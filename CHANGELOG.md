# Changelog

All notable changes to the Grav AI Chatbot plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

### Added
- **Environment-var API key override**: when `api_key` is blank, the plugin uses `AI_CHATBOT_API_KEY` from `.env`, allowing the secret to stay out of version control.
- **3-Step Interactive Model Setup Wizard** (`<chatbot-model-tools>`):
  - Step 1: `🔑 Test API Key` (Live verification against provider API).
  - Step 2: `🔄 Retrieve Active Models` (Queries provider for available model IDs).
  - Step 3: `⚡ Test Model Health` & `✨ Use This Model` (Live latency health check ping & single-input model field application).
  - Prominent **`✍️ Switch to Unlisted / Custom Model Input`** toggle button styling with blue accent highlight.
- **Alphabetical Sorting**:
  - `AI Provider Engine` options in `blueprints.yaml` sorted alphabetically by provider label.
  - Active provider model IDs in Step 3 sorted alphabetically using `localeCompare()`.
- **3-Level Live Provider Detector**:
  - Detects unsaved dropdown selections (`⚡ Groq Cloud`, `🌐 OpenRouter`, `🤖 OpenAI`, `♊ Google Gemini`, `🦙 Ollama`, `🔀 OmniRoute Gateway`) in real-time.
- **5-Layer Security Suite & Real-Time Audit Dashboard** (`<chatbot-security-logs>`):
  - Automatic normalization of leetspeak & zero-width space obfuscation.
  - Threat detection blocking XSS, SQLi, Prompt Injections, and credential probing.
  - Anonymized IP hash lockout shield (`429 Security Cool-Off`).
- **Retrieval-Augmented Generation (RAG) Vector Search**:
  - SQLite vector store (`rag_index.sqlite`) with cosine similarity.
  - Multi-driver embeddings (Ollama `nomic-embed-text`, Gemini `text-embedding-004`, OpenAI `text-embedding-3-small`, TF-IDF/BM25).
  - Grav CMS Scheduler background cron integration (`ai-chatbot-rag-reindex`).
- **Grav 2.0 REST API** `/api/v1/ai-chatbot/*` (`ChatbotApiController`): public `query` and `summarize` (registered through `onApiCollectPublicRoutes`); admin `metrics`, `logs`, `security`, `test-key`, `models`, `health`, `reindex`, `unlock`, `clear-logs`, `export` (`api.system.read|write`).
- **`<chatbot-rag-tools>`** Admin 2 field with Twig fallback.

### Changed
- **Lean revision**: Classic Admin analytics scripts and the duplicate `onTwigSiteVariables` widget injection path were removed; Admin 2 Web Components and the single `onOutputGenerated` injection path are canonical.
- **Client identity**: `RateLimiter::getClientIp()` is the shared resolver for rate limiting, lockout identity, and telemetry. Proxy headers are trusted only from loopback and RFC1918 peers.
- **Log writes**: Interaction logs use `flock(LOCK_EX)` for read-modify-write and append operations.
- **Data paths**: `DataPath` resolves `user/data/ai-chatbot` through `user://data`, with a `GRAV_ROOT` fallback and no `/var/www/html` hard-coding.
- **Embeddings**: `tfidf_local` is the default. `openrouter` is no longer an embedding provider, and OpenAI embedding failures are logged instead of silently falling back to TF-IDF.
- **Gemini authentication**: API keys are sent with the `x-goog-api-key` header instead of URL query parameters.
- **Provider surface**: `gemini`, `groq`, `openai`, `openrouter`, `ollama`, and `omniroute` are the only accepted providers; the `custom` and `openai_compatible` aliases were removed, and the shipped default is `gemini`. Ollama and OmniRoute require a valid `http` or `https` endpoint.
- **Frontend Widget REST Migration**: Frontend widget uses `/api/v1/ai-chatbot/{query,summarize}` and falls back to the legacy `/chatbot-api` endpoint.
- **Legacy API Deprecation**: Legacy `/chatbot-api` is deprecated: it serves visitor actions only (admin actions return HTTP 403 and must use the REST API). It now emits RFC 8594 `Deprecation`, `Sunset`, `Warning: 299`, and successor `Link` headers. Planned removal in 3.0.0.
- **Admin Translations**: Removed the Spanish (`es`) admin translation block; added an Indonesian (`id`) block mirroring the English keys.
- **OmniRoute Gateway**: The custom OpenAI-compatible provider is exposed as **`omniroute`** with the `🔀 OmniRoute Gateway (OpenAI-Compatible)` label.
- **Non-Ollama Payload Sanitization**: Omitted Ollama-specific `options` payload parameters (`num_ctx`, `num_predict`) when sending requests to standard OpenAI endpoints (Groq, OpenRouter, OpenAI).

### Removed
- **Dead configuration keys**: `strict_site_scope`, `logging_enabled`, `log_api_usage`, `fallback_endpoint`, `export_require_auth`, and `export_allowed_users` were removed from defaults, blueprints, translations, and documentation.
- **Dead actions**: `analytics_report`, `clear_analytics`, `generate_demo_data`, `test_connection`, `release_ip_lockouts`, and `clear_security_logs`. The legacy shim now serves only `query`, `force_ai`, and `summarize_page`; admin operations use the REST API.
- **Classic Admin surface**: `assets/js/admin-analytics.js`, `assets/js/admin-model-tools.js`, `assets/css/admin-analytics.css`, and `templates/admin/analytics.html.twig`.
- **Client-side key exposure paths**: the Gemini key is no longer appended to URLs.
- **Stale assets and notes**: `assets/css/test-marker.css` and the historical `.task/` notes.

### Security
- **Strict API Key Leakage Protection**: Removed debug log statement that dumped live config containing raw API keys to `error.log`.
- **Svelte 5 Shadow DOM Single-Element Targeting**: Scoped `Model Identifier` field setter strictly to single inputs with negative filtering, preventing field overwrite collisions.

## [1.1.0] - 2026-07-28

### Added
- Multilingual FAQ Pre-Matching (`FaqResolver.php`) supporting localized pages (e.g. `default.es.md`, `/es/faq`).
- UI Theme Presets (`glass_blue`, `emerald_dark`, `purple_haze`, `sunset_orange`, `custom`).
- Proactive Visitor Notification Toast bubble with configurable text and delay timer.
- Multi-Day Chat Session Retention across pages, tabs, and page refreshes (`session_retention_days`).
- Added sample Spanish FAQ page (`06.faq/default.es.md`).

## [1.0.0] - 2026-07-28

### Added
- Initial release of Grav AI Chatbot plugin.
- Google Gemini API REST v1beta driver.
- OpenAI and OpenRouter Chat Completions REST API drivers.
- Local FAQ pre-matching engine (`FaqResolver.php`) to bypass AI API key usage.
- Multi-tier contact resolution (`ContactPageResolver.php`) supporting public `/contact` and hidden `/hidden-contacts` pages.
- IP-based rate limiter using Grav Cache (`RateLimiter.php`).
- Interaction logging, token cost tracking, and candidate FAQ recommendation engine (`Logger.php`, `FaqRecommender.php`).
- Admin Analytics Dashboard with visual graphs and CSV/JSON export capability (`AnalyticsReportGenerator.php`).
- Floating glassmorphic chatbot widget Twig partial, CSS, and Vanilla JS client.
- GPLv3 License specification.
