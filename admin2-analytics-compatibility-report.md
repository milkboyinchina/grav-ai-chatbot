# Admin2 Compatibility Technical Report: Live Analytics, Error Logs & Metrics Data Updating Behavior

**Plugin**: `grav-plugin-ai-chatbot`  
**Target Admin**: Grav Admin2 (`user/plugins/admin2`)  
**Date**: August 1, 2026  
**Status**: Investigated & Diagnosed

---

## 1. Executive Summary

When viewing the AI Chatbot configuration page in **Admin2** (`user/plugins/admin2`), the following 3 fields appear frozen on default strings:
- 🚨 **AI Chatbot Live Error Log** (`ai_chatbot_error_log_display`)
- 📊 **Interaction Summary Metrics** (`analytics_summary_text`)
- 📈 **Visual Interaction Volume & Source Distribution Chart** (`analytics_chart_display`)

**Is Admin2 the reason data won't update?**  
**YES.** Admin2's decoupled SvelteKit Single Page Application (SPA) architecture changes how blueprint form fields and client-side JavaScript execute compared to Classic Admin (`user/plugins/admin`).

---

## 2. Technical Root Cause Analysis

### A. SvelteKit SPA DOM Sanitization (Inline Script Stripping)
- **Classic Admin Behavior**: In Classic Admin, blueprint form fields rendered raw Twig HTML into the DOM. The plugin embedded an inline `<script>` tag inside `blueprints.yaml` (`content: '<script>(function(){ doPoll()... })()</script>'`) that ran a 2-second background polling loop, fetching data from `/chatbot-api` and directly mutating DOM `<textarea>` elements via `document.querySelector()`.
- **Admin2 SPA Behavior**: Admin2 fetches form blueprints over JSON API (`GET /api/v1/blueprints/plugins/ai-chatbot`) and renders them using Svelte components. To prevent XSS vulnerabilities, SvelteKit **sanitizes all HTML strings and ignores inline `<script>` tags** embedded in YAML blueprint content. As a result, the polling loop never starts in Admin2.

### B. Blueprint Event Resolution Scope
- In Classic Admin, dynamic field values were injected via PHP event `onBlueprintCreated`.
- In Admin2, blueprints are resolved via `grav-plugin-api` event `onApiBlueprintResolved` (`GET /api/v1/blueprints/plugins/*`). Without handling `onApiBlueprintResolved`, Admin2 serves raw static YAML blueprint default values.

---

## 3. Compatibility & Resolution Strategy

To ensure real-time analytics and log updates work seamlessly in **both** Classic Admin and Modern Admin2:

### Strategy 1: Server-Side Blueprint Event Hook (`onApiBlueprintResolved`)
By subscribing `onApiBlueprintResolved` in `ai-chatbot.php`, the PHP backend dynamically calculates and injects live error logs, summary metrics, and visual ASCII charts into the blueprint fields whenever Admin2 loads `/api/v1/blueprints/plugins/ai-chatbot`.

### Strategy 2: Native Admin2 Telemetry Dashboard Widget (`ai-chatbot-analytics`)
Admin2 users get live telemetry data via the newly implemented **`ai-chatbot-analytics`** widget (`onApiDashboardWidgets`), which streams live query volume, FAQ hit ratios, token cost savings, and the **last 5 visitor queries with source page URIs** over `/api/v1/dashboard/widgets`.

---

## 4. Implementation Fix

1. **`ai-chatbot.php`**: Enable `onApiBlueprintResolved` event hook to populate real-time analytics data for Admin2 JSON blueprint requests.
2. **`Admin2IntegrationService.php`**: Serve real-time telemetry metrics via the native Admin2 widget API.

---

## 5. Verification & Git Commit

- **Documentation**: Report committed as `admin2-analytics-compatibility-report.md`.
- **Repository**: [`github.com/milkboyinchina/grav-ai-chatbot`](https://github.com/milkboyinchina/grav-ai-chatbot)
