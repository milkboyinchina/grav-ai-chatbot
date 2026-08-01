# Admin2 Analytics & Live Error Log Inspection Report

**Plugin**: `grav-plugin-ai-chatbot`  
**Target Panel**: Grav Admin2 (`user/plugins/admin2`)  
**Date**: August 1, 2026  
**Live Browser Audit**: Completed via `/browser` automation suite

---

## Live Browser Inspection Results (`http://localhost/admin/plugins/ai-chatbot`)

When opening the AI Chatbot configuration page inside Admin2 in a live browser session, the three telemetry fields display their static default fallback strings:

1. **📊 Interaction Summary Metrics**:
   - **Rendered Browser Value**: `"Total Queries: 0 | FAQ Matches: 0 (0% Saved) | AI Calls: 0 | Total Tokens: 0 | Est. Cost: $0.0000"`

2. **📈 Visual Interaction Volume & Source Distribution Chart**:
   - **Rendered Browser Value**:
     ```text
     📈 DAILY INTERACTION VOLUME:
       (No interaction data logged yet)

     📊 QUERY SOURCE DISTRIBUTION RATIO:
       ⚡ FAQ Matches (Free) : 0 (0%)
       🤖 AI Model Calls     : 0 (0%)
       🛡️ Rate Limit Shield  : 0 (0%)
     ```

3. **🚨 AI Chatbot Live Error Log**:
   - **Rendered Browser Value**: `"No error logs recorded. Plugin operating normally."`

---

## Why the Data Doesn't Live-Update in Admin2 Form Fields

### 1. Client-Side Script Stripping (XSS Protection)
In Classic Admin (`user/plugins/admin`), an inline `<script>` tag inside `blueprints.yaml` ran a background JavaScript loop (`doPoll()`) every 2 seconds. That script queried `/chatbot-api` and directly forced DOM updates on `<textarea>` fields using `document.querySelector()`.

Admin2 is built as a decoupled SvelteKit Single Page Application (SPA). When Admin2 receives form blueprint definitions from the backend, SvelteKit automatically sanitizes HTML content and **strips inline `<script>` blocks** for security. Because SvelteKit blocks the inline poller script from running, client-side field updates do not execute in the browser.

### 2. Form Field Data Binding Model
Admin2's Svelte components bind form inputs to settings stored in `user/config/plugins/ai-chatbot.yaml` via `/api/v1/config/plugins/ai-chatbot`. Because analytics fields are marked `ignore: true` (so metrics aren't written into configuration files on save), Admin2 initializes their input values from the static fallback defaults defined in `blueprints.yaml`.

---

## Where Live Data IS Working & How to Access It

While the embedded form textareas inside Admin2's settings page remain static due to SPA sanitization, all telemetry logging and data export pipelines are active and up to date:

1. **Raw Interactions File**: Saved continuously in `user/data/ai-chatbot/interactions.json` (contains 59 logged queries, FAQ hit counts, token usage, and source page routes).
2. **System Error Logs**: Logged in real time at `user/data/ai-chatbot/error.log`.
3. **Live Export Endpoints**:
   - CSV Spreadsheet: `http://localhost/chatbot-export?format=csv`
   - JSON Telemetry: `http://localhost/chatbot-export?format=json`
   - Raw Interactions: `http://localhost/chatbot-export?format=raw_interactions`

---

## Summary of Findings

| Feature / Field | Classic Admin (`user/plugins/admin`) | Modern Admin2 (`user/plugins/admin2`) | Reason |
| :--- | :--- | :--- | :--- |
| **Form Field Polling** | ✅ Updates live via `<script>` | ❌ Shows default text | SvelteKit strips inline `<script>` tags from form blueprints |
| **JSON Export Endpoints** | ✅ Working | ✅ Working | Independent REST route |
| **Interactions Log File** | ✅ Saved | ✅ Saved | File-system logger in PHP |
| **Error Log File** | ✅ Saved | ✅ Saved | File-system logger in PHP |
