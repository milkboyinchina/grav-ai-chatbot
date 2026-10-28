# ai-chatbot Plugin Code Audit

- Date: 2026-10-23
- Audited version: 2.0.0 (`blueprints.yaml`), branch `dev`, HEAD `9fd5a6d`
- Scope: all PHP classes (5,192 LOC), REST controller, legacy shim, Admin2 Web Component fields, Classic Admin assets, widget JS, Twig templates, YAML config/blueprints/languages, CLI command, CI workflow
- Method: full source read, cross-check of action registry / config key touchpoints / architecture invariants, lint verification in the container
- Known debt: items already recorded in `.agents/skills/ai-chatbot-dev/references/known-debt.md` (D1 to D18) are cross-referenced, not re-counted. All findings below are new unless stated otherwise.

## 1. Automated verification results

| Check | Result |
|---|---|
| `php -l` on all 20 PHP files | Pass, no syntax errors |
| `yaml-lint` on `ai-chatbot.yaml`, `blueprints.yaml`, `languages.yaml` | Pass |
| `node --check` on all 9 JS files (`chatbot.js`, 5 Admin2 fields, 3 Classic Admin scripts) | Pass |
| Git working tree before audit | Clean |

## 2. Executive summary

The plugin is functionally coherent: the request pipeline (rate limit, guardrail, FAQ, contact resolver, RAG, LLM) is well layered, admin actions are correctly gated away from the legacy shim, the REST controller consistently calls `requirePermission()` first, and the guardrail avoids persisting raw visitor input in error logs. However, the audit found:

- 2 security findings that deserve prompt attention (proxy trust range, inline JSON injection hardening)
- 6 functional defects (date-range filter not implemented, Clear Logs button performs the wrong action, dead reasoning fallback in the OpenAI client, broken `openrouter` embedding path, default-provider routing trap, silent Gemini model substitution)
- 3 configuration/integration gaps (dead config keys, missing `api` plugin dependency declaration, blueprint fields populated but never defined)
- Several lower-severity correctness, performance, and maintainability issues

## 3. Security findings

### SEC-1 (High): trusted proxy range is wider than RFC1918, and rate-limit identity differs from lockout identity

- Location: `classes/RateLimiter.php` L116-126 (`isTrustedProxy`), L93-114 (`getClientIp`); `classes/ChatbotHandler.php` L199
- `isTrustedProxy()` accepts any `str_starts_with($ip, '172.')`, i.e. all of 172.0.0.0/8. Only 172.16.0.0/12 is private; 172.217.x.x and other public ranges are ordinary internet hosts. A visitor from such an address is treated as a trusted proxy, so their `X-Forwarded-For` / `Client-IP` header becomes their rate-limit identity (`ai_chatbot_rate_<md5(xff)>`). By rotating a spoofed header they never hit `rate_limit_max_requests`.
- Additionally, the guardrail lockout hashes raw `$_SERVER['REMOTE_ADDR']` (ChatbotHandler L199) while the rate limiter uses the header-honoring `getClientIp()`. Two different identity notions means lockouts and rate limits can target different IPs.
- Fix: restrict the 172 check to `172.16.` through `172.31.` (explicit prefix list), and use one shared IP-resolution helper for both rate limiting and lockouts.

### SEC-2 (High): inline `GravChatbotConfig` injection without `JSON_HEX_*` flags

- Location: `ai-chatbot.php` L511-542 (`onOutputGenerated`) and L779-829 (`onTwigSiteVariables`)
- Both hooks embed `json_encode([...])` directly into `<script>window.GravChatbotConfig = ...</script>`. `json_encode` does not escape `/`. An admin-editable string (`bot_title`, `welcome_message`, `notification_text`, quick reply labels) containing `</script><script>...` breaks out of the script context and executes. This is a stored-XSS vector that requires admin access or a compromised admin session, but it violates the "config values never reach HTML unescaped" hardening posture used elsewhere (the Twig partial escapes via autoescape).
- Also: `json_encode` returns `false` on invalid UTF-8, which would silently inject an empty config object.
- Fix: `json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)` (or `JSON_UNESCAPED_SLASHES` is NOT wanted here; the hex flags are).

### SEC-3 (Low): export 403 message discloses whitelisted usernames

- Location: `ai-chatbot.php` L712 (`handleAnalyticsExport`)
- The rejection message includes `implode(', ', $allowedUsers)`, telling unauthenticated visitors which admin usernames exist. Combined with D12 (usernames hard-coded in defaults) this is a small information leak. Fix: return a generic message; do not enumerate the whitelist.

### SEC-4 (Advisory): `export_require_auth: false` grants anonymous telemetry export

- Location: `ai-chatbot.php` L658-716; default `true` in `ai-chatbot.yaml`
- When disabled, any visitor downloads full interaction logs (questions, answers, `ip_hash`) via `/chatbot-export`. This is a documented option, but `SECURITY.md` should state the risk explicitly. The canonical REST export endpoint is always permission-enforced; only the legacy shim honors the toggle.

## 4. Functional defects

### FUN-1 (High): analytics date-range filter is advertised but not implemented

- Location: `classes/ChatbotHandler.php` L37-45 (`analytics_report` passes `$range` to `getDashboardAnalyticsData($range)`); `classes/AnalyticsReportGenerator.php` L59 (method signature accepts no parameters); `templates/admin/analytics.html.twig` (range select, `&range=all` links); `assets/js/admin-analytics.js` (range dropdown)
- PHP user-defined functions silently ignore extra arguments, so the call does not crash, but `range` is discarded everywhere: dashboard, `exportReport`, and CSV/JSON/raw exports always cover all time. The Classic Admin UI offers 7d/1m/3m/6m/12m/all and displays "successfully updated" after any selection, so admins believe filtering works.
- Fix: add a `string $range = 'all'` parameter to `getDashboardAnalyticsData()` and filter logs by timestamp cutoff; thread it through exports, or remove the UI control.

### FUN-2 (High): security-logs "Clear Logs" button performs "release lockouts", and no clear-logs REST endpoint exists

- Location: `admin-next/fields/chatbot-security-logs.js` L59-79 (`_handleAction`), L66 (identical-branch ternary, D16), L311; `ai-chatbot.php` L114-122 (route list has no clear-logs route)
- `_handleAction('clear_security_logs')` and `_handleAction('release_ip_lockouts')` both POST to `/api/v1/ai-chatbot/unlock`. The "Clear Logs" button therefore releases IP lockouts and refreshes; it never clears anything, and no REST route for clearing exists. The handler-side `clear_security_logs` action (ChatbotHandler L182-191) is reachable only via the legacy shim, where it is blocked as admin-only (403).
- Related semantic mismatch: the handler `clear_security_logs` action releases lockouts, wipes `interactions.json` via `Logger::clearLogs()`, leaves `error.log` (where guardrail audit entries actually live) untouched, and reports "Security audit logs cleared". Action name, behavior, and message disagree.
- Fix: add a dedicated `POST /ai-chatbot/clear-logs` route (or split unlock/clear), wire the button to it, and make the handler clear `error.log` plus violation records instead of interaction telemetry.

### FUN-3 (High): dead reasoning fallback and fabricated `'OK'` success in OpenAI-compatible client

- Location: `classes/OpenAiCompatibleClient.php` L201-216, L222
- Sequence: if sanitized content is empty, `$answer` is set to `'OK'` (L204-206). The subsequent `if (empty($answer) && !empty($rawReasoning))` block (L208-216) is therefore unreachable dead code. Worse, an empty model response is reported as `success => true` with answer `'OK'`, which the handler logs as a normal AI answer. A provider returning empty content is masked as a successful "OK" reply to visitors.
- Fix: move the reasoning fallback before the `'OK'` placeholder, and only fall back to `'OK'` (if that behavior is even desired for ping/health calls) when the caller explicitly opts in.

### FUN-4 (Medium): `rag_embedding_provider: openrouter` is silently broken

- Location: `classes/Rag/EmbeddingProvider.php` L37-50 (constructor sets `$this->endpoint` only for ollama/gemini/openai), L66-77 (`openrouter` dispatches to `generateOpenAiEmbedding`)
- For `openrouter`, `$this->endpoint` stays `''`, so the request URL becomes the relative string `/embeddings`. cURL fails on it, `curl_exec` returns false, and every embedding silently degrades to local TF-IDF. Indexing "succeeds" with wrong vectors; no error is logged.
- Fix: set an OpenAI-compatible endpoint for `openrouter` (or reuse `https://openrouter.ai/api/v1`) and log embedding failures instead of silently falling back.

### FUN-5 (Medium): default provider config routes chat traffic to api.openai.com with the wrong key

- Location: `ai-chatbot.yaml` L4-L7 (`provider: omniroute`, `custom_endpoint: ''`); `classes/AiClientFactory.php` L84-95; `classes/OpenAiCompatibleClient.php` L30-36
- With provider `omniroute` and an empty custom endpoint (the shipped default), the factory passes the empty endpoint, and the client constructor falls back to `https://api.openai.com/v1/chat/completions`. Chat queries then send the omniroute API key to OpenAI and fail with auth errors. `testApiKey()` and `fetchModels()` correctly refuse endpoint-less omniroute/ollama/custom, but the actual chat path (`queryAiModel`) performs no such validation.
- Fix: validate `custom_endpoint` presence for endpoint-requiring providers at query time, or default the provider to one with a built-in endpoint.

### FUN-6 (Medium): Gemini client silently substitutes the selected model

- Location: `classes/GeminiClient.php` L37-40
- `gemini-1.5-flash`, `gemini-1.5-flash-latest`, and `gemini-1.5-pro` are force-remapped to `gemini-2.0-flash` with no log or response hint. An admin selecting a pro-tier model gets flash behavior silently. If legacy 1.5 IDs must be retired, reject them in validation or log the substitution once.

## 5. Configuration and integration gaps

### CFG-1 (Medium): REST API hard-depends on the `api` plugin, which is not declared as a dependency

- Location: `classes/Controllers/ChatbotApiController.php` L10-11 (`AbstractApiController`, `ApiResponse`); `blueprints.yaml` L16-17 (dependencies list only `grav >=2.0.0`)
- If the `api` plugin is absent, `onApiRegisterRoutes` never fires, all Admin2 field fetches (`/api/v1/...`), the REST surface, and `onApiBlueprintResolved` are silently unavailable, and the widget silently degrades to the deprecated shim. Any code path that instantiates the controller class without the API plugin loaded fails fatally.
- Fix: add `- { name: api }` to `dependencies` so GPM enforces installation, and document the requirement in README/MANUAL.

### CFG-2 (Medium): dead config keys imply control that does not exist

- Location: `blueprints.yaml` L498-507 (`strict_site_scope`), L565-585 (`logging_enabled`, `log_api_usage`); `languages.yaml` L43, L48-49, L114, L119-120
- None of these keys is read by any PHP code (verified by grep across the plugin). `Logger::logInteraction()` is unconditional, so "Enable Interaction Logging: Disabled" does not stop logging. `strict_site_scope` has no effect; the scope probe is always active when the guardrail is on.
- Fix: implement the toggles or remove the fields and translations. Per the config touchpoint rule, every blueprint field must map to a consumed key.

### CFG-3 (Medium): `onBlueprintCreated` populates fields that do not exist in the blueprint

- Location: `ai-chatbot.php` L248-272; `blueprints.yaml` (no `analytics_summary_text`, `analytics_chart_display`, or `ai_chatbot_error_log_display` fields exist)
- The hook sets defaults for three fields that were removed from `blueprints.yaml` when the `chatbot-metrics` / `chatbot-live-logs` Web Components replaced them, and additionally writes them under a `form.fields.tabs.fields...` path that does not match the actual blueprint structure (fields are top level with nested sections). All those `set()` calls are dead. `onApiBlueprintResolved` (L358-378) contains the same dead keys inside the tree mutator.
- The Classic Admin script `admin-analytics.js` still hunts for those field names in the DOM (L54-78) and cannot find them. Compounds D13.
- Fix: delete the dead branches and keys, or reintroduce the fields if Classic Admin parity is still a goal.

### CFG-4 (Low): provider vocabulary mismatch between sanitiser, factory, and blueprint

- Location: `classes/ChatbotHandler.php` L445-450 (`sanitizeProvider` allows `gemini_openai`, `openai_compatible`, `custom`); `classes/AiClientFactory.php` L30-109 (supports `custom`, `openai_compatible`); `blueprints.yaml` L65-76 (select offers only 6 options; no `custom` or `openai_compatible`)
- `show_on: "provider: omniroute,ollama,custom"` (L103, L113) references a provider value (`custom`) that cannot be selected in the form. Saved configs carrying legacy provider values are accepted by the sanitiser but unreachable from the UI.
- Fix: align the three lists (blueprint options, sanitiser whitelist, factory cases) and update the action registry if any value is renamed.

### CFG-5 (Low): Admin2 fields hard-code `/api/v1` and ignore the configurable API base

- Location: `admin-next/fields/chatbot-metrics.js` L84, `chatbot-live-logs.js` L79, `chatbot-rag-tools.js` L45, `chatbot-security-logs.js` L41 and L66, `chatbot-model-tools.js` L231, L269, L317
- The PHP side computes the real base via `getRestBase()` (respecting `plugins.api.route` and `version_prefix`) and passes `restBase` to the public widget, but all Admin2 components hard-code the `/api/v1` prefix. Sites that relocate the API route get broken admin fields.
- Fix: expose the computed base to the fields (e.g. via a data attribute or `window.__GRAV_API_BASE`) and use it in all fetch calls.

## 6. Correctness and robustness findings

### COR-1 (Medium): public widget injection path also runs on admin pages (needs verification)

- Location: `ai-chatbot.php` L421-429 (admin branch enables `onTwigSiteVariables`), L735-830 (method has no `isAdmin()` guard, unlike `onOutputGenerated`)
- In Classic Admin, `onTwigSiteVariables` enqueues `chatbot.css`, `chatbot.js`, the config inline script, and the widget DOM injection on admin pages. The visibility rules (`display_mode`) default to `all`, so the floating widget is expected to render inside the admin panel. `onAdminTwigSiteVariables` exists separately for admin analytics assets, indicating the public widget on admin pages is unintended.
- Fix: add `if ($this->isAdmin()) { return; }` at the top of `onTwigSiteVariables`, or gate the widget-injection half. Verify against the live Admin2 UI before/after.

### COR-2 (Medium): legacy shim always returns HTTP 200 and leaks the internal `http_code` field

- Location: `ai-chatbot.php` L627-629 (`handleChatbotQueryApi`)
- The handler returns `http_code` (429 rate limit, 500 provider failure, 400 validation), but the shim emits `http_response_code(200)` unconditionally and serialises the whole response array, so the status code is only visible as a stray body field. The REST controller correctly maps it. Fix: honor `$response['http_code']` and `unset()` it before echoing.

### COR-3 (Medium): `interactions.json` is read-modify-write without locking, and history is capped at 500

- Location: `classes/Logger.php` L25-73 (`logInteraction`), L67-70
- Concurrent requests (two visitors, or a visitor plus the 15-second metrics poller) can each read, append, and overwrite, losing records. The 500-record cap then silently discards analytics history with no warning surfaced in the admin dashboard.
- Fix: use `flock()` around the read/write, or append-oriented storage; consider making the cap configurable and surfacing "showing latest 500 of N".

### COR-4 (Low): `ContextIndexer` signatures are misleading

- Location: `classes/ContextIndexer.php` L30-33 (`buildContextPrompt` ignores `$currentRoute` entirely), L155-164 (`searchContext` ignores `$question` and returns the first N chunks of the whole-site context)
- Callers believe they get route-aware or relevance-ranked context. `handleSummarizePage` also falls back to the first two pages site-wide when the route is not found (ChatbotHandler L815), which can summarise the wrong pages. `searchContext` appears to have no callers and should be removed or implemented.

### COR-5 (Low): public response leaks internal config state

- Location: `classes/ChatbotHandler.php` L834 (`'debug_raw_ai_enabled' => $this->config['ai_enabled'] ?? 'MISSING'` returned to visitors when AI is disabled and a summary is requested)
- Debug leftovers should not be exposed on a public endpoint. Remove the field.

### COR-6 (Low): token fallback defaults fabricate usage numbers

- Location: `classes/ChatbotHandler.php` L970-971 (`$res['prompt_tokens'] ?? 450`, `?? 120`)
- When a client response lacks token counts, 450/120 are invented and feed cost estimates. Both current clients always return the keys, so the path is mostly dormant, but any future client omission silently inflates analytics. Use 0 as the default and log the absence.

### COR-7 (Low): guardrail defaults produce false positives on a developer site

- Location: `classes/SecurityGuardrail.php` L28-97 (patterns `fetch\(`, `eval\(`, `exec\(`, `.env`, `api_key`, `hack`, `bypass`, `system prompt`), L188-211 (`isSystemPathProbe` includes `api_key`); `ai-chatbot.yaml` L37 mirrors them
- On this personal CV/dev site, ordinary questions ("how do I use fetch() in JavaScript", "what is an api_key") are blocked with a safety warning and count toward IP lockout. `ContactPageResolver` L43 uses substring regexes without word boundaries (`reach` matches "breach", "preach"). Consider word-boundary patterns and trimming the default list; document the trade-off in MANUAL.md.

### COR-8 (Low): data-path fallbacks are environment-specific and inconsistent

- Location: `classes/Logger.php` L193, L200, L207 (`/var/www/html/user/data` fallback), `classes/Rag/VectorStore.php` L23, `classes/Rag/Indexer.php` L30 (same), `classes/SecurityGuardrail.php` L22 (when `GRAV_ROOT` is undefined the path degrades to the absolute path `/user/data/...`)
- These violate the architecture invariant "no environment-specific paths in plugin code" (the IP-literal analogue is D8) and the guardrail uses a third, different resolution strategy than Logger. Unify on the locator with a single shared helper; fail loudly instead of guessing paths.

### COR-9 (Low): widget quick-reply `contact` action works by accident

- Location: `ai-chatbot.yaml` L66-68 (`type: action`, `action_or_prompt: contact`); `classes/ChatbotHandler.php` (no registered `contact` action); `assets/js/chatbot.js` L59-65
- The pill sends `action: 'contact'` with the button label as the question. There is no `contact` action; resolution succeeds only because the default label "Contact Owner" happens to contain the keyword that triggers `ContactPageResolver`. A relabelled button silently degrades to a generic AI call. The REST controller normalises unknown actions to `query`, masking the issue further. Register a real `contact` action or treat `action_or_prompt` as a question consistently.
- Also: the server-rendered Twig pills (`templates/partials/chatbot-widget.html.twig` L47-53, `data-action`/`data-prompt`) have no click handlers anywhere; `chatbot.js` clears and re-renders the container. Without JS the markup is inert. Either wire the handlers for progressive enhancement or render the pills only client side.

### COR-10 (Low): dead compatibility branches and parameter shadowing

- Location: `classes/FaqResolver.php` L32-35 (`is_string($config)` is unreachable; parameter is typed `array`); `classes/ContactPageResolver.php` L23-31 (same legacy shim via untyped parameter); `classes/ChatbotHandler.php` L165 (`$data = SecurityGuardrail::getSecurityAuditData(...)` overwrites the request payload variable)
- Cosmetic today, refactor hazards tomorrow. Remove the dead branches; rename the security-data variable.

## 7. Performance and scalability

### PERF-1 (Low): vector search is a full-table scan with JSON decode per query

- Location: `classes/Rag/VectorStore.php` L137-185
- `searchSimilar` loads every row, `json_decode`s every embedding blob, and computes cosine similarity in PHP per visitor query. Acceptable at current site size; a scaling ceiling. The mixed 0.6 cosine + 0.4 keyword blend also means provider switches (different vector dims) silently reduce retrieval to keyword-only matching (dimension check at L157). Consider storing the dim alongside the blob and rejecting the whole index on mismatch (prompt for `make index-rag-rebuild`).

### PERF-2 (Low): admin metrics polling re-reads and re-clusters all logs every 15 seconds

- Location: `admin-next/fields/chatbot-metrics.js` L29; `classes/AnalyticsReportGenerator.php` L59-132 (`getDashboardAnalyticsData` re-reads `interactions.json` and runs `FaqRecommender::getRecommendations` clustering on every call)
- Each poll does file I/O plus O(n x clusters) similarity work. Cache the aggregate for a short TTL or skip recommendations when only the summary cards are requested.

### PERF-3 (Low): `onAdminTwigSiteVariables` loads Classic Admin analytics CSS/JS on every admin page

- Location: `ai-chatbot.php` L838-844
- The CSS and two scripts are enqueued unconditionally for the whole admin, not just the plugin's own page. Gate on the current admin page (as Admin2 does for its field bundles).

## 8. Release and process gaps

### REL-1 (Medium): release sync set is incomplete for 2.0.0

- `blueprints.yaml` `version: 2.0.0`, but `CHANGELOG.md` still heads the section `## [2.0.0] - Unreleased` and the latest git tag is `v1.1.0` (no `v2.0.0`). Per the release rule the sync set is blueprint version + CHANGELOG entry + tag. When the release is made: date the CHANGELOG entry, tag `v2.0.0` so `.github/workflows/release.yml` builds the zip.

### REL-2 (Low): unresolved known-debt housekeeping in the shipped tree

- `assets/css/test-marker.css` (D18) is still present and packaged by the release workflow (no exclude rule for it). `.task/` historical notes (D17) are excluded from the zip but still describe nonexistent symbols. Confirm and remove `test-marker.css` if unused.

## 9. Known-debt ledger cross-check (D1 to D18)

All 18 items remain present at the audited HEAD. Locations re-verified:

| ID | Status | Note |
|---|---|---|
| D1 silent `catch (\Throwable) {}` | Present | Including two new-to-audit sites in `ChatbotApiController::handler()` (L38) and the RAG path; consistent with the count in the ledger |
| D2 `ChatbotHandler` size / string dispatch | Present | 1,101 lines |
| D3 page-visibility duplication | Present | `ai-chatbot.php` ~L467-500 and ~L748-773 |
| D4 analytics builder duplication | Present | `onBlueprintCreated` vs `onApiBlueprintResolved`; CFG-3 shows both also target dead fields |
| D5 `GravChatbotConfig` duplication | Present | L511-530 and L779-798; both sites need the SEC-2 fix |
| D6 admin action lists in three places | Present | `$adminOnly`, `$nonQuestionActions`, controller; FUN-2 adds a fourth list (JS action names) |
| D7 `strict_types` missing | Present | Only `ChatbotApiController.php` declares it (grep-verified) |
| D8 hard-coded IP literals | Present | `OpenAiCompatibleClient.php` L85, L120-123; `AiClientFactory.php` L65-69 and `EmbeddingProvider.php` L39-42 also bake `host.docker.internal` into plugin code |
| D9 endpoint scheme validation | Present | `testApiKey`/`fetchModels`/`testModelHealth` accept `custom_endpoint` without an `http(s)://` check |
| D10 Gemini key in URL query | Present | `GeminiClient.php` L73, `EmbeddingProvider.php` L123, `ChatbotHandler.php` L485 and L619 |
| D11 `Access-Control-Allow-Origin: *` shim preflight | Present | `ai-chatbot.php` L590 |
| D12 hard-coded export usernames | Present | `ai-chatbot.yaml` L41; interacts with SEC-3 |
| D13 Classic Admin admin actions blocked by shim | Present | `admin-analytics.js` L136, L167, L240 |
| D14 `routeCheck()` substring match | Present | `ai-chatbot.php` L566-571; any page URL or query string containing `chatbot-api`/`chatbot-export` is hijacked and `exit()`ed |
| D15 model-tools DOM scraping | Present | `chatbot-model-tools.js` (756 lines) |
| D16 redundant ternary | Present | `chatbot-security-logs.js` L66; see FUN-2, the ternary also hides the wrong-endpoint bug |
| D17 stale `.task` notes | Present | |
| D18 `test-marker.css` | Present | Contains only `/* src */`; referenced by nothing |

## 10. Positive observations

- REST controller hygiene: every admin method begins with `requirePermission()`; public routes are limited to `query` and `summarize` via an explicit allowlist (`onApiCollectPublicRoutes`), and the controller normalises the visitor-supplied `action` to a fixed set, so visitors cannot invoke arbitrary handler branches.
- The legacy shim blocks admin actions with a clear 403 and deprecation headers (Sunset, successor-version Link), which is the correct BC posture for planned 3.0.0 removal.
- Guardrail logging is PII-conscious: raw visitor input is kept out of `error.log`; IP hashes are truncated.
- `applyEnvironmentOverrides()` keeps `api_key` out of version control while remaining server-side only; no grep hit places the key in client JS, DOM, or responses.
- Indexing uses a lock file with a 10-minute expiry to prevent concurrent rebuilds; `onPageSaved`/`onPageDeleted` keep the vector index incremental.
- Rate limiting fails closed on window logic and the widget JS escapes HTML before its Markdown mini-formatter (link hrefs restricted to `http(s)` and root-relative).

## 11. Prioritised recommendations

1. Fix SEC-1 (proxy range + unified IP identity) and SEC-2 (`JSON_HEX_*` flags). Small diffs, security-relevant.
2. Fix FUN-2 (add clear-logs route, wire the button, align handler semantics) and FUN-3 (dead fallback + `'OK'` fabrication). Both are user-visible misbehavior.
3. Implement or remove the date-range filter (FUN-1) and the dead config keys (CFG-2); both currently mislead admins.
4. Declare the `api` dependency (CFG-1) and repair the `openrouter` embedding path (FUN-4) and the omniroute default trap (FUN-5).
5. Harden the data-path fallbacks (COR-8) and the shim status codes (COR-2).
6. Schedule debt cleanup D1/D5/D6 (they all touch the same pipeline), and drop `test-marker.css` during the next release pass (REL-1/REL-2).

## 12. Audit limitations

- No runtime execution was performed against the live site (no authenticated admin API calls, no Admin2 UI walk-through). COR-1 (widget on admin pages) and CFG-5 (custom API base) should be verified in the browser before fixing.
- The `api` and `admin2` plugins were treated as read-only references; their event contracts (`onApiBlueprintResolved`, field bundling) were assumed from usage, not verified against their source.
- `.task/` historical notes were excluded from findings except where they conflict with current code (D17).
