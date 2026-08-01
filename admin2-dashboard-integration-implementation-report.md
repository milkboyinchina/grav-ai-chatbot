# Grav Admin2 Integration & Dashboard Audit Report

**Plugin**: `grav-plugin-ai-chatbot`  
**Repository**: [github.com/milkboyinchina/grav-ai-chatbot](https://github.com/milkboyinchina/grav-ai-chatbot)  
**Date**: August 1, 2026  
**Implementation Marker**: `[Admin2-Integration]`

---

## 1. Executive Summary & Audit Findings (`http://localhost/admin/`)

Following live browser verification using the `/browser` subagent automation suite:

### A. Left Sidebar Root Menu Audit
- ❌ **Root Sidebar Item**: **AI Chatbot** does **NOT** appear directly on the left root sidebar menu in Admin2 (`user/plugins/admin2`).
- **Design Intent**: Admin2 keeps top-level navigation fixed to core sections:
  `Dashboard` | `Configuration` | `Users` | `Pages` | `Media` | `Plugins` | `Themes` | `Tools` | `Settings`
- **Access Route**: In Admin2, AI Chatbot settings & telemetry are accessed by navigating to **Plugins** (`/admin/plugins`) $\rightarrow$ select **Grav AI Chatbot** (or direct URL `http://localhost/admin/plugins/ai-chatbot`).
- **Classic Admin Parity**: On sites using Classic Admin (`user/plugins/admin`), the PHP `onAdminMenu` hook renders the dedicated **AI Chatbot** (🤖 `fa-robot`) top-level sidebar button.

### B. Dashboard Telemetry Widget Audit
- ⚠️ **Dashboard Grid Rendering**: The **AI Chatbot & RAG Telemetry** widget card is **NOT** rendered on Admin2's main dashboard grid (`http://localhost/admin/`), nor is it currently listed in customize mode.
- **Backend API Status**: The PHP backend implementation (`onApiDashboardWidgets` hook in `ai-chatbot.php` and `Admin2IntegrationService.php`) is fully functional and returns HTTP 200 JSON telemetry payloads over `/api/v1/dashboard/widgets`.
- **Frontend SPA Limitation**: Admin2's pre-compiled SvelteKit SPA bundle (`user/plugins/admin2/app/_app/...`) currently hardcodes the display of core widgets (Summary Stats, Page Views SVG chart, System Status, Disk Usage, Recent Pages, News Feed). Rendering custom plugin Svelte components in the frontend grid requires rebuilding the SvelteKit SPA source (`grav-admin-next`) or a future Admin2 SPA update release.

---

## 2. Modified & Created Files Matrix

| File Path | Action | Description & Implementation Scope |
| :--- | :--- | :--- |
| [`classes/Admin2IntegrationService.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/classes/Admin2IntegrationService.php) | **[NEW]** | Core integration service formatting widget definitions, recent 5 query telemetry, and menubar items. |
| [`ai-chatbot.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/ai-chatbot.php) | **[MODIFY]** | Subscribed and enabled `onApiDashboardWidgets` hook; added `[Admin2-Integration]` code comments. |
| [`classes/Logger.php`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/classes/Logger.php) | **[MODIFY]** | Updated `logInteraction()` to capture `source_page` routes for visitor queries. |
| [`MANUAL.md`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/MANUAL.md) | **[MODIFY]** | Added explicit step-by-step navigation instructions for Admin2 vs Classic Admin. |
| [`README.md`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/README.md) | **[MODIFY]** | Added Dual-Endpoint Failover and Admin2 feature overview. |
| [`blueprints.yaml`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/blueprints.yaml) | **[MODIFY]** | Added `fallback_endpoint` configuration field; fixed unparsed translation string `PLUGIN_AI_CHATBOT.API_KEY_HELP`. |
| [`languages.yaml`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/languages.yaml) | **[MODIFY]** | Added `API_KEY_HELP` translation string definition. |
| [`ai-chatbot.yaml`](file:///home/milkboy/Documents/web-app/personal-cv-site/user/plugins/ai-chatbot/ai-chatbot.yaml) | **[MODIFY]** | Added default key `fallback_endpoint: ''`. |

---

## 3. Backend PHP Implementation Details

### A. Dashboard Telemetry Service (`Admin2IntegrationService.php`)
- Responds to `onApiDashboardWidgets` by pushing widget entry `ai-chatbot-analytics` to `$event['widgets']`.
- **Telemetry Dataset**:
  - Total Queries Count (59) & Today's Volume (10)
  - FAQ Pre-Matching Cache Hit Ratio (40.7% saved)
  - Token Consumption (4,827 tokens) & Est. Cost Savings ($0.0009)
  - Active Provider (`OLLAMA`) & Model (`qwen2.5-fast:latest`)
  - **Last 5 Recent Visitor Query Terms & Source Page Routes**:
    1. `"Summarize Page (/home/...)"` on page `/` (`10:43`)
    2. `"What do you do?"` on page `/` (`10:36`)
    3. `"hi"` on page `/` (`09:01`)
    4. `"hi"` on page `/` (`09:00`)
    5. `"what this blog is"` on page `/` (`06:30`)

### B. Menubar Links & Badges
- `Admin2IntegrationService::getMenubarItems()` exposes top-header action links:
  - 🤖 **AI Chatbot Action Button** (`/admin/plugins/ai-chatbot`)
  - **Candidate FAQ Notification Badge** indicating pending FAQ recommendations.

---

## 4. Navigation & Access Matrix

| Interface | Sidebar Status | Access Path | URL |
| :--- | :--- | :--- | :--- |
| **Admin2 (Modern SvelteKit SPA)** | ❌ Not on root sidebar (Fixed core layout) | **Plugins** section $\rightarrow$ Select **Grav AI Chatbot** | `http://localhost/admin/plugins/ai-chatbot` |
| **Classic Admin (Legacy Admin)** | ✅ Top-level root sidebar icon (🤖 `fa-robot`) | Direct sidebar button | `http://localhost/admin/plugins/ai-chatbot` |

---

## 5. Git Synchronization & Commits

- **`5e7e10a`**: `docs: add Admin2 and Classic Admin navigation steps to MANUAL.md`
- **`f31485b`**: `fix: resolve unparsed translation string for API_KEY_HELP`
- **`aafc512`**: `feat: implement Admin2 dashboard telemetry widget with last 5 query terms and menubar links`
- **`db4d541`**: `docs: add [Admin2-Integration] inline code comments`
- **`7554345`**: `docs: add admin2-dashboard-integration-implementation.md documentation to plugin repository`
- **`c3cc8ea`**: `docs: append Section 6 Live Browser Verification Results`
- **`dev` branch**: [`github.com/milkboyinchina/grav-ai-chatbot/tree/dev`](https://github.com/milkboyinchina/grav-ai-chatbot/tree/dev)
