# Admin2 Analytics & Live Log Compatibility Report

**Plugin**: `grav-plugin-ai-chatbot`  
**Panel**: Grav Admin2 (`user/plugins/admin2`)  
**Date**: August 1, 2026  

---

## Environment & System Context

- **CMS Platform**: Grav CMS `v2.0.12`
- **Admin Interface**: Grav Admin2 SvelteKit SPA (`user/plugins/admin2`) alongside Classic Admin (`user/plugins/admin`)
- **API Engine**: `grav-plugin-api`
- **PHP Environment**: PHP 8.x inside Docker LAMP container (`grav-lamp-web`)
- **Active LLM Provider**: Local Ollama (`qwen2.5-fast:latest`)
- **Supported AI Providers**: Ollama, Google Gemini, Groq, OpenRouter, OpenAI

---

## Live Browser Inspection vs Expected Data

When you open the AI Chatbot settings inside Admin2 (`http://localhost/admin/plugins/ai-chatbot`), three form fields display static placeholder text instead of live logged stats:

### 1. 📊 Interaction Summary Metrics (`analytics_summary_text`)
- **What Admin2 Displays on Screen**:
  `Total Queries: 0 | FAQ Matches: 0 (0% Saved) | AI Calls: 0 | Total Tokens: 0 | Est. Cost: $0.0000`
- **Actual Live Data (What it should display)**:
  `Total Queries: 59 | FAQ Matches: 24 (41% Saved) | AI Calls: 10 | Total Tokens: 4,827 | Est. Cost: $0.0009`

---

### 2. 📈 Visual Interaction Volume & Source Distribution Chart (`analytics_chart_display`)
- **What Admin2 Displays on Screen**:
  `DAILY INTERACTION VOLUME: (No interaction data logged yet)...`
- **Actual Live Data (What it should display)**:
  ```text
  📈 DAILY INTERACTION VOLUME:
    2026-07-29 : ███████████ (13 queries)
    2026-07-30 : ████████████████████ (24 queries)
    2026-07-31 : ██████████ (12 queries)
    2026-08-01 : ████████ (10 queries)

  📊 QUERY SOURCE DISTRIBUTION RATIO:
    ⚡ FAQ Matches (Free) : ████████ 24 (41%)
    🤖 AI Model Calls     : ███ 10 (17%)
    🛡️ Rate Limit Shield  :  0 (0%)
  ```

---

### 3. 🚨 AI Chatbot Live Error Log (`ai_chatbot_error_log_display`)
- **What Admin2 Displays on Screen**:
  `No error logs recorded. Plugin operating normally.`
- **Actual Live Data (What it should display)**:
  Real-time system log trace lines recorded in `user/data/ai-chatbot/error.log`.

---

## Why Doesn't the Text Update Live in Admin2?

1. **SvelteKit Strips Inline Scripts**: In classic Grav Admin, we used an inline `<script>` poller in `blueprints.yaml` (`doPoll()`) that updated `<textarea>` fields in the DOM every 2 seconds. Admin2 is built as a SvelteKit SPA, which strips out inline `<script>` tags from form blueprints for security (XSS prevention).
2. **Form State Binding**: Admin2 binds form field state to `user/config/plugins/ai-chatbot.yaml`. Because analytics fields are marked `ignore: true` (so metrics aren't saved into config files on save), Admin2 renders static default strings on screen.

---

## Features Already Implemented in `ai-chatbot`

You don't need to wait for Admin2 frontend updates to access your live analytics and log data. We have already built and enabled full telemetry pipelines directly in `ai-chatbot`:

1. **Live REST Data Export Endpoints** (Implemented & Active):
   - **CSV Spreadsheet Export**: `http://localhost/chatbot-export?format=csv`
   - **JSON Telemetry Dataset**: `http://localhost/chatbot-export?format=json`
   - **Raw Interactions Stream**: `http://localhost/chatbot-export?format=raw_interactions`
2. **Direct File System Loggers** (Implemented & Active):
   - Query logs saved in real time at `user/data/ai-chatbot/interactions.json`.
   - System error logs recorded at `user/data/ai-chatbot/error.log`.
3. **Backend Blueprint Resolver** (Implemented & Active):
   - Subscribed `onApiBlueprintResolved` in `ai-chatbot.php` so all REST API requests dynamically receive calculated query metrics and ASCII graphs in the backend JSON payload.
4. **Dual-Endpoint LLM Failover** (Implemented & Active):
   - Configurable `custom_endpoint` and `fallback_endpoint` failover.

---

## Recommendations for the Grav Admin2 Core Team

Here are suggestions for the `grav-plugin-admin2` developers to help third-party plugins render dynamic live metrics cleanly:

- **Hydrate Defaults from `onApiBlueprintResolved`**: Ensure Svelte form controls bind initial state to the resolved `$event['fields']` default values returned by backend PHP event listeners.
- **Add a Native Polling Field Type (`type: dynamic_metrics`)**: Introduce a native blueprint field type in Admin2 that accepts an API URL and polls it safely within Svelte SPA state, avoiding inline scripts.
- **Web Component UI Slot Registry**: Provide a JavaScript extension API for plugins to register custom Web Components or Svelte components inside admin form tabs.
