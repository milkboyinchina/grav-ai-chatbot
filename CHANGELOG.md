# Changelog

All notable changes to the Grav AI Chatbot plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-07-28

### Added
- Initial release of Grav AI Chatbot plugin.
- Google Gemini API REST v1beta driver.
- OpenAI and OpenRouter Chat Completions REST API drivers.
- Local FAQ pre-matching engine (`FaqResolver.php`) to bypass AI API key usage.
- Multi-tier contact resolution (`ContactPageResolver.php`) supporting public `/contact` and hidden `/hidden-contacts` pages.
- IP-based rate limiter using Grav Cache (`RateLimiter.php`).
- Interaction logging, token cost tracking, and candidate FAQ recommendation engine (`Logger.php`, `FaqRecommender.php`).
- Admin Analytics Dashboard with visual graphs and CSV/JSON export capability (`AnalyticsReportGenerator.php`).
- Floating glassmorphic chatbot widget Twig partial, CSS, and Vanilla JS client.
- GPLv3 License specification.
