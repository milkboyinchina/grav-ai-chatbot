<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\AiChatbot\ChatbotHandler;
use Grav\Plugin\AiChatbot\AnalyticsReportGenerator;
use Grav\Plugin\AiChatbot\Logger;

/**
 * PSR-4 Autoloader fallback for plugin classes.
 */
spl_autoload_register(function ($class) {
    $prefix = 'Grav\\Plugin\\AiChatbot\\';
    $baseDir = __DIR__ . '/classes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Class AiChatbotPlugin
 * Grav CMS AI Chatbot Plugin entry point.
 *
 * @license GPL-3.0-or-later
 */
class AiChatbotPlugin extends Plugin
{
    /**
     * Helper to reliably resolve plugin enabled state across boolean and array configs.
     */
    protected function isPluginEnabled(): bool
    {
        $pluginConfig = $this->config->get('plugins.ai-chatbot');
        if ($pluginConfig === false) {
            return false;
        }
        if (is_array($pluginConfig) && isset($pluginConfig['enabled'])) {
            return (bool)$pluginConfig['enabled'];
        }
        return true;
    }

    /**
     * Overlay environment secrets onto the plugin configuration.
     *
     * Keeps secrets out of version control: when `plugins.ai-chatbot.api_key`
     * is empty, the value of the `AI_CHATBOT_API_KEY` environment variable
     * (typically defined in `.env`) is used instead. The value remains
     * server-side and is never persisted, logged or exposed to the client.
     */
    protected function applyEnvironmentOverrides(): void
    {
        $configured = trim((string)$this->config->get('plugins.ai-chatbot.api_key', ''));
        if ($configured !== '') {
            // Main key set; still allow a dedicated embedding key from env.
            $this->applyEmbeddingEnvOverride();
            return;
        }

        $envKey = getenv('AI_CHATBOT_API_KEY');
        if ($envKey === false || trim((string)$envKey) === '') {
            $envKey = $_ENV['AI_CHATBOT_API_KEY'] ?? $_SERVER['AI_CHATBOT_API_KEY'] ?? '';
        }

        $envKey = trim((string)$envKey);
        if ($envKey !== '') {
            $this->config->set('plugins.ai-chatbot.api_key', $envKey);
        }

        $this->applyEmbeddingEnvOverride();
    }

    /**
     * Overlay a dedicated embedding secret so chat and embeddings can run
     * on different vendors without storing either key in version control.
     */
    protected function applyEmbeddingEnvOverride(): void
    {
        $configured = trim((string)$this->config->get('plugins.ai-chatbot.rag_embedding_api_key', ''));
        if ($configured !== '') {
            return;
        }

        $envKey = getenv('AI_CHATBOT_EMBEDDING_API_KEY');
        if ($envKey === false || trim((string)$envKey) === '') {
            $envKey = $_ENV['AI_CHATBOT_EMBEDDING_API_KEY'] ?? $_SERVER['AI_CHATBOT_EMBEDDING_API_KEY'] ?? '';
        }

        $envKey = trim((string)$envKey);
        if ($envKey !== '') {
            $this->config->set('plugins.ai-chatbot.rag_embedding_api_key', $envKey);
        }
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 1000],
            'onPageNotFound' => ['onPageNotFound', 1000],
            'onPageInitialized' => ['onPageInitialized', 1000],
            'onOutputGenerated' => ['onOutputGenerated', 0],
            'onApiBlueprintResolved' => ['onApiBlueprintResolved', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiCollectPublicRoutes' => ['onApiCollectPublicRoutes', 0],
            'onPageSaved' => ['onPageSaved', 0],
            'onPageDeleted' => ['onPageDeleted', 0],
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
        ];
    }

    /**
     * Register Grav 2.0 REST API routes (replaces legacy /chatbot-api exit() hack).
     * Legacy /chatbot-api is kept as a BC shim for existing frontend JS (see routeCheck).
     */
    public function onApiRegisterRoutes($event): void
    {
        /** @var \Grav\Plugin\Api\ApiRouteCollector $routes */
        $routes = $event['routes'];
        $controller = \Grav\Plugin\AiChatbot\Controllers\ChatbotApiController::class;

        // Public visitor endpoints (rate-limit + guardrail protected, no login required)
        $routes->post('/ai-chatbot/query', [$controller, 'query']);
        $routes->post('/ai-chatbot/summarize', [$controller, 'summarize']);

        // Admin endpoints (require api.system.read/write via AbstractApiController)
        $routes->get('/ai-chatbot/metrics', [$controller, 'metrics']);
        $routes->get('/ai-chatbot/logs', [$controller, 'logs']);
        $routes->get('/ai-chatbot/security', [$controller, 'security']);
        $routes->post('/ai-chatbot/test-key', [$controller, 'testKey']);
        $routes->post('/ai-chatbot/models', [$controller, 'fetchModels']);
        $routes->post('/ai-chatbot/health', [$controller, 'testHealth']);
        $routes->post('/ai-chatbot/test-embedding', [$controller, 'testEmbedding']);
        $routes->post('/ai-chatbot/reindex', [$controller, 'rebuildIndex']);
        $routes->post('/ai-chatbot/reindex-incremental', [$controller, 'rebuildIndexIncremental']);
        $routes->get('/ai-chatbot/rag-status', [$controller, 'ragStatus']);
        $routes->get('/ai-chatbot/rag-pages', [$controller, 'ragIndexedPages']);
        $routes->post('/ai-chatbot/unlock', [$controller, 'unlock']);
        $routes->post('/ai-chatbot/clear-logs', [$controller, 'clearLogs']);
        $routes->get('/ai-chatbot/export', [$controller, 'export']);
    }

    /**
     * Declare visitor-facing REST endpoints as public (no login). Rate limiting and
     * the security guardrail are enforced inside ChatbotHandler.
     *
     * @param \RocketTheme\Toolbox\Event\Event $event
     */
    public function onApiCollectPublicRoutes($event): void
    {
        $base  = (string)($event['api_base'] ?? '');
        $exact = (array)($event['exact'] ?? []);
        $exact[] = 'POST ' . $base . '/ai-chatbot/query';
        $exact[] = 'POST ' . $base . '/ai-chatbot/summarize';
        $event['exact'] = $exact;
    }

    /**
     * Base path of the plugin's REST endpoints, or '' when the API plugin is unavailable.
     */
    protected function getRestBase(): string
    {
        if (!$this->config->get('plugins.api.enabled', false)) {
            return '';
        }
        $route  = '/' . trim((string)$this->config->get('plugins.api.route', '/api'), '/');
        $prefix = trim((string)$this->config->get('plugins.api.version_prefix', 'v1'), '/');
        $root   = rtrim((string)($this->grav['base_url_relative'] ?? ''), '/');
        return $root . $route . '/' . $prefix . '/ai-chatbot';
    }

    /**
     * Populate the Admin2 recommendations field with live FAQ candidates.
     *
     * @param mixed $event
     */
    public function onApiBlueprintResolved($event): void
    {
        try {
            if (($event['plugin'] ?? '') !== 'ai-chatbot') {
                return;
            }

            $fields = $event['fields'] ?? [];
            if (empty($fields) || !is_array($fields)) {
                return;
            }

            $generator = new AnalyticsReportGenerator($this->grav);
            $data = $generator->getDashboardAnalyticsData();
            $recs = $data['recommendations'] ?? [];

            $recLines = [];
            foreach ($recs as $rec) {
                $recLines[] = "• [{$rec['count']}x asked] Q: {$rec['sample_question']} => A: " . substr($rec['suggested_answer'], 0, 100) . "...";
            }

            $recStr = $recLines !== []
                ? implode("\n\n", $recLines)
                : 'No candidate FAQ recommendations at this time. All interactions logged in user/data/ai-chatbot/interactions.json.';

            $mutateTree = function (array &$tree) use (&$mutateTree, $recStr): void {
                foreach ($tree as $key => &$node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    if ($key === 'analytics_recommendations_text') {
                        $node['default'] = $recStr;
                    }
                    if (isset($node['fields']) && is_array($node['fields'])) {
                        $mutateTree($node['fields']);
                    }
                }
            };

            $mutateTree($fields);
            $event['fields'] = $fields;
        } catch (\Throwable $t) {
            // Admin blueprint enrichment is optional; never break the admin page.
        }
    }

    /**
     * Helper to normalize multilingual routes (e.g. /en, /id, /en/home -> /).
     */
    protected function normalizeRoute(?string $route): string
    {
        if ($route === null) {
            return '/';
        }

        $clean = '/' . ltrim(trim($route), '/');

        // Supported languages in Grav system config
        $supportedLangs = (array)$this->config->get('system.languages.supported', ['en', 'id']);
        foreach ($supportedLangs as $lang) {
            $langPrefix = '/' . trim($lang, '/');
            if ($clean === $langPrefix) {
                return '/';
            }
            if (strpos($clean, $langPrefix . '/') === 0) {
                $clean = substr($clean, strlen($langPrefix));
                break;
            }
        }

        $clean = '/' . ltrim($clean, '/');
        return empty($clean) ? '/' : $clean;
    }

    /**
     * Plugin initialization. Subscribes necessary events.
     */
    public function onPluginsInitialized()
    {
        $this->applyEnvironmentOverrides();

        $this->enable([
            'onApiBlueprintResolved' => ['onApiBlueprintResolved', 0],
        ]);

        if ($this->isAdmin()) {
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onPageInitialized' => ['onPageInitialized', 1000],
                'onPageNotFound' => ['onPageNotFound', 1000],
            ]);
            // Outlined style for blueprint text fields (wrapper_classes marker).
            $this->grav['assets']->addCss('plugin://ai-chatbot/admin-next/css/chatbot-outlined.css');
        } else {
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onPageInitialized' => ['onPageInitialized', 1000],
                'onPageNotFound' => ['onPageNotFound', 1000],
                'onOutputGenerated' => ['onOutputGenerated', 0],
            ]);
        }
    }

    /**
     * Universal Direct Output HTML Injector.
     * Guarantees chatbot widget injection across all themes regardless of whether theme calls assets.js() or not.
     */
    public function onOutputGenerated($event): void
    {
        if ($this->isAdmin()) {
            return;
        }

        if (!$this->isPluginEnabled()) {
            return;
        }

        $output = (string)($event['output'] ?? '');

        // Check if output is HTML and body tag exists
        if (empty($output) || strpos($output, '</body>') === false) {
            return;
        }

        // Avoid duplicate injections
        if (strpos($output, 'grav-ai-chatbot-root') !== false) {
            return;
        }

        // Page Display Visibility Rules
        $rawRoute = $this->grav['uri']->path() ?: '/';
        $currentRoute = $this->normalizeRoute($rawRoute);
        $pageObject = $this->grav['page'] ?? null;
        $pageRoute = ($pageObject && method_exists($pageObject, 'route') && $pageObject->route()) ? $this->normalizeRoute($pageObject->route()) : '';

        $displayMode = $this->config->get('plugins.ai-chatbot.display_mode', 'all');

        if ($displayMode !== 'all') {
            $rawPages = $this->config->get('plugins.ai-chatbot.display_pages', '');
            $pagesList = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $rawPages))));

            $isListed = false;
            foreach ($pagesList as $pRoute) {
                $cleanP = $this->normalizeRoute($pRoute);

                if (
                    $currentRoute === $cleanP ||
                    ($pageRoute && $pageRoute === $cleanP) ||
                    (($currentRoute === '/' || $currentRoute === '/home' || $pageRoute === '/home' || $pageRoute === '/blog') && ($cleanP === '/' || $cleanP === '/home'))
                ) {
                    $isListed = true;
                    break;
                }
            }

            if ($displayMode === 'selected_only' && !$isListed) {
                return;
            }

            if ($displayMode === 'exclude_selected' && $isListed) {
                return;
            }
        }

        // Render Widget Twig Partial
        $twig = $this->grav['twig'];
        $widgetHtml = '';
        try {
            $widgetHtml = $twig->processTemplate('partials/chatbot-widget.html.twig', [
                'config' => $this->config
            ]);
        } catch (\Throwable $t) {}

        try {
            $jsConfig = json_encode([
                'apiEndpoint' => '/chatbot-api',
                'restBase' => $this->getRestBase(),
                'position' => $this->config->get('plugins.ai-chatbot.position', 'bottom-right'),
                'botTitle' => $this->config->get('plugins.ai-chatbot.bot_title', 'Website Assistant'),
                'welcomeMessage' => $this->config->get('plugins.ai-chatbot.welcome_message', 'Hello! How can I help you with this website today?'),
                'customErrorMessage' => $this->config->get('plugins.ai-chatbot.custom_error_message', 'An unexpected connection error occurred. Please try again later.'),
                'accentColor' => $this->config->get('plugins.ai-chatbot.accent_color', '#3b82f6'),
                'themePreset' => $this->config->get('plugins.ai-chatbot.theme_preset', 'glass_blue'),
                'sessionRetentionDays' => (int)$this->config->get('plugins.ai-chatbot.session_retention_days', 7),

                'quickRepliesEnabled' => (bool)$this->config->get('plugins.ai-chatbot.quick_replies_enabled', true),
                'quickReplies' => (array)$this->config->get('plugins.ai-chatbot.quick_replies', []),
                'maxTokens' => (int)$this->config->get('plugins.ai-chatbot.max_tokens', 800),
                'maxInputTokens' => (int)$this->config->get('plugins.ai-chatbot.max_input_tokens', 500),
                'contextWindowTokens' => (int)$this->config->get('plugins.ai-chatbot.context_window_tokens', 8192),
                'currentRoute' => $currentRoute,
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $jsConfig = '{}';
            (new Logger($this->grav))->logError('Failed to encode chatbot widget config: ' . $e->getMessage(), 'WIDGET_CONFIG');
        }

        $baseUrl = rtrim($this->grav['base_url_relative'] ?? '', '/');
        $cssUrl = "{$baseUrl}/user/plugins/ai-chatbot/assets/css/chatbot.css";
        $jsUrl = "{$baseUrl}/user/plugins/ai-chatbot/assets/js/chatbot.js";

        $injection = "
<!-- AI Chatbot Asset & Widget Container -->
<link rel=\"stylesheet\" href=\"{$cssUrl}\">
<script>window.GravChatbotConfig = {$jsConfig};</script>
<script src=\"{$jsUrl}\" defer></script>
{$widgetHtml}
";

        $event['output'] = str_replace('</body>', $injection . "\n</body>", $output);
    }

    /**
     * Intercept custom API routes for Chatbot AJAX (/chatbot-api) and Analytics Exports (/chatbot-export).
     */
    public function onPageNotFound()
    {
        $this->routeCheck();
    }

    public function onPageInitialized()
    {
        $this->routeCheck();
    }

    protected function routeCheck()
    {
        $requestPath = $this->getRequestPath();
        $redirectUrl = (string)($_SERVER['REDIRECT_URL'] ?? '');
        $redirectPath = $redirectUrl !== '' ? (string)(parse_url($redirectUrl, PHP_URL_PATH) ?: '') : '';
        $redirectPath = $redirectPath !== '' ? '/' . trim($redirectPath, '/') : '';

        if ($requestPath === '/chatbot-export' || $redirectPath === '/chatbot-export') {
            $this->handleAnalyticsExport();
            exit();
        }

        if ($requestPath === '/chatbot-api' || $redirectPath === '/chatbot-api') {
            $this->handleChatbotQueryApi();
            exit();
        }
    }

    /**
     * Request path with the configured base path removed, or '' when absent.
     */
    protected function getRequestPath(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '');
        if ($path === '') {
            return '';
        }

        $path = '/' . trim($path, '/');
        $base = '/' . trim((string)($this->grav['base_url_relative'] ?? ''), '/');
        if ($base !== '/' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
            $path = $path === '' ? '/' : $path;
        }

        return $path;
    }

    protected function handleChatbotQueryApi()
    {
        header('Content-Type: application/json');
        header('Deprecation: true');
        header('Sunset: Wed, 31 Dec 2026 23:59:59 GMT');
        header('Warning: 299 - "Endpoint /chatbot-api is deprecated. Migrate to /api/v1/ai-chatbot/query"');
        header('Link: <' . $this->getRestBase() . '/query>; rel="successor-version"');

        // Initialize Grav Pages container so FAQ pages are loaded
        if (isset($this->grav['pages'])) {
            $pages = $this->grav['pages'];
            if (method_exists($pages, 'init')) {
                try {
                    $pages->init();
                } catch (\Throwable $t) {}
            }
        }

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true) ?: $_POST;
        $action = trim($data['action'] ?? $_GET['action'] ?? 'query');

        // BC shim policy: legacy /chatbot-api only serves PUBLIC visitor actions.
        // Admin actions must use /api/v1/ai-chatbot/* with X-API-Token + api.system.* perms.
        $publicActions = ['query', 'force_ai', 'summarize_page'];
        if (!in_array($action, $publicActions, true)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => "Action '{$action}' requires Admin API authentication. Use /api/v1/ai-chatbot/* with X-API-Token.",
            ]);
            exit();
        }

        $cfg = $this->config->get('plugins.ai-chatbot', []);

        $handler = new ChatbotHandler($this->grav, $cfg);
        $response = $handler->processRequest($data);

        $status = (int)($response['http_code'] ?? 200);
        unset($response['http_code']);
        http_response_code($status);
        echo json_encode($response);
        exit();
    }

    /**
     * Inspect Grav user objects to find authenticated admin user.
     * Never starts sessions manually and never trusts raw $_SESSION/$_COOKIE.
     */
    protected function getAuthenticatedAdminUser()
    {
        // 1. Check Grav admin object user
        if (isset($this->grav['admin']) && !empty($this->grav['admin']->user) && !empty($this->grav['admin']->user->authenticated)) {
            return $this->grav['admin']->user;
        }

        // 2. Check Grav core user object
        if (isset($this->grav['user']) && !empty($this->grav['user']->authenticated)) {
            return $this->grav['user'];
        }

        return null;
    }

    /**
     * Handle export download with an administrator permission check.
     * NOTE: preferred path is GET /api/v1/ai-chatbot/export (permission-enforced).
     * This legacy /chatbot-export shim no longer trusts raw cookies/session arrays.
     */
    protected function handleAnalyticsExport()
    {
        $user = $this->getAuthenticatedAdminUser();
        $isAdmin = false;

        if (is_object($user) && !empty($user->authenticated) && method_exists($user, 'authorize')) {
            $isAdmin = $user->authorize('admin.super') || $user->authorize('admin.plugins');
        }

        if (!$isAdmin) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 403,
                'error' => 'Forbidden',
                'message' => 'Access denied. Use /api/v1/ai-chatbot/export with an authenticated admin account.',
            ]);
            exit();
        }

        $format = $_GET['format'] ?? 'csv';
        $generator = new AnalyticsReportGenerator($this->grav);
        $generator->exportReport($format);
        exit();
    }

    /**
     * Register plugin Twig templates directory.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Re-index single page when saved in Grav Admin or CLI.
     */
    public function onPageSaved($event): void
    {
        if (!($this->config->get('plugins.ai-chatbot.rag_indexing_enabled', true))) {
            return;
        }

        $page = $event['page'] ?? null;
        if ($page instanceof \Grav\Common\Page\Page) {
            try {
                $indexer = new \Grav\Plugin\AiChatbot\Rag\Indexer($this->grav, $this->config->toArray());
                $indexer->indexSinglePage($page);
            } catch (\Throwable $t) { (new \Grav\Plugin\AiChatbot\Logger($this->grav))->logError($t->getMessage(), 'RAG_INDEX'); }
        }
    }

    /**
     * Remove deleted page chunks from vector store.
     */
    public function onPageDeleted($event): void
    {
        if (!($this->config->get('plugins.ai-chatbot.rag_indexing_enabled', true))) {
            return;
        }

        $page = $event['page'] ?? null;
        if ($page instanceof \Grav\Common\Page\Page) {
            try {
                $indexer = new \Grav\Plugin\AiChatbot\Rag\Indexer($this->grav, $this->config->toArray());
                $indexer->removePageByRoute($page->route());
            } catch (\Throwable $t) { (new \Grav\Plugin\AiChatbot\Logger($this->grav))->logError($t->getMessage(), 'RAG_INDEX'); }
        }
    }

    /**
     * Register RAG scheduled re-indexing job in Grav CMS Scheduler.
     */
    public function onSchedulerInitialized($event): void
    {
        if (!($this->config->get('plugins.ai-chatbot.rag_indexing_enabled', true)) ||
            !($this->config->get('plugins.ai-chatbot.rag_scheduler_enabled', true))) {
            return;
        }

        try {
            $scheduler = $event['scheduler'] ?? null;
            if (!$scheduler) return;

            $cronExpr = $this->config->get('plugins.ai-chatbot.rag_scheduler_cron', '0 2 * * *');
            $job = $scheduler->addFunction(
                'Grav\Plugin\AiChatbot\Rag\Indexer::reindexAll',
                [$this->grav, $this->config->toArray()],
                'ai-chatbot-rag-reindex'
            );
            $job->at($cronExpr);
            $locator = $this->grav['locator'] ?? null;
            $logBase = $locator ? $locator->findResource('user://data', true) : null;
            $job->output(($logBase ?: 'user/data') . '/ai-chatbot/rag_scheduler.log');
        } catch (\Throwable $t) { (new \Grav\Plugin\AiChatbot\Logger($this->grav))->logError($t->getMessage(), 'RAG_SCHEDULER'); }
    }
}
