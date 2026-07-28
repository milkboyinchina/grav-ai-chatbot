# Grav CMS AI Chatbot Plugin (`grav-plugin-ai-chatbot`)

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Grav CMS](https://img.shields.io/badge/Grav-1.7%2B-orange.svg)](https://getgrav.org)

An intelligent, native **Grav CMS AI Chatbot Plugin** offering dual-engine AI support (Google Gemini & OpenAI/OpenRouter), local FAQ pre-matching (saving AI API keys), multi-tier contact resolution, strict website content scoping, rate-limiting, and an interactive Admin Analytics Dashboard.

---

## 🌟 Key Features

- 🤖 **Dual AI Engines**: Compatible out-of-the-box with **Google Gemini API** (`gemini-1.5-flash`) and **OpenAI-compatible endpoints** (**OpenRouter**, OpenAI, Groq, custom).
- ⚡ **Local FAQ Pre-Matching Engine**: Pre-matches visitor questions against your `/faq` page. Matching questions return instant answers with **0 AI API calls**, saving quota.
- 📞 **Multi-Tier Contact Sourcing**: Reads public `/contact` for standard inquiries and hidden/unpublished `/hidden-contacts` for specialized engineering/technical contact requests.
- 🛡️ **Strict Website Scope & Rate Limiting**: Restricts answers exclusively to website content; rejects external URLs. Includes IP-based rate limiting to prevent spam.
- 📊 **Analytics Dashboard & Reports**: Visual graphs in Grav Admin tracking daily query volume, FAQ vs AI hit ratio, token consumption, and CSV/JSON export.
- 💡 **Automated FAQ Recommendations**: Analyzes visitor queries to suggest candidate FAQ additions.
- 📝 **Page Summarization**: Includes quick action button to summarize the current active page.

---

## 🚀 Installation & Setup

### Manual Installation
1. Clone or extract this repository into your Grav plugins directory:
   ```bash
   cd user/plugins
   git clone https://github.com/milkboyinchina/grav-ai-chatbot.git ai-chatbot
   ```
2. Enable the plugin in your Grav Admin Panel under **Plugins -> Grav AI Chatbot**.
3. Select your AI Provider (Gemini / OpenRouter / OpenAI) and enter your API Key.

---

## 📄 License

This project is licensed under the [GNU General Public License v3.0 (GPLv3)](LICENSE).
