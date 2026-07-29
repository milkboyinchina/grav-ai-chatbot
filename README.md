# Grav CMS AI Chatbot Plugin (`grav-plugin-ai-chatbot`)

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Grav CMS](https://img.shields.io/badge/Grav-1.7%2B-orange.svg)](https://getgrav.org)
[![Version](https://img.shields.io/badge/Version-v1.2.0-green.svg)](https://github.com/milkboyinchina/grav-ai-chatbot)

An intelligent, enterprise-ready **Grav CMS AI Chatbot Plugin** supporting multi-engine AI inference (**Groq Ultra-Fast Llama 3**, **Google Gemini**, **OpenRouter**, **OpenAI**, or **Custom Endpoints**), local semantic FAQ pre-matching with alias normalization, multi-tier contact resolution, customizable quick replies, date range analytics filtering, and visual telemetry dashboards.

---

## 🌟 Core Features

- ⚡ **Multi-Provider AI Engines**:
  - **Groq API** (Default: `llama-3.3-70b-versatile`, `llama-3.1-8b-instant`) — Ultra-fast sub-second LLM inference.
  - **Google Gemini API** (`gemini-1.5-flash`, `gemini-1.5-pro`).
  - **OpenRouter API** (`google/gemini-flash-1.5`, `anthropic/claude-3.5-sonnet`).
  - **OpenAI API** (`gpt-4o-mini`, `gpt-4o`).
  - **Custom Endpoints** (Local Ollama, vLLM, or self-hosted OpenAI-compatible servers).

- 🔌 **Live AI Connection Test**:
  - Click **`[ 🔌 Test AI Connection ]`** in Grav Admin settings to test API keys and models live before saving configuration.

- ❓ **Semantic FAQ Pre-Matching Engine**:
  - Pre-matches visitor questions against localized `/faq` pages.
  - Supports **alias variations** (e.g. 15+ ways to ask *"When was this company established?"*).
  - Matching questions return instant answers with **0 AI API calls**, saving quota.
  - Interactive **Yes / No** confirmation buttons with automatic AI fallback escalation.

- 📅 **Date Range Telemetry & Analytics Dashboard**:
  - Filter interaction logs and graphs by date range: **Last 7 Days**, **Last 1 Month (30d)**, **Last 3 Months (90d)**, **Last 6 Months (180d)**, **Last 12 Months (365d)**, or **All Time**.
  - **`[ 🔄 Refresh ]`** button for instant live data reloading without page refreshes.
  - **Visual SVG Charts**: Daily interaction volume bar charts & query source distribution ratios.
  - **Candidate FAQ Recommendations**: Automatically highlights frequent AI queries to add to `/faq`.

- 📥 **Export & Download Options**:
  - **CSV Report Download**: `/chatbot-export?format=csv&range=...`
  - **JSON Analytics Dataset**: `/chatbot-export?format=json&range=...`
  - **Raw Interactions JSON Download**: `/chatbot-export?format=raw_interactions&range=...`

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
| **AI Provider Settings** | Provider Select, API Key, Model Identifier, Custom Endpoint, **Live Test AI Connection Button** |
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
