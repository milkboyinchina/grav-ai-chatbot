# Changelog

All notable changes to the Grav AI Chatbot plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-08-14

### Added
- **3-Step Interactive Model Setup Wizard** (`<chatbot-model-tools>`):
  - Step 1: `🔑 Test API Key` (Live verification against provider API).
  - Step 2: `🔄 Retrieve Active Models` (Queries provider for available model IDs).
  - Step 3: `⚡ Test Model Health` & `✨ Use This Model` (Live latency health check ping & single-input model field application).
  - Prominent **`✍️ Switch to Unlisted / Custom Model Input`** toggle button styling with blue accent highlight.
- **Alphabetical Sorting**:
  - `AI Provider Engine` options in `blueprints.yaml` sorted alphabetically by provider label.
  - Active provider model IDs in Step 3 sorted alphabetically using `localeCompare()`.
- **3-Level Live Provider Detector**:
  - Detects unsaved dropdown selections (`⚡ Groq Cloud`, `🌐 OpenRouter`, `🤖 OpenAI`, `♊ Google Gemini`, `🦙 Ollama`, `🤖 Custom OpenAI-Compatible`) in real-time.
- **5-Layer Security Suite & Real-Time Audit Dashboard** (`<chatbot-security-logs>`):
  - Automatic normalization of leetspeak & zero-width space obfuscation.
  - Threat detection blocking XSS, SQLi, Prompt Injections, and credential probing.
  - Anonymized IP hash lockout shield (`429 Security Cool-Off`).
- **Retrieval-Augmented Generation (RAG) Vector Search**:
  - SQLite vector store (`rag_index.sqlite`) with cosine similarity.
  - Multi-driver embeddings (Ollama `nomic-embed-text`, Gemini `text-embedding-004`, OpenAI `text-embedding-3-small`, TF-IDF/BM25).
  - Grav CMS Scheduler background cron integration (`ai-chatbot-rag-reindex`).

### Changed
- **Renamed Omniroute & Custom Endpoints**: Renamed `omniroute` to **Custom OpenAI-Compatible** and label to `🌐 Custom URL`.
- **Google Gemini Authentication**: Removed `Authorization: Bearer` headers for Gemini API to ensure `AQ.Ab...` and `AIzaSy...` keys authenticate cleanly with `HTTP 200 OK`.
- **Non-Ollama Payload Sanitization**: Omitted Ollama-specific `options` payload parameters (`num_ctx`, `num_predict`) when sending requests to standard OpenAI endpoints (Groq, OpenRouter, OpenAI).

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
