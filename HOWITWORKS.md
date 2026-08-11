# How Grav AI Chatbot Works — Technical Architecture & Workflow

This document explains the internal execution flow, multi-tier request pipeline, local vector storage, and background processes powering the **Grav CMS AI Chatbot Plugin** (`user/plugins/ai-chatbot`).

---

## 🏗️ System Architecture Overview

```
                      +-----------------------------+
                      |   Visitor Browser Query     |
                      +-----------------------------+
                                     |
                                     v
                      +-----------------------------+
                      |  AJAX Request Router        |
                      |  (/chatbot-api endpoint)    |
                      +-----------------------------+
                                     |
                                     v
                      +-----------------------------+
                      |  Tier -1: Rate Limiter      |  ---> [Exceeded] ---> Return HTTP 429 Shield
                      +-----------------------------+
                                     | [Pass]
                                     v
                      +-----------------------------+
                      |  Tier 0: Safety Guardrail   |  ---> [Matched Forbidden Term] ---> Block Message
                      +-----------------------------+
                                     | [Pass]
                                     v
                      +-----------------------------+
                      |  Tier 1: Semantic Local FAQ |  ---> [Matched FAQ] ---> Instant Answer (0 Tokens)
                      +-----------------------------+
                                     | [No Match]
                                     v
                      +-----------------------------+
                      |  Tier 2: Contact Resolver   |  ---> [Contact Intent] ---> Local /contact Info
                      +-----------------------------+
                                     | [No Match]
                                     v
                      +-----------------------------+
                      |  Tier 3: RAG Retrieval      |  ---> Query SQLite Vector Store (`rag_index.sqlite`)
                      +-----------------------------+       Extracts Top-K Relevant Section Chunks (< 1ms)
                                     |
                                     v
                      +-----------------------------+
                      |  Tier 4: Cloud / Local LLM  |  ---> Injects Top-K Context + User Question
                      |  (Gemini/Groq/Ollama/OpenAI)|       Generates Factual AI Response
                      +-----------------------------+
                                     |
                                     v
                      +-----------------------------+
                      |  Telemetry & Cost Logger    |  ---> Record to `user/data/ai-chatbot/interactions.json`
                      +-----------------------------+
```

---

## ⚡ The 5-Tier Request Resolution Pipeline

When a visitor types a question into the floating chat widget, the request passes through 5 distinct resolution tiers to minimize API costs and guarantee fast, safe responses:

### 🛡️ Tier -1: IP-Based Rate Limiting
- **Component**: [`RateLimiter.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/classes/RateLimiter.php)
- **How it works**: Checks the client IP against Grav's cache store using a rolling time window (`rate_limit_max_requests` per `rate_limit_window_seconds`).
- **Outcome**: If a visitor sends too many requests in a short window, the pipeline halts immediately with an HTTP 429 response before calling any AI APIs.

### 🚫 Tier 0: Blacklisted Words Safety Guardrail
- **Component**: [`ChatbotHandler.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/classes/ChatbotHandler.php)
- **How it works**: Compares visitor text against the restricted vocabulary list configured in Admin.
- **Outcome**: If forbidden terms (e.g. `exploit`, `admin_password`) are detected, the request is blocked locally with a safety notice, consuming **0 API tokens**.

### ❓ Tier 1: Local Semantic FAQ Pre-Matching
- **Component**: [`FaqResolver.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/classes/FaqResolver.php)
- **How it works**: Compares the question against localized `/faq` frontmatter definitions and alias variations.
- **Outcome**: Matching questions return instant answers with **0 AI API calls**. Includes interactive Yes/No confirmation buttons for visitor escalation.

### 📞 Tier 2: Multi-Tier Contact Intent Resolution
- **Component**: [`ContactPageResolver.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/classes/ContactPageResolver.php)
- **How it works**: Detects contact-seeking phrases (e.g. *"how can I email support?"*, *"where is your office?"*).
- **Outcome**: Returns formatted contact details from public `/contact` or hidden technical support `/hidden-contacts` frontmatter locally.

### 🧠 Tier 3 & 4: RAG Retrieval & AI Completion
- **Components**: [`Retriever.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/classes/Rag/Retriever.php) & [`AiClientFactory.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/classes/AiClientFactory.php)
- **How it works**:
  1. Generates an embedding vector for the question using the selected RAG engine (TF-IDF local, Ollama, Gemini, or OpenAI).
  2. Searches `user/data/ai-chatbot/rag_index.sqlite` and extracts the Top-K (e.g. 3) most relevant section chunks.
  3. Injects only those specific section chunks into the LLM system prompt.
  4. Dispatches the prompt to the configured AI Provider (**Google Gemini**, **Groq**, **OpenAI**, **OpenRouter**, or **Ollama**).

---

## 📦 RAG Ingestion & Page Indexing Engine

The RAG engine automatically processes published routable pages to keep vector search up-to-date:

```
 Grav CMS Pages  --->  Clean Markdown/HTML  --->  Heading-Aware  --->  SHA-256 Hash  --->  Vector Store
 (Published)           (Strip Nav/CSS)            Chunker (H1-H3)        Check                (SQLite DB)
```

1. **Heading-Aware Page Chunking** ([`Chunker.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/classes/Rag/Chunker.php)):  
   Segments pages into discrete sections based on Markdown headers (`# H1`, `## H2`, `### H3`), tagging each chunk with page title, route, section name, and exact anchor URL (e.g. `/docs#requirements`).

2. **Incremental SHA-256 Hash Caching** ([`Indexer.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/classes/Rag/Indexer.php)):  
   Before calling vector embedding APIs, the system calculates the SHA-256 hash of each section. If the hash matches the SQLite record, **re-indexing skips the section with 0 API calls**.

3. **SQLite Vector Storage** ([`VectorStore.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/classes/Rag/VectorStore.php)):  
   Stores text chunks, metadata, and float vector arrays in `user/data/ai-chatbot/rag_index.sqlite`. Computes cosine similarity and keyword overlap scores in `< 1ms`.

---

## ⏰ Background Scheduling & Event Hooks

- **Real-Time Indexing**: Listens to Grav events `onPageSaved` and `onPageDeleted` to re-chunk modified pages immediately.
- **Grav Scheduler Cron**: Listens to `onSchedulerInitialized` to run periodic background re-indexing via job `ai-chatbot-rag-reindex` (configured via 5-field cron expression, default `0 2 * * *`).

---

## 📊 Analytics Telemetry & Data Logging

- Every query is logged to `user/data/ai-chatbot/interactions.json` with token consumption, resolution source (`faq_match`, `rate_limit`, `guardrail`, `contact_resolver`, or `rag_ai`), and estimated USD costs.
- The Admin dashboard generates visual SVG bar charts, query distribution ratios, and candidate FAQ recommendations automatically.
