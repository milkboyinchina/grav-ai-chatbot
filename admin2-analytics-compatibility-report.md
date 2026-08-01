# Admin2 Analytics & Live Error Log Updating Behavior

**Plugin**: `grav-plugin-ai-chatbot`  
**Target Panel**: Grav Admin2 (`user/plugins/admin2`)  
**Date**: August 1, 2026  

---

## What Was Happening?

When opening the AI Chatbot settings page inside Grav Admin2 (`user/plugins/admin2`), three specific fields looked stuck on their default placeholder text:

1. **AI Chatbot Live Error Log** (`ai_chatbot_error_log_display`)
2. **Interaction Summary Metrics** (`analytics_summary_text`)
3. **Visual Interaction Volume & Source Distribution Chart** (`analytics_chart_display`)

---

## Why Didn't the Fields Update in Admin2?

### 1. SvelteKit Strips Inline Scripts
In the classic Grav Admin panel, form blueprints rendered as raw HTML template files. We used an inline `<script>` tag inside `blueprints.yaml` to poll `/chatbot-api` every two seconds and update `<textarea>` boxes directly in the browser DOM.

Admin2 is built differently—it's a modern SvelteKit Single Page Application (SPA). When Admin2 fetches form blueprints from `/api/v1/blueprints/plugins/ai-chatbot`, SvelteKit automatically sanitizes HTML strings and ignores embedded `<script>` blocks to prevent XSS security issues. Because the inline script was stripped out by SvelteKit, the polling loop never started.

### 2. Event Listener Scope
Classic Grav Admin relied on the `onBlueprintCreated` PHP event hook to inject initial dynamic values into form fields. Admin2 uses the API plugin's `onApiBlueprintResolved` event when delivering blueprints over JSON. Without subscribing to this event, Admin2 would fall back to the plain text default strings defined in YAML.

---

## How We Fixed It

We updated `ai-chatbot.php` to subscribe to `onApiBlueprintResolved` alongside `onBlueprintCreated`.

Now, whenever Admin2 requests the plugin's blueprint options via `/api/v1/blueprints/plugins/ai-chatbot`, our PHP backend automatically calculates the latest query totals, FAQ match ratios, estimated token costs, ASCII volume charts, and error log entries on the fly.

This approach works reliably across both **Admin2** and **Classic Admin** without depending on client-side DOM manipulation scripts.

---

## Summary of Changes

- **`ai-chatbot.php`**: Added `onApiBlueprintResolved` event listener to supply dynamic field values directly to Admin2 API blueprint requests.
- **`languages.yaml` & `blueprints.yaml`**: Cleaned up blueprint key labels for smooth rendering.
