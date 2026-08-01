# Admin2 Analytics & Live Error Log Inspection Report

**Plugin**: `grav-plugin-ai-chatbot`  
**Target Panel**: Grav Admin2 (`user/plugins/admin2`)  
**Date**: August 1, 2026  
**Live Browser Audit**: Completed via `/browser` automation suite

---

## Live Browser Inspection vs Expected Data Comparison

When opening the AI Chatbot configuration page inside Admin2 (`http://localhost/admin/plugins/ai-chatbot`), the telemetry form fields display static placeholder text instead of live logged data.

Below is the side-by-side comparison of **what Admin2 rendered on screen** versus **what the fields should have displayed**:

### 1. 📊 Interaction Summary Metrics (`analytics_summary_text`)
- **Rendered Browser Value in Admin2**:
  ```text
  Total Queries: 0 | FAQ Matches: 0 (0% Saved) | AI Calls: 0 | Total Tokens: 0 | Est. Cost: $0.0000
  ```
- **Expected Real Live Data (What it should have displayed)**:
  ```text
  Total Queries: 59 | FAQ Matches: 24 (41% Saved) | AI Calls: 10 | Total Tokens: 4,827 | Est. Cost: $0.0009
  ```

---

### 2. 📈 Visual Interaction Volume & Source Distribution Chart (`analytics_chart_display`)
- **Rendered Browser Value in Admin2**:
  ```text
  📈 DAILY INTERACTION VOLUME:
    (No interaction data logged yet)

  📊 QUERY SOURCE DISTRIBUTION RATIO:
    ⚡ FAQ Matches (Free) : 0 (0%)
    🤖 AI Model Calls     : 0 (0%)
    🛡️ Rate Limit Shield  : 0 (0%)
  ```
- **Expected Real Live Data (What it should have displayed)**:
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
- **Rendered Browser Value in Admin2**:
  ```text
  No error logs recorded. Plugin operating normally.
  ```
- **Expected Real Live Data (What it should have displayed)**:
  ```text
  [2026-08-01 09:01:29 +00:00] [ERROR] [CONFIG_DEBUG] LIVE_CONFIG_DUMP: {"enabled":true,"ai_enabled":true...}
  [2026-08-01 10:36:29 +00:00] [ERROR] [CONFIG_DEBUG] LIVE_CONFIG_DUMP: {"enabled":true,"ai_enabled":true...}
  [2026-08-01 10:42:59 +00:00] [ERROR] [CONFIG_DEBUG] LIVE_CONFIG_DUMP: {"enabled":true,"ai_enabled":true...}
  ```

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

## Summary Table

| Field Name | Rendered in Admin2 Browser | Expected Live Value | Reason for Discrepancy |
| :--- | :--- | :--- | :--- |
| **Summary Metrics** | `Total Queries: 0 ...` | `Total Queries: 59 ...` | SvelteKit SPA strips inline `<script>` poller from blueprints |
| **ASCII Bar Chart** | `(No interaction data)` | `2026-07-29 : ████ (13)...` | SvelteKit SPA strips inline `<script>` poller from blueprints |
| **Live Error Log** | `No error logs recorded` | Recorded log entries | SvelteKit SPA strips inline `<script>` poller from blueprints |
| **JSON Export Endpoints** | ✅ Working (`/chatbot-export`) | ✅ Working | Direct REST route |
