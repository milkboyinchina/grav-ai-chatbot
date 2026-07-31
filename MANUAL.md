# Grav CMS AI Chatbot — Official User & Administrator Manual

Welcome to the comprehensive user manual for the **Grav CMS AI Chatbot Plugin** (`user/plugins/ai-chatbot`). This guide provides detailed end-to-end instructions, real-world code examples, configuration samples, and administrative procedures for managing the **Retrieval-Augmented Generation (RAG) & Page Indexing Engine**.

---

## 📋 Table of Contents

1. [Overview & Key Features](#1-overview--key-features)
2. [Grav Scheduler & Automated Cron Setup](#2-grav-scheduler--automated-cron-setup)
   - [Does Installing the Plugin Add a Cron Schedule Automatically?](#does-installing-the-plugin-add-a-cron-schedule-automatically)
   - [Step-by-Step Server Crontab Setup](#step-by-step-server-crontab-setup)
3. [Deep Dive: RAG (Retrieval-Augmented Generation) & Page Indexing Engine](#3-deep-dive-rag-retrieval-augmented-generation--page-indexing-engine)
   - [Architecture & How RAG Works](#architecture--how-rag-works)
   - [Supported Embedding Drivers & Code Examples](#supported-embedding-drivers--code-examples)
   - [Heading-Aware Page Chunking Example](#heading-aware-page-chunking-example)
   - [Incremental SHA-256 Hash Caching](#incremental-sha-256-hash-caching)
   - [Manual vs Automated Indexing Workflows](#manual-vs-automated-indexing-workflows)
4. [Grav Admin Configuration Options](#4-grav-admin-configuration-options)
5. [Semantic FAQ Pre-Matching Engine & YAML Examples](#5-semantic-faq-pre-matching-engine--yaml-examples)
6. [Multi-Tier Contact Resolution & Hidden Contact Setup](#6-multi-tier-contact-resolution--hidden-contact-setup)
7. [Widget Customization & Quick Reply Configuration](#7-widget-customization--quick-reply-configuration)
8. [Date Range Analytics & Data Export API Examples](#8-date-range-analytics--data-export-api-examples)
9. [CLI Commands Reference & Examples](#9-cli-commands-reference--examples)
10. [Troubleshooting & FAQs](#10-troubleshooting--faqs)

---

## 1. Overview & Key Features

The **Grav CMS AI Chatbot Plugin** delivers a high-performance conversational AI interface for your website. It pairs local zero-token FAQ pre-matching with RAG vector search and multi-provider LLMs (**Groq Llama 3**, **Google Gemini**, **OpenAI**, **OpenRouter**, or **Local Ollama**).

---

## 2. Grav Scheduler & Automated Cron Setup

### Does Installing the Plugin Add a Cron Schedule Automatically?

> [!IMPORTANT]
> **YES and NO**:
> 
> 1. **Inside Grav CMS (AUTOMATIC)**:  
>    **Yes.** When you install and enable the plugin with `rag_scheduler_enabled: true`, the plugin automatically registers its background job (`ai-chatbot-rag-reindex`) with Grav's internal scheduler via the `onSchedulerInitialized` event hook. You will immediately see the job listed under **Grav Admin -> Tools -> Scheduler**.
> 
> 2. **On the Linux Server / Hosting Level (ONE-TIME SETUP REQUIRED)**:  
>    **No.** Grav CMS relies on a single master system cron entry on your Linux server or Web Hosting CPanel to run its scheduler loop. If your server does not already have Grav's master cron job active, you must add it once.

### Step-by-Step Server Crontab Setup

To allow Grav (and the AI Chatbot background re-indexer) to execute scheduled tasks automatically:

1. Open your Linux user crontab editor:
   ```bash
   crontab -e
   ```
2. Add the following master Grav scheduler entry (runs every minute to check if jobs are due):
   ```bash
   * * * * * cd /home/milkboy/Documents/web-app/personal-cv-site && php bin/grav scheduler 1>/dev/null 2>&1
   ```
3. Test Grav Scheduler execution manually via SSH CLI:
   ```bash
   php bin/grav scheduler -r
   ```

---

## 3. Deep Dive: RAG (Retrieval-Augmented Generation) & Page Indexing Engine

### Architecture & How RAG Works

Traditional AI chatbots inject the entire website content into every prompt, consuming thousands of tokens per request. 

The RAG engine solves this by indexing your published site pages into a local SQLite vector database (`user/data/ai-chatbot/rag_index.sqlite`). When a visitor asks a question:
1. The question vector or keywords are queried against local SQLite chunks in `< 1ms`.
2. Only the Top-K (e.g. 2–3) most relevant paragraph sections are extracted.
3. Only those specific sections are injected into the AI system prompt, achieving an **80%+ token reduction**.

```
+-----------------------------------------------------------------------------+
|                            INGESTION PIPELINE                               |
|                                                                             |
|  Grav CMS Pages  --->  HTML/Markdown  --->  Heading-Aware  --->  Vector     |
|  (Published &          Cleaner              Chunker              Embeddings |
|   Routable)                                                      Generator  |
|                                                                      |      |
|                                                                      v      |
|                                                            RAG Index Store  |
|                                                            (SQLite Database)|
+-----------------------------------------------------------------------------+
                                                                       |
+-----------------------------------------------------------------------------v
|                             RETRIEVAL PIPELINE                              |
|                                                                             |
|  User Query  ---> Embed Query ---> Vector Cosine / ---> Top-K Chunks  --->  |
|                                    BM25 Search         + Metadata           |
|                                                                      |      |
|                                                                      v      |
|                                                            LLM Prompt       |
|                                                            Injection        |
+-----------------------------------------------------------------------------+
```

---

### Supported Embedding Drivers & Code Examples

Configure the embedding driver in `user/config/plugins/ai-chatbot.yaml` or via Grav Admin (**Admin -> Plugins -> AI Chatbot -> RAG Settings**):

#### Option A: Local TF-IDF / BM25 Driver (Default - Zero API Token Cost)
```yaml
rag_enabled: true
rag_indexing_enabled: true
rag_embedding_provider: tfidf_local
rag_top_k: 3
rag_min_similarity: 65
```
*Benefits*: 100% server-local execution, no paid API keys required, zero latency overhead.

#### Option B: Local Ollama Driver
```yaml
rag_enabled: true
rag_indexing_enabled: true
rag_embedding_provider: ollama
rag_embedding_model: nomic-embed-text
rag_custom_endpoint: http://localhost:11434/api/embeddings
```

#### Option C: Google Gemini Driver
```yaml
rag_enabled: true
rag_indexing_enabled: true
rag_embedding_provider: gemini
rag_embedding_model: text-embedding-004
gemini_api_key: "YOUR_GEMINI_API_KEY"
```

---

### Heading-Aware Page Chunking Example

The chunker (`classes/Rag/Chunker.php`) scans routable Grav pages by heading markers (`H1`, `H2`, `H3`).

#### Example Page (`user/pages/02.docs/default.md`):
```markdown
# Installation Guide

## Requirements
Ensure PHP 8.1+ and SQLite3 extensions are installed.

## Quick Start
Run `composer install` in your Grav root directory.
```

#### Generated RAG Chunks stored in SQLite:
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

### Incremental SHA-256 Hash Caching

During background re-indexing, the system calculates the SHA-256 hash of each page section:
1. It compares `$newHash` against `$storedHash` in `rag_index.sqlite`.
2. If unchanged, **embedding generation is skipped**, consuming **0 API calls**.
3. If modified, only the updated section is re-embedded.

---

### Manual vs Automated Indexing Workflows

#### 1. Event-Driven Auto-Indexing
Whenever a page is published, modified, or deleted in Grav Admin, the plugin intercepts `onPageSaved` and `onPageDeleted` to update SQLite in real time.

#### 2. Scheduled Background Indexing
Configured via cron expression (`rag_scheduler_cron`, default: `0 2 * * *` [Daily at 2:00 AM]).

#### 3. Manual Admin Rebuild
Click **`⚡ Rebuild RAG Index`** under **Grav Admin -> Plugins -> AI Chatbot** to purge and re-index the database on demand.

---

## 4. Grav Admin Configuration Options

| Setting Group | Configuration Fields & Options |
| :--- | :--- |
| **RAG Retrieval & Vector Store** | Master Toggle (`rag_enabled`), Page Auto-Indexing (`rag_indexing_enabled`), Embedding Provider Select, Top-K Count, Min Similarity %, **Scheduler Cron**, **`⚡ Rebuild RAG Index` Button** |
| **AI Provider Settings** | Master AI Switch (`ai_enabled`), Custom Offline Message (`ai_disabled_response_text`), Provider Select, API Keys, Model Name, **Live Test AI Connection Button** |
| **FAQ Engine** | Enable FAQ Pre-Matching, Multilingual FAQ Support, FAQ Route (`/faq`), Similarity Sensitivity Threshold (%) |
| **Contact Resolution** | Public Contact Route (`/contact`), Hidden Technical Contact Route (`/hidden-contacts`) |
| **Widget UI Customization** | Presets (Glassmorphic Blue, Emerald Dark, etc.), Accent Color Picker, Screen Position, Quick Reply Suggestion Pills |
| **Telemetry & Analytics** | Logging Toggle, Date Range Filter (7d, 30d, 90d, 180d, 365d, All), CSV/JSON Export Links |

---

## 5. Semantic FAQ Pre-Matching Engine & YAML Examples

Create a page at `/faq` (`user/pages/05.faq/default.md`) with frontmatter aliases:

```yaml
---
title: Frequently Asked Questions
faqs:
  - question: "What are your business hours?"
    aliases:
      - "When are you open?"
      - "What time do you open and close?"
      - "Operating hours"
      - "Work schedule"
    answer: "We are open Monday through Friday from 9:00 AM to 5:00 PM EST."

  - question: "Where is your office located?"
    aliases:
      - "What is your address?"
      - "How do I get to your office?"
      - "Location details"
    answer: "Our main office is located at 123 Innovation Way, Tech Suite 400."
---
```

When a visitor types *"When are you open?"*, the bot returns the exact FAQ answer with **0 AI API calls**.

---

## 6. Multi-Tier Contact Resolution & Hidden Contact Setup

To provide secure engineering/support contacts for technical questions without exposing them on public pages, create a hidden page at `/hidden-contacts` (`user/pages/hidden-contacts/default.md`):

```yaml
---
title: Technical Support Contacts
published: false
routable: false
technical_contacts:
  - department: "DevOps & Cloud Infrastructure"
    email: "devops-support@example.com"
    telegram: "@devops_oncall"
  - department: "Security & Encryption"
    email: "security@example.com"
    pgp_key_id: "0x9B8A7C6D5E4F3A2B"
---
```

---

## 7. Widget Customization & Quick Reply Configuration

Add customized quick reply suggestion pills in `user/config/plugins/ai-chatbot.yaml`:

```yaml
quick_reply_buttons:
  - label: "📝 Summarize Page"
    prompt: "Can you provide a concise summary of this page?"
  - label: "📞 Contact Support"
    prompt: "How can I contact your engineering support team?"
  - label: "❓ Business Hours"
    prompt: "What are your operating hours?"
```

---

## 8. Date Range Analytics & Data Export API Examples

Export interaction telemetry programmatically via HTTP REST endpoints:

- **Export 30-Day CSV Report**:
  ```bash
  curl -X GET "https://yoursite.com/chatbot-export?format=csv&range=30d" -o chatbot_report.csv
  ```
- **Export 90-Day JSON Analytics**:
  ```bash
  curl -X GET "https://yoursite.com/chatbot-export?format=json&range=90d" -o chatbot_analytics.json
  ```
- **Export All-Time Raw Interaction Logs**:
  ```bash
  curl -X GET "https://yoursite.com/chatbot-export?format=raw_interactions&range=all" -o raw_interactions.json
  ```

---

## 9. CLI Commands Reference & Examples

Run commands via SSH terminal in your Grav root directory:

```bash
# 1. Force complete rebuild of RAG SQLite Vector Index
php bin/plugin ai-chatbot index-rag --rebuild

# 2. Run Grav Scheduler execution loop manually
php bin/grav scheduler -r

# 3. Clear Grav site cache and chatbot data cache
php bin/grav clear-site-cache
```

---

## 10. Troubleshooting & FAQs

### Q: Why is RAG background re-indexing not running?
1. Verify `rag_scheduler_enabled: true` in plugin settings.
2. Confirm server crontab includes `* * * * * cd /path/to/grav && php bin/grav scheduler 1>/dev/null 2>&1`.
3. Inspect scheduler output logs at `user/data/ai-chatbot/rag_scheduler.log`.

### Q: How do I verify SQLite RAG storage is active?
Ensure `user/data/ai-chatbot/rag_index.sqlite` exists and has write permissions. You can click **`⚡ Rebuild RAG Index`** in Grav Admin to verify chunk generation statistics.
