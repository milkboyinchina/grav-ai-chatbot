# AGENTS.md: Grav CMS AI Chatbot Plugin (`ai-chatbot`)

> Autonomous Git subproject: this directory is an independent repository. Binding rules live at the site root: `.agents/rules/ai-chatbot-boundaries.md` (scope, git, secrets) and `.agents/rules/ai-chatbot-architecture.md` (pipeline, touchpoints, invariants). This file only points at them.

## 1. Read first
1. `.agents/rules/ai-chatbot-boundaries.md` (binding).
2. `.agents/rules/ai-chatbot-architecture.md` (touchpoints, action registry, invariants).
3. Skill `ai-chatbot-dev` (runbooks + verification chain), including `references/known-debt.md` and `references/api-ground-truth.md`.
4. `.agents/rules/grav-2.0.md` and skill `grav-cms-api-plugin` for Grav 2.0 API/Admin2 standards.
5. Plugin docs when relevant: `DEVELOPER.md`, `HOWITWORKS.md`, `MANUAL.md`, `SECURITY.md`.

## 2. Critical directives (details in the rules above)
- **Scope / tone / secrets**: edit only this directory (plus `.agents/` when the task is agent guidance); accurate engineer, no humor in code, comments, docs or commits; never expose `api_key`; never commit or print `user/data/ai-chatbot/*`.
- **Git**: branch `dev`, Conventional Commits; no commit, push, tag or force-push unless asked; never push `main` without permission. Check `git status --short` before editing and leave the user's pre-existing changes untouched.
- **Version**: `blueprints.yaml` `version:` is canonical; sync `CHANGELOG.md` and tag `v<version>`. `composer.json` intentionally has no `version`.
- **PHP / Grav 2.0**: PHP 8.3+, `declare(strict_types=1);` in new files, typed properties; REST controllers extend `AbstractApiController`; Admin2 fields are Web Components with Twig fallbacks; blueprints carry no inline multi-line JS.

## 3. Commands (run from the site root)
```bash
make clear-cache        # after any template, config, blueprint or plugin edit
make index-rag          # incremental RAG indexing
make index-rag-rebuild  # full rebuild (ask first if embeddings are remote)
docker exec grav-lamp-web php -l user/plugins/ai-chatbot/<file>.php
docker exec grav-lamp-web vendor/bin/yaml-lint user/plugins/ai-chatbot/<file>.yaml
```
Full verification chain: skill `ai-chatbot-dev`.

## 4. Structure
```text
user/plugins/ai-chatbot/
├── admin-next/fields/      # Admin2 Web Components (chatbot-*.js)
├── ai-chatbot.php          # Event hooks, route registration, legacy shim
├── ai-chatbot.yaml         # Default configuration
├── blueprints.yaml         # Admin form + plugin version
├── classes/                # Handler, guardrail, FAQ, AI clients, Logger, analytics
│   ├── Controllers/        # ChatbotApiController (REST)
│   └── Rag/                # Chunker, Indexer, VectorStore, Retriever, EmbeddingProvider
├── cli/                    # index-rag command
├── assets/                 # Widget JS/CSS; Classic Admin scripts
├── templates/              # Widget partial, Classic Admin fallbacks (forms/fields/*)
└── .task/                  # Historical notes, non-authoritative
```
