# Grav CMS AI Chatbot — Plugin Configuration & User Manual

Welcome to the official manual for the **Grav CMS AI Chatbot Plugin** (`user/plugins/ai-chatbot`). This guide is structured to directly map to the **Grav Admin Plugin Configuration Page** (**Plugins -> AI Chatbot**), explaining every field, toggle, blueprint section, code snippet, and background process.

---

## 📋 Table of Contents

1. [Plugin Master Enable / Disable](#1-plugin-master-enable--disable)
2. [Section 1: AI Provider Settings (`section_provider`)](#section-1-ai-provider-settings-section_provider)
3. [Section 2: 🧠 RAG (Retrieval-Augmented Generation) & Page Indexing Engine (`section_rag`)](#section-2--rag-retrieval-augmented-generation--page-indexing-engine-section_rag)
   - [Does Installing the Plugin Add a Cron Schedule Automatically?](#does-installing-the-plugin-add-a-cron-schedule-automatically)
   - [Server Crontab Setup Guide](#server-crontab-setup-guide)
   - [RAG Architecture & Heading-Aware Chunking Example](#rag-architecture--heading-aware-chunking-example)
4. [Section 3: ❓ FAQ Settings (`section_faq`)](#section-3--faq-settings-section_faq)
5. [Section 4: 📞 Contact Settings (`section_contact`)](#section-4--contact-settings-section_contact)
6. [Section 5: 🎨 UI & Chatbot Widget Settings (`section_ui`)](#section-5--ui--chatbot-widget-settings-section_ui)
7. [Section 6: 🕒 Session Retention Settings (`section_session`)](#section-6--session-retention-settings-section_session)
8. [Section 7: 🛡️ Security & Limits Settings (`section_security`)](#section-7--security--limits-settings-section_security)
9. [Section 8: 📝 Logging & Cost Estimation Settings (`section_logging`)](#section-8--logging--cost-estimation-settings-section_logging)
10. [Section 9: 📊 AI Analytics & Performance Dashboard (`section_analytics`)](#section-9--ai-analytics--performance-dashboard-section_analytics)
11. [CLI Commands Reference](#cli-commands-reference)

---

## 1. Plugin Master Enable / Disable

| Field Name | Type | Options / Default | Description & Instructions |
| :--- | :--- | :--- | :--- |
| **Plugin Status** (`enabled`) | Toggle | `1` (Enabled) / `0` (Disabled) | Master switch for the entire plugin. When set to `0`, all chatbot endpoints, Twig assets, and scheduled tasks are deactivated. |

---

## Section 1: AI Provider Settings (`section_provider`)

Controls the core AI model connection, API authentication keys, model parameters, and offline fallback responses.

| Field Label | Setting Key | Default Value | Options / Help Guide |
| :--- | :--- | :--- | :--- |
| **Enable AI Fallback** | `ai_enabled` | `1` (Enabled) | Master toggle for cloud AI generation. When disabled, only local FAQ answers are delivered. |
| **AI Disabled Message** | `ai_disabled_response_text` | *"AI assistant is currently disabled..."* | Text displayed to visitors when AI fallback is disabled and no local FAQ answer matches. |
| **AI Provider** | `provider` | `groq` | Select provider: `groq` (Groq Llama 3), `gemini` (Google Gemini), `openrouter` (OpenRouter API), `openai` (OpenAI), `ollama` (Local/Remote Ollama), `custom` (OpenAI-compatible server). |
| **API Key** | `api_key` | *Blank* | Secret API Key for Groq, Gemini, OpenRouter, or OpenAI (Leave blank for local Ollama). |
| **Model Identifier** | `model` | `gemini-2.0-flash` | Identifier string (e.g., `gemini-2.0-flash`, `gpt-4o-mini`, `llama-3.3-70b-versatile`, `nomic-embed-text`, `deepseek-r1`). |
| **Custom Endpoint URL** | `custom_endpoint` | *Blank* | Required for `custom` provider. For `ollama`, set remote host (e.g. `http://192.168.1.50:11434`) or leave blank for default local `http://localhost:11434`. |
| **API Timeout (Sec)** | `api_timeout` | `30` | Maximum HTTP execution time in seconds (Range: 5 - 120s). |
| **Max Output Tokens** | `max_tokens` | `800` | Token limit for AI completion replies (Range: 50 - 4,000 tokens). |
| **Max Input Tokens** | `max_input_tokens` | `500` | Maximum allowed tokens per user question (~2000 characters). |
| **Context Window** | `context_window_tokens` | `8192` | Model token capacity (e.g., 8192, 16384, 128000). |

---

## Section 2: 🧠 RAG (Retrieval-Augmented Generation) & Page Indexing Engine (`section_rag`)

The RAG engine parses your published Grav site pages, breaks them down into heading-aware sections, and stores them in a local SQLite vector database (`user/data/ai-chatbot/rag_index.sqlite`).

### RAG Configuration Fields Matrix

| Field Label | Setting Key | Default Value | Options / Help Guide |
| :--- | :--- | :--- | :--- |
| **Enable RAG Retrieval** | `rag_enabled` | `1` (Enabled) | Use SQLite vector search to inject relevant section chunks into prompts instead of full-site text. |
| **Automatic Page Indexing** | `rag_indexing_enabled` | `1` (Enabled) | Enables automatic indexing on `onPageSaved`, `onPageDeleted`, and background cron runs. |
| **RAG Embedding Engine** | `rag_embedding_provider` | `tfidf_local` | Drivers: `tfidf_local` (Free local BM25), `ollama` (Local Ollama), `gemini` (`text-embedding-004`), `openai` (`text-embedding-3-small`). |
| **Embedding Model ID** | `rag_embedding_model` | `tfidf_local` | Model string (e.g., `nomic-embed-text`, `text-embedding-004`, `text-embedding-3-small`). |
| **Top-K Chunks Count** | `rag_top_k` | `3` | Number of section chunks injected per prompt (Range: 1 - 10). |
| **Min Similarity %** | `rag_min_similarity` | `65` | Minimum relevance threshold score % required to include a chunk (Range: 10 - 100%). |
| **Background Cron Scheduler** | `rag_scheduler_enabled` | `1` (Enabled) | Automatically re-indexes site pages in background via Grav CMS Scheduler. |
| **Scheduled Cron Expression**| `rag_scheduler_cron` | `0 2 * * *` | 5-field cron schedule expression (`0 2 * * *` = Daily at 2:00 AM). |
| **Rebuild RAG Index Button** | `rebuild_rag_index_button` | *Interactive Button* | Click **`⚡ Rebuild RAG Vector Index Now`** to clear SQLite storage and re-index all site pages instantly. |

---

### Does Installing the Plugin Add a Cron Schedule Automatically?

> [!IMPORTANT]
> **YES and NO**:
> 
> 1. **Inside Grav CMS (AUTOMATIC)**:  
>    **Yes.** When the plugin is enabled with `rag_scheduler_enabled: 1`, it registers its background job (`ai-chatbot-rag-reindex`) automatically with Grav's internal scheduler via `onSchedulerInitialized`. You will see it listed under **Grav Admin -> Tools -> Scheduler**.
> 
> 2. **On the Server Level (ONE-TIME SETUP REQUIRED)**:  
>    **No.** Grav relies on a single master Linux system crontab entry to run its scheduler loop. If your server does not have Grav's master cron job active, you must add it once.

### Server Crontab Setup Guide

Run `crontab -e` on your server terminal and add:
```bash
* * * * * cd /home/milkboy/Documents/web-app/personal-cv-site && php bin/grav scheduler 1>/dev/null 2>&1
```

---

### RAG Architecture & Heading-Aware Chunking Example

#### Sample Page (`user/pages/02.docs/default.md`):
```markdown
# Installation Guide

## Requirements
Ensure PHP 8.1+ and SQLite3 extensions are installed.

## Quick Start
Run `composer install` in your Grav root directory.
```

#### Generated SQLite Vector Chunks:
```json
[
  {
    "chunk_id": "8f1a2b3c",
    "route": "/docs",
    "title": "Installation Guide",
    "section": "Requirements",
    "anchor": "/docs#requirements",
    "content": "Ensure PHP 8.1+ and SQLite3 extensions are installed.",
    "hash": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
  },
  {
    "chunk_id": "9d4e5f6a",
    "route": "/docs",
    "title": "Installation Guide",
    "section": "Quick Start",
    "anchor": "/docs#quick-start",
    "content": "Run composer install in your Grav root directory.",
    "hash": "f2ca1bb6c7e907d06dafe4687e579fce76b37e4e93b7605022da52e6ccc26fd2"
  }
]
```

---

## Section 3: ❓ FAQ Settings (`section_faq`)

Pre-matches visitor questions against localized `/faq` pages with **0 AI API calls**.

| Field Label | Setting Key | Default Value | Description & Options |
| :--- | :--- | :--- | :--- |
| **Enable FAQ Pre-Matching** | `faq_enabled` | `1` (Enabled) | Matches questions locally against `/faq` pages before invoking AI APIs. |
| **Multilingual FAQ Matching** | `enable_multilingual_faq` | `1` (Enabled) | Matches language-specific FAQ files (e.g. `default.en.md`, `default.fr.md`). |
| **FAQ Route** | `faq_route` | `/faq` | Relative page route path containing FAQ YAML definitions. |
| **Similarity Threshold %** | `faq_similarity_threshold` | `70` | Text similarity sensitivity threshold (Range: 50% - 100%). |

#### Sample FAQ Frontmatter (`user/pages/05.faq/default.md`):
```yaml
---
title: Frequently Asked Questions
faqs:
  - question: "What are your business hours?"
    aliases:
      - "When are you open?"
      - "Operating hours"
    answer: "We are open Monday through Friday from 9:00 AM to 5:00 PM EST."
---
```

---

## Section 4: 📞 Contact Settings (`section_contact`)

Multi-tier contact resolution for public and technical inquiries.

| Field Label | Setting Key | Default Value | Description & Options |
| :--- | :--- | :--- | :--- |
| **Public Contact Route** | `contact_route` | `/contact` | Relative route to public contact page. |
| **Enable Hidden Contacts** | `enable_hidden_contacts` | `1` (Enabled) | Reads hidden `/hidden-contacts` page for technical/engineering support inquiries. |
| **Hidden Contact Route** | `hidden_contact_route` | `/hidden-contacts` | Route path for unpublished technical contact data. |

---

## Section 5: 🎨 UI & Chatbot Widget Settings (`section_ui`)

Controls floating widget aesthetics, themes, page display visibility, and quick reply suggestion pills.

| Field Label | Setting Key | Default Value | Description & Options |
| :--- | :--- | :--- | :--- |
| **Theme Preset** | `theme_preset` | `glass_blue` | Presets: `glass_blue` (Glassmorphic Blue), `emerald_dark` (Emerald Dark Mode), `purple_haze` (Purple Haze), `sunset_orange` (Sunset Orange), `custom` (Color Picker). |
| **Widget Position** | `position` | `bottom-right` | Options: `bottom-right` or `bottom-left`. |
| **Page Visibility Rule** | `display_mode` | `all` | Options: `all` (Show Everywhere), `selected_only` (Only Selected Routes), `exclude_selected` (Exclude Selected Routes). |
| **Target Page Routes** | `display_pages` | `/\n/home\n/contact\n/faq` | Relative URL routes (one per line) used for page visibility rules. |
| **Header Window Title** | `bot_title` | `Website Assistant` | Header text displayed at the top of the chat widget window. |
| **Welcome Message** | `welcome_message` | *"Hello! How can I help..."* | Initial message displayed when opening the chat window. |
| **Accent Color** | `accent_color` | `#3b82f6` | Hex color picker used when `theme_preset` is set to `custom`. |
| **Enable Quick Replies** | `quick_replies_enabled` | `1` (Enabled) | Shows clickable suggestion pills inside the chat window. |
| **Quick Reply Buttons List**| `quick_replies` | *List of pills* | Custom list of buttons (`label`, `type`, `action_or_prompt`). |

#### Quick Replies YAML Snippet:
```yaml
quick_replies:
  - label: "📝 Summarize Page"
    action_or_prompt: "summarize_page"
  - label: "📞 Contact Owner"
    action_or_prompt: "contact"
```

---

## Section 6: 🕒 Session Retention Settings (`section_session`)

| Field Label | Setting Key | Default Value | Description & Options |
| :--- | :--- | :--- | :--- |
| **Session Retention Days** | `session_retention_days` | `7` | Days to retain visitor chat history in browser `localStorage` across refreshes and page transitions. |

---

## Section 7: 🛡️ Security & Limits Settings (`section_security`)

| Field Label | Setting Key | Default Value | Description & Options |
| :--- | :--- | :--- | :--- |
| **Rate Limiting** | `rate_limit_enabled` | `1` (Enabled) | Protects against API quota exhaustion and spam attacks. |
| **Max Requests** | `rate_limit_max_requests` | `10` | Maximum requests allowed per IP within the time window. |
| **Window Duration (Sec)** | `rate_limit_window_seconds` | `60` | Time window duration in seconds (Default 60s). |
| **Strict Site Scope** | `strict_site_scope` | `1` (Enabled) | Restricts AI answers strictly to your website scope. |
| **Blacklist Guardrail** | `blacklist_filter_enabled` | `1` (Enabled) | Blocks queries containing forbidden terms before sending to AI API. |
| **Blacklisted Vocabulary** | `blacklist_words` | `spam\nscam\nhack...` | List of restricted words/profanity (one per line). |
| **Blacklist Response Text**| `blacklist_response_text` | *"⚠️ Safety Guardrail..."* | Warning message shown to visitors when restricted terms are detected. |
| **Restrict Export Access** | `export_require_auth` | `1` (Enabled) | Requires user to be logged into Grav Admin to download CSV/JSON reports. |
| **Whitelisted Export Users**| `export_allowed_users` | `admin\nmilkboy` | Authorized usernames allowed to download telemetry files (one per line). |

---

## Section 8: 📝 Logging & Cost Estimation Settings (`section_logging`)

| Field Label | Setting Key | Default Value | Description & Options |
| :--- | :--- | :--- | :--- |
| **Logging Enabled** | `logging_enabled` | `1` (Enabled) | Logs visitor queries to `user/data/ai-chatbot/interactions.json`. |
| **Log API Usage & Costs** | `log_api_usage` | `1` (Enabled) | Tracks token consumption and calculates estimated USD costs. |
| **Log AI Server Responses**| `log_ai_responses` | `0` (Disabled) | Records raw AI payloads to `user/data/ai-chatbot/ai_responses.log`. |
| **Input Token Price ($/1M)**| `cost_input_token_price_per_m` | `0.15` | Input price per 1M tokens in USD (e.g. Gemini 1.5 Flash = $0.15). |
| **Output Token Price ($/1M)**| `cost_output_token_price_per_m`| `0.60` | Output price per 1M tokens in USD (e.g. Gemini 1.5 Flash = $0.60). |

---

## Section 9: 📊 AI Analytics & Performance Dashboard (`section_analytics`)

Access visual dashboards under **Grav Admin -> Plugins -> AI Chatbot -> Section 9**:

- **Update Chart Metrics Button**: Click **`🔄 Update & Regenerate Chart Metrics`** to sync live charts instantly.
- **Visual SVG Graphs**: View daily interaction volume bar charts and query source ratios (FAQ Hits vs AI Calls vs Rate Limits).
- **Export Links**:
  - CSV Report: `/chatbot-export?format=csv&range=30d`
  - JSON Analytics: `/chatbot-export?format=json&range=90d`
  - Raw Interaction Logs: `/chatbot-export?format=raw_interactions&range=all`

---

## CLI Commands Reference

Execute commands via SSH terminal in your Grav root directory:

```bash
# 1. Force complete rebuild of RAG SQLite Vector Index
php bin/plugin ai-chatbot index-rag --rebuild

# 2. Execute Grav Scheduler background job loop manually
php bin/grav scheduler -r

# 3. Clear Grav site cache and plugin data cache
php bin/grav clear-site-cache
```
