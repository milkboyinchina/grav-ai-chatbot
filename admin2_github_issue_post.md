# Proposed GitHub Issue Post for `getgrav/grav-plugin-admin2`

Below is a personally written draft for your GitHub issue post. You can copy and paste this directly into the issue submission page at [https://github.com/getgrav/grav-plugin-admin2/issues/new](https://github.com/getgrav/grav-plugin-admin2/issues/new).

---

### **Issue Title**:
```text
[Question/RFC] Blueprint form fields with dynamic defaults or polling scripts render static text in Admin2
```

---

### **Issue Description / Body**:

```markdown
Hi Grav & Admin2 Team 👋

First of all, thank you so much for your hard work on Grav CMS and the modern Admin2 SvelteKit panel!

I am testing my custom plugin (`grav-plugin-ai-chatbot`) under **Grav Admin2 (v2.0.17)** on Grav v2.0.12. In the classic Grav Admin panel, I used a small inline `<script>` poller in `blueprints.yaml` to update live metrics textareas (query volume, token cost estimates, and error log feeds).

When viewing the plugin configuration page in Admin2, these fields stay frozen on their initial YAML placeholder default text (`Total Queries: 0 | FAQ Matches: 0...`).

### Summary of What I Observed:
1. **Script Sanitization**: Admin2's SvelteKit SPA sanitizes HTML and strips inline `<script>` tags from form blueprint content for security (XSS protection), so client-side JavaScript polling loops don't execute.
2. **Form Default Hydration**: When Admin2 fetches blueprints over `/api/v1/blueprints/plugins/...`, Svelte form fields bind to YAML config values rather than dynamically resolved `$event['fields']` default values from backend PHP listeners (`onApiBlueprintResolved`).

I compiled a detailed inspection report with environment details and screenshots here:
🔗 **[Admin2 Analytics & Live Log Compatibility Report](https://github.com/milkboyinchina/grav-ai-chatbot/blob/dev/admin2-analytics-compatibility-report.md)**

### Apology & Disclaimer:
Please accept my apologies if I misunderstood anything or if my technical analysis is off! I have very little experience building SvelteKit SPAs or working with Svelte reactivity, so my assumptions about the frontend rendering pipeline might be incomplete or sub-optimal.

I wanted to ask if there is a recommended or planned way in Admin2 for third-party plugins to present reactive live telemetry data or dynamic form fields without relying on inline scripts.

Thank you again for all your amazing work on Grav!
```
