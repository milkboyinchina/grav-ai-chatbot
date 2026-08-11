# Grav CMS AI Chatbot Plugin (`grav-plugin-ai-chatbot`)

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Grav CMS](https://img.shields.io/badge/Grav-1.7%2B-orange.svg)](https://getgrav.org)
[![Version](https://img.shields.io/badge/Version-v1.5.0-green.svg)](https://github.com/milkboyinchina/grav-ai-chatbot)

An intelligent, enterprise-ready **Grav CMS AI Chatbot Plugin** supporting Retrieval-Augmented Generation (RAG) vector search, multi-engine AI inference (**Groq Ultra-Fast Llama 3**, **Google Gemini**, **OpenRouter**, **OpenAI**, or **Custom Endpoints**), local semantic FAQ pre-matching with alias normalization, customizable AI disabled response messages, multi-tier contact resolution, customizable quick replies, date range analytics filtering, and visual telemetry dashboards.

## 🌐 Live Demo & Hardware Benchmark Test

> [!TIP]
> **Experience the Live Plugin in Action**:  
> Test the live floating chatbot widget directly on **[https://www.milkboy.my.id](https://www.milkboy.my.id)**.
> Not using CDN, connection may be slow if you are not in SEA region. 

> [!WARNING]
> **Proof of Extreme Token Efficiency & Context Window Optimization**:  
> The live demo backend on `milkboy.my.id` is powered by a custom Ollama endpoint running on a **remote janky old Intel Celeron N4100 low-power mini PC** serving custom `Qwen2.5-0.8B`! Running at snail's Average 3.59 tokens/sec.
> Because the RAG engine trims system prompt context down to 300–500 tokens, even ultra-low-spec hardware can process site queries in "real™" time. Please expect slight hardware-bound processing latency when testing complex queries (*your mileage may vary!*).
> Don't judge me, I'm not made of money.
---

- **[User & Administrator Manual (`MANUAL.md`)](MANUAL.md)**: Complete guide on setup, RAG engine, Grav Scheduler crontab configuration, CLI commands, and troubleshooting.
- **[Developer & API Guide (`DEVELOPER.md`)](DEVELOPER.md)**: Complete technical specification covering REST API endpoints, Admin 2 Svelte 5 Web Component field contracts, and security architecture.
- **[How It Works Summary (`HOWITWORKS.md`)](HOWITWORKS.md)**: Technical overview of the 5-tier resolution pipeline, RAG ingestion, and execution flow.
- **[RAG Technical Plan & Benchmarks (`BENCHMARK-RAG.md`)](BENCHMARK-RAG.md)**: Token consumption matrices, latency benchmarks, and cost reduction analysis.

---

## 🛡️ Security Guardrails & Defense Architecture

The plugin includes a multi-layered security hardening suite protecting against **Prompt Injections**, **Jailbreaks**, **XSS Script Injections**, **SQL Injection**, **Credential Stuffing**, and **Data Leaks**:

- 🔒 **Strict Read-Only Scope Boundary (`user/pages/` & RAG Only)**: Restricts chatbot knowledge strictly to public pages in `user/pages/` and its RAG vector embeddings (`user/data/ai-chatbot/rag_index.json`). Chatbot has zero access to server files, credentials, or `user/config/`.
- 🔤 **Leetspeak & Unicode Normalization**: Automatically normalizes obfuscation tricks (e.g. `h4ck` $\rightarrow$ `hack`, `byp4ss` $\rightarrow$ `bypass`, `@dmin` $\rightarrow$ `admin`, zero-width spaces) prior to pattern inspection.
- 🛡️ **5-Category Defense Matrix**: Automatically blocks Prompt Injections, Jailbreaks, XSS vectors (`<script`, `onerror=`), SQL Injection (`union select`, `drop table`), Credential Probes (`.env`, `user/config`), and System Commands (`cat /etc/passwd`).
- ⏱️ **Server-Side Token Flooding Defense**: Enforces strict server-side input truncation (**Max 500 characters / ~125 tokens**) before invoking LLM API providers.
- 🔑 **Credential Stuffing Cool-Off Protection**: Tracks security guardrail violations by anonymized IP hash. 5 violations within 60s trigger a **15-minute temporary IP lockout** (`429 Security Cool-Off`).
- 📊 **Real-Time Admin 2 Security Audit Dashboard (`<chatbot-security-logs>`)**: Renders real-time threat meter, live audit violation logs, and admin lockout release controls in Grav Admin 2.

---

## 🌟 Core Features

- 🧠 **Retrieval-Augmented Generation (RAG) Engine**:
  - **Heading-Aware Page Chunking**: Automatically parses and splits published Grav pages along H1–H3 section boundaries.
  - **SQLite Vector Database**: Local `rag_index.sqlite` storage supporting cosine similarity and term overlap searches.
  - **Multi-Driver Embedding**: Supports Ollama (`nomic-embed-text`), Gemini (`text-embedding-004`), OpenAI (`text-embedding-3-small`), and zero-token local TF-IDF/BM25.
  - **Grav Scheduler & Auto-Indexing**: Incremental SHA-256 hash checks and automated background cron re-indexing via Grav CMS Scheduler.
  - 📉 **80%+ Token & Cost Reduction**: Drastically reduces LLM prompt size and cuts API costs. See [`BENCHMARK-RAG.md`](BENCHMARK-RAG.md) for full benchmarks.

- ⏰ **Automated Grav Scheduler Background Cron**:
  - Registers job `ai-chatbot-rag-reindex` automatically with Grav CMS Scheduler.
  - *Note*: Requires standard system crontab setup (`* * * * * cd /path/to/grav && php bin/grav scheduler`). See [`MANUAL.md`](MANUAL.md#2-grav-scheduler--automated-cron-setup) for details.

- ⚡ **Multi-Provider AI Engines & Dual-Endpoint Failover**:
  - **Groq API** (`llama-3.3-70b-versatile`, `llama-3.1-8b-instant`) — Ultra-fast sub-second LLM inference.
  - **Google Gemini API** (Default: `gemini-2.0-flash`, `gemini-2.0-flash-lite`, `gemini-2.0-flash-001`, `gemini-2.0-flash-lite-001`).
    *(Disclaimer: Not sponsored by Google — please Google, donate some tokens! 😅)*
  - **OpenRouter API** (`google/gemini-flash-1.5`, `anthropic/claude-3.5-sonnet`).
  - **OpenAI API** (`gpt-4o-mini`, `gpt-4o`).
  - **Custom Endpoints & Configurable Failover** (Local Ollama, Tailscale VPN hosts, vLLM, or self-hosted OpenAI-compatible servers with **automatic primary & secondary endpoint failover**).

- 🛡️ **Configurable AI Disabled Reply & Offline Fallback**:
  - Toggle AI generation on/off (`ai_enabled`).
  - Set a custom reply message (`ai_disabled_response_text`) to display when no local FAQ answer match is found and AI fallback is disabled.
  - **Uninterrupted FAQ Pre-Matching**: Local FAQ answers are always matched and delivered first with 0 AI API calls.

<!--
- 🔌 **Live AI Connection Test**:
  - Click **`[ 🔌 Test AI Connection ]`** in Grav Admin settings to test API keys and models live before saving configuration.
-->

- ❓ **Semantic FAQ Pre-Matching Engine**:
  - Pre-matches visitor questions against localized `/faq` pages.
  - Supports **alias variations** (e.g. 15+ ways to ask *"When was this company established?"*).
  - Matching questions return instant answers with **0 AI API calls**, saving quota.
  - Interactive **Yes / No** confirmation buttons with automatic AI fallback escalation.

- 📊 **Real-Time Interaction Metrics & Analytics Dashboard (`<chatbot-metrics>`)**:
  - **Live KPI Metrics**: Total Conversations, Completion Tokens, Estimated API Cost (USD), and Active AI Engine Provider.
  - **Date Range Telemetry Filter**: Filter metrics and graphs by **Last 7 Days**, **Last 30 Days**, **Last 90 Days**, **Last 180 Days**, **Last 365 Days**, or **All Time**.
  - **Live Data Refresh**: Real-time **`[ 🔄 Refresh ]`** action for live metric updates without page reloads.

- 💡 **Candidate FAQ Recommendations Engine (`FaqRecommender.php`)**:
  - Automatically analyzes visitor interaction logs to detect recurring AI queries (`source === 'ai_api'`).
  - Performs string similarity clustering (**75% threshold**) to group questions asked 2+ times with different phrasing.
  - Suggests candidate questions and AI answers to add to local `/faq` for **$0 cost savings**!

- 🚨 **Live Error Log Viewer (`<chatbot-live-logs>`)**:
  - Real-time error log viewer embedded directly in Grav Admin 2 with card border overflow protection.

- 🛡️ **Real-Time Security & Threat Audit Dashboard (`<chatbot-security-logs>`)**:
  - Live threat meter (`🟢 System Secure` / `⚠️ Active Lockout`), blocked threat feed, and one-click IP lockout release actions.

- 📥 **Export & Download Options**:
  - **CSV Report Download**: `/chatbot-export?format=csv&range=...`
  - **JSON Analytics Dataset**: `/chatbot-export?format=json&range=...`
  - **Raw Interactions JSON Download**: `/chatbot-export?format=raw_interactions&range=all`

- 💬 **Customizable Quick Reply Buttons**:
  - Configure quick reply suggestion pills directly in Grav Admin (**`📝 Summarize Page`**, **`📞 Contact Owner`**, **`❓ Founding Date`**, or custom prompts).

- 🎨 **Custom UI Theme Presets & Position**:
  - Select between **Glassmorphic Blue**, **Emerald Dark Mode**, **Purple Haze**, **Sunset Orange**, or **Custom Color Picker**.
  - Position control: **Bottom-Right** or **Bottom-Left**.

- 🔔 **Proactive Notification Toast & Multi-Day Session Retention**:
  - Configurable pop-up toast above floating launcher button.
  - Multi-day `localStorage` session retention across browser refreshes, tabs, and page transitions.

- 📞 **Multi-Tier Contact Resolution**:
  - Reads public `/contact` for general inquiries and hidden/unpublished `/hidden-contacts` for specialized engineering/technical contact requests.

---

## 📈 RAG Token Usage & Cost Optimization

The built-in **RAG (Retrieval-Augmented Generation)** engine eliminates context window bloat and drastically optimizes token consumption by injecting only Top-K relevant section chunks into the LLM system prompt:

| Optimization Metric | Without RAG (Legacy Full-Site Context) | With RAG (SQLite Vector Store) | Performance / Savings Impact |
| :--- | :--- | :--- | :--- |
| **Prompt Token Count** | **204 Tokens** *(scales with site size)* | **36 Tokens** *(constant size)* | **82.35% Token Savings** |
| **Local Retrieval Latency** | 0.006 ms | **0.312 ms** | Sub-millisecond local vector lookup |
| **Monthly Token Volume (10k queries)** | 2.04 Million Tokens | **0.36 Million Tokens** | **1.68 Million Tokens Saved** |
| **Monthly API Cost** (@ $0.15/1M) | $0.3060 / month | **$0.0540 / month** | **82.35% Direct Cost Savings** |

> [!TIP]
> For complete technical benchmarks, token reduction breakdowns, and architectural benchmarks, refer to **[`BENCHMARK-RAG.md`](BENCHMARK-RAG.md)**. For user instructions, refer to **[`MANUAL.md`](MANUAL.md)**.

---

## 🚀 Installation & Setup

### 1. Plugin Installation
Extract or clone this repository into your Grav site's plugins directory:
```bash
cd user/plugins
git clone https://github.com/milkboyinchina/grav-ai-chatbot.git ai-chatbot
```

### 2. FAQ Page Setup
Copy the included example FAQ template file to your Grav pages directory:
```bash
cp user/plugins/ai-chatbot/examples/faq.md user/pages/05.faq/default.md
```
Or create a custom page at `/faq` (`user/pages/05.faq/default.md`) with YAML frontmatter `faqs:` list and question aliases:
```yaml
---
title: Frequently Asked Questions
faqs:
  - question: "When was this company established?"
    aliases:
      - "When was the company founded?"
      - "What is the incorporation date of the business?"
      - "In what year was the company established?"
      - "When did the company officially begin operations?"
      - "How long has this company been around?"
    answer: "Our company was established in 2020."
---
```

---

## ⚙️ Admin Configuration Options

Navigate to **Grav Admin -> Plugins -> Grav AI Chatbot** to configure:

| Setting Group | Configuration Fields |
| :--- | :--- |
| **RAG Retrieval & Vector Store** | Master RAG Toggle (`rag_enabled`), Page Auto-Indexing (`rag_indexing_enabled`), Embedding Provider (Ollama / Gemini / OpenAI / TF-IDF), Top-K Chunks, Min Similarity %, **Grav Scheduler Re-Indexing Cron**, **`⚡ Rebuild RAG Index` Button** |
| **AI Provider Settings** | Enable AI Fallback (`ai_enabled`), **AI Disabled Response Message (`ai_disabled_response_text`)**, Provider Select, API Key, Model Identifier, Custom Endpoint, **Live Test AI Connection Button** |
| **FAQ Settings** | Enable FAQ Pre-Matching, Multilingual FAQ Matching, FAQ Route (`/faq`), Similarity Sensitivity Threshold (%) |
| **Contact Resolution** | Public Contact Route (`/contact`), Enable Hidden Contact Page, Hidden Contact Route (`/hidden-contacts`) |
| **Chatbot Widget UI** | Theme Presets, Floating Position, Page Display Visibility Rules, Welcome Message, Accent Color, **Quick Reply Buttons List** |
| **Proactive Toasts** | Enable Toast, Toast Text, Delay Seconds |
| **Session Retention** | Session Retention Period (Days) |
| **Security & Limits** | IP Rate Limiting Enable, Max Requests Per Window, Window Duration (Seconds), Strict Site Scope |
| **Analytics & Telemetry** | Enable Interaction Logging, **Date Range Filter (7d, 1m, 3m, 6m, 12m, All)**, Export CSV/JSON/Raw Links |

---

## 📊 Analytics & Export API Endpoints

- **Live Analytics Report**: `POST /chatbot-api` with `{ "action": "analytics_report", "range": "7d" }`
- **CSV Download**: `GET /chatbot-export?format=csv&range=30d`
- **JSON Download**: `GET /chatbot-export?format=json&range=90d`
- **Raw Interactions Download**: `GET /chatbot-export?format=raw_interactions&range=all`

---

## 📄 License

This project is licensed under the [GNU General Public License v3.0 (GPLv3)](LICENSE).

## Credits
> Countless free API tokens were brutally sacrificed during the testing of this bot—may their KV caches rest in peace. 🕊️