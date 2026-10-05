<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\File\CompiledYamlFile;
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
            'onBlueprintCreated' => ['onBlueprintCreated', 0],
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
        $routes->post('/ai-chatbot/reindex', [$controller, 'rebuildIndex']);
        $routes->post('/ai-chatbot/unlock', [$controller, 'unlock']);
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
     * Populate blueprint fields dynamically with live analytics metrics, visual bar charts, error logs, and export URLs.
     */
    public function onBlueprintCreated($event)
    {
        $blueprint = $event['blueprint'];
        if ($blueprint->getFilename() === 'ai-chatbot') {
            try {
                $logger = new Logger($this->grav);
                $generator = new AnalyticsReportGenerator($this->grav);
                $data = $generator->getDashboardAnalyticsData();

                $summary = $data['summary'] ?? [];
                $totalQueries = $summary['total_queries'] ?? 0;
                $faqHits = $summary['faq_hits'] ?? 0;
                $aiHits = $summary['ai_hits'] ?? 0;
                $rateHits = $summary['rate_limit_hits'] ?? 0;
                $totalTokens = number_format($summary['total_tokens'] ?? 0);
                $totalCost = number_format($summary['total_cost_usd'] ?? 0, 4);

                $faqPct = $totalQueries > 0 ? round(($faqHits / $totalQueries) * 100) : 0;
                $aiPct = $totalQueries > 0 ? round(($aiHits / $totalQueries) * 100) : 0;
                $ratePct = $totalQueries > 0 ? round(($rateHits / $totalQueries) * 100) : 0;

                $summaryStr = "Total Queries: {$totalQueries} | FAQ Matches: {$faqHits} ({$faqPct}% Saved) | AI Calls: {$aiHits} | Total Tokens: {$totalTokens} | Est. Cost: \${$totalCost}";

                // Build Visual ASCII/Unicode Bar Chart
                $chartLines = ["DAILY INTERACTION VOLUME:"];
                $dailyLabels = $data['daily_chart']['labels'] ?? [];
                $dailyValues = $data['daily_chart']['values'] ?? [];
                $maxDaily = max(1, ...($dailyValues ?: [1]));

                if (empty($dailyLabels)) {
                    $chartLines[] = "  (No interaction data logged yet)";
                } else {
                    $slicedLabels = count($dailyLabels) > 25 ? array_slice($dailyLabels, -25) : $dailyLabels;
                    $slicedValues = count($dailyValues) > 25 ? array_slice($dailyValues, -25) : $dailyValues;

                    foreach ($slicedLabels as $idx => $lbl) {
                        $val = $slicedValues[$idx] ?? 0;
                        $barLen = (int)round(($val / $maxDaily) * 20);
                        $barStr = str_repeat('█', max(1, $barLen));
                        $chartLines[] = sprintf("  %s : %s (%d queries)", $lbl, $barStr, $val);
                    }
                }

                $chartLines[] = "";
                $chartLines[] = "QUERY SOURCE DISTRIBUTION RATIO:";
                $chartLines[] = sprintf("  FAQ Matches (Free) : %s %d (%d%%)", str_repeat('█', (int)round(($faqPct / 100) * 20)), $faqHits, $faqPct);
                $chartLines[] = sprintf("  AI Model Calls     : %s %d (%d%%)", str_repeat('█', (int)round(($aiPct / 100) * 20)), $aiHits, $aiPct);
                $chartLines[] = sprintf("  Rate Limit Shield  : %s %d (%d%%)", str_repeat('█', (int)round(($ratePct / 100) * 20)), $rateHits, $ratePct);

                $chartStr = implode("\n", $chartLines);

                // Read site URL from site.yaml or relative root
                $siteUrl = rtrim($this->config->get('site.url') ?: $this->grav['uri']->rootUrl(true), '/');
                if (empty($siteUrl) || $siteUrl === '/' || str_contains($siteUrl, 'localhost')) {
                    $csvUrl = "/chatbot-export?format=csv";
                    $jsonUrl = "/chatbot-export?format=json";
                    $rawUrl = "/chatbot-export?format=raw_interactions";
                } else {
                    $csvUrl = "{$siteUrl}/chatbot-export?format=csv";
                    $jsonUrl = "{$siteUrl}/chatbot-export?format=json";
                    $rawUrl = "{$siteUrl}/chatbot-export?format=raw_interactions";
                }

                // Log file location instructions
                $locator = $this->grav['locator'];
                $absLogPath = $locator->findResource('user://data') . '/ai-chatbot/interactions.json';
                $fileInstStr = "Relative Path: user/data/ai-chatbot/interactions.json\nAbsolute Path: {$absLogPath}\n\nManual Data Editing & Deleting Instructions:\n• To edit, prune, or delete individual interaction entries, open 'user/data/ai-chatbot/interactions.json' in any text editor.\n• To reset or delete ALL telemetry records, clear the file content to '[]' or delete the file. The plugin will automatically recreate an empty log file on the next visitor query.";

                // Recommendations
                $recs = $data['recommendations'] ?? [];
                $recLines = [];
                if (!empty($recs)) {
                    foreach ($recs as $rec) {
                        $recLines[] = "• [{$rec['count']}x asked] Q: {$rec['sample_question']} => A: " . substr($rec['suggested_answer'], 0, 100) . "...";
                    }
                    $recStr = implode("\n\n", $recLines);
                } else {
                    $recStr = "No candidate FAQ recommendations at this time. All interactions logged in user/data/ai-chatbot/interactions.json.";
                }

                $inPrice = $this->config->get('plugins.ai-chatbot.cost_input_token_price_per_m', '0.15');
                $outPrice = $this->config->get('plugins.ai-chatbot.cost_output_token_price_per_m', '0.60');

                $exampleDisclaimer = "Provider Token Pricing Example (Google Gemini 1.5 Flash):\n• Input / Prompt Tokens: \${$inPrice} per 1,000,000 tokens ($0.00015 / 1k)\n• Output / Completion Tokens: \${$outPrice} per 1,000,000 tokens ($0.00060 / 1k)\n\nToken Cost Estimation Warning:\nEstimated API cost = (Prompt Tokens / 1,000,000 × \${$inPrice}) + (Completion Tokens / 1,000,000 × \${$outPrice}).\nPlease note that token cost estimates are approximations for general guidance. Actual billing may vary depending on model pricing updates, system prompt caching, image inputs, or free tier credits. Please refer to your AI provider's official dashboard for exact billing statements.";

                $errLogPath = $logger->getErrorLogFilePath();
                $errInstStr = "Relative Path: user/data/ai-chatbot/error.log\nAbsolute Path: {$errLogPath}\n\nManual Error Log Editing & Deleting Instructions:\n• To view, edit, or prune error log entries, open 'user/data/ai-chatbot/error.log' in any text editor.\n• To clear all error logs, delete the file or empty its contents. The plugin will automatically recreate an empty log file when new errors occur.";

                $errorLogs = $logger->getErrorLogs();

                // Set both tabbed path and direct path for Admin2 / Classic Admin compatibility
                $blueprint->set('form.fields.tabs.fields.section_analytics.fields.analytics_summary_text.default', $summaryStr);
                $blueprint->set('form.fields.tabs.fields.section_analytics.fields.analytics_chart_display.default', $chartStr);
                $blueprint->set('form.fields.tabs.fields.section_analytics.fields.download_csv_link.default', $csvUrl);
                $blueprint->set('form.fields.tabs.fields.section_analytics.fields.download_json_link.default', $jsonUrl);
                $blueprint->set('form.fields.tabs.fields.section_analytics.fields.download_raw_link.default', $rawUrl);
                $blueprint->set('form.fields.tabs.fields.section_analytics.fields.analytics_data_file_location.default', $fileInstStr);
                $blueprint->set('form.fields.tabs.fields.section_analytics.fields.analytics_recommendations_text.default', $recStr);

                $blueprint->set('form.fields.tabs.fields.section_logging.fields.cost_estimation_example.default', $exampleDisclaimer);
                $blueprint->set('form.fields.tabs.fields.section_logging.fields.ai_chatbot_error_log_display.default', $errorLogs);
                $blueprint->set('form.fields.tabs.fields.section_logging.fields.ai_chatbot_error_log_location.default', $errInstStr);

                // Direct fallback paths
                $blueprint->set('form.fields.section_analytics.fields.analytics_summary_text.default', $summaryStr);
                $blueprint->set('form.fields.section_analytics.fields.analytics_chart_display.default', $chartStr);
                $blueprint->set('form.fields.section_analytics.fields.download_csv_link.default', $csvUrl);
                $blueprint->set('form.fields.section_analytics.fields.download_json_link.default', $jsonUrl);
                $blueprint->set('form.fields.section_analytics.fields.download_raw_link.default', $rawUrl);
                $blueprint->set('form.fields.section_analytics.fields.analytics_data_file_location.default', $fileInstStr);
                $blueprint->set('form.fields.section_analytics.fields.analytics_recommendations_text.default', $recStr);
                $blueprint->set('form.fields.section_logging.fields.cost_estimation_example.default', $exampleDisclaimer);
                $blueprint->set('form.fields.section_logging.fields.ai_chatbot_error_log_display.default', $errorLogs);
                $blueprint->set('form.fields.section_logging.fields.ai_chatbot_error_log_location.default', $errInstStr);
                $blueprint->set('form.fields.ai_chatbot_error_log_display.default', $errorLogs);
                $blueprint->set('form.fields.ai_chatbot_error_log_location.default', $errInstStr);
            } catch (\Throwable $e) {
                // Ignore gracefully
            }
        }
    }

    /**
     * Populate Admin2 API blueprint fields dynamically with live analytics metrics, visual bar charts, error logs, and export URLs.
     *
     * @param mixed $event
     */
    public function onApiBlueprintResolved($event): void
    {
        try {
            $plugin = $event['plugin'] ?? '';
            if ($plugin !== 'ai-chatbot') {
                return;
            }

            $fields = $event['fields'] ?? [];
            if (empty($fields) || !is_array($fields)) {
                return;
            }

            $logger = new Logger($this->grav);
            $generator = new AnalyticsReportGenerator($this->grav);
            $data = $generator->getDashboardAnalyticsData();

            $summary = $data['summary'] ?? [];
            $totalQueries = $summary['total_queries'] ?? 0;
            $faqHits = $summary['faq_hits'] ?? 0;
            $aiHits = $summary['ai_hits'] ?? 0;
            $rateHits = $summary['rate_limit_hits'] ?? 0;
            $totalTokens = number_format($summary['total_tokens'] ?? 0);
            $totalCost = number_format($summary['total_cost_usd'] ?? 0, 4);

            $faqPct = $totalQueries > 0 ? round(($faqHits / $totalQueries) * 100) : 0;
            $aiPct = $totalQueries > 0 ? round(($aiHits / $totalQueries) * 100) : 0;
            $ratePct = $totalQueries > 0 ? round(($rateHits / $totalQueries) * 100) : 0;

            $summaryStr = "Total Queries: {$totalQueries} | FAQ Matches: {$faqHits} ({$faqPct}% Saved) | AI Calls: {$aiHits} | Total Tokens: {$totalTokens} | Est. Cost: \${$totalCost}";

            // Build Visual ASCII Bar Chart
            $chartLines = ["DAILY INTERACTION VOLUME:"];
            $dailyLabels = $data['daily_chart']['labels'] ?? [];
            $dailyValues = $data['daily_chart']['values'] ?? [];
            $maxDaily = max(1, ...($dailyValues ?: [1]));

            if (empty($dailyLabels)) {
                $chartLines[] = "  (No interaction data logged yet)";
            } else {
                $slicedLabels = count($dailyLabels) > 25 ? array_slice($dailyLabels, -25) : $dailyLabels;
                $slicedValues = count($dailyValues) > 25 ? array_slice($dailyValues, -25) : $dailyValues;

                foreach ($slicedLabels as $idx => $lbl) {
                    $val = $slicedValues[$idx] ?? 0;
                    $barLen = (int)round(($val / $maxDaily) * 20);
                    $barStr = str_repeat('█', max(1, $barLen));
                    $chartLines[] = sprintf("  %s : %s (%d queries)", $lbl, $barStr, $val);
                }
            }

            $chartLines[] = "";
            $chartLines[] = "QUERY SOURCE DISTRIBUTION RATIO:";
            $chartLines[] = sprintf("  FAQ Matches (Free) : %s %d (%d%%)", str_repeat('█', (int)round(($faqPct / 100) * 20)), $faqHits, $faqPct);
            $chartLines[] = sprintf("  AI Model Calls     : %s %d (%d%%)", str_repeat('█', (int)round(($aiPct / 100) * 20)), $aiHits, $aiPct);
            $chartLines[] = sprintf("  Rate Limit Shield  : %s %d (%d%%)", str_repeat('█', (int)round(($ratePct / 100) * 20)), $rateHits, $ratePct);

            $chartStr = implode("\n", $chartLines);

            // Recommendations
            $recs = $data['recommendations'] ?? [];
            $recLines = [];
            if (!empty($recs)) {
                foreach ($recs as $rec) {
                    $recLines[] = "• [{$rec['count']}x asked] Q: {$rec['sample_question']} => A: " . substr($rec['suggested_answer'], 0, 100) . "...";
                }
                $recStr = implode("\n\n", $recLines);
            } else {
                $recStr = "No candidate FAQ recommendations at this time. All interactions logged in user/data/ai-chatbot/interactions.json.";
            }

            $errorLogs = $logger->getErrorLogs();

            // Helper lambda to mutate default values in field tree
            $mutateTree = function (&$tree) use (&$mutateTree, $summaryStr, $chartStr, $recStr, $errorLogs) {
                foreach ($tree as $key => &$node) {
                    if (is_array($node)) {
                        if ($key === 'analytics_summary_text') {
                            $node['default'] = $summaryStr;
                        } elseif ($key === 'analytics_chart_display') {
                            $node['default'] = $chartStr;
                        } elseif ($key === 'analytics_recommendations_text') {
                            $node['default'] = $recStr;
                        } elseif ($key === 'ai_chatbot_error_log_display') {
                            $node['default'] = $errorLogs;
                        }
                        if (isset($node['fields']) && is_array($node['fields'])) {
                            $mutateTree($node['fields']);
                        }
                    }
                }
            };

            $mutateTree($fields);
            $event['fields'] = $fields;
        } catch (\Throwable $t) {}
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
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
                'onAdminTwigSiteVariables' => ['onAdminTwigSiteVariables', 0],
                'onPageInitialized' => ['onPageInitialized', 1000],
                'onPageNotFound' => ['onPageNotFound', 1000],
                'onBlueprintCreated' => ['onBlueprintCreated', 0],
            ]);
        } else {
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
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
    public function onOutputGenerated()
    {
        if ($this->isAdmin()) {
            return;
        }

        if (!$this->isPluginEnabled()) {
            return;
        }

        $output = $this->grav['output'];

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
            'notificationEnabled' => (bool)$this->config->get('plugins.ai-chatbot.notification_enabled', true),
            'notificationText' => $this->config->get('plugins.ai-chatbot.notification_text', 'Hi there! Need help finding anything on our website?'),
            'notificationDelaySeconds' => (int)$this->config->get('plugins.ai-chatbot.notification_delay_seconds', 4),
            'quickRepliesEnabled' => (bool)$this->config->get('plugins.ai-chatbot.quick_replies_enabled', true),
            'quickReplies' => (array)$this->config->get('plugins.ai-chatbot.quick_replies', []),
            'maxTokens' => (int)$this->config->get('plugins.ai-chatbot.max_tokens', 800),
            'maxInputTokens' => (int)$this->config->get('plugins.ai-chatbot.max_input_tokens', 500),
            'contextWindowTokens' => (int)$this->config->get('plugins.ai-chatbot.context_window_tokens', 8192),
            'currentRoute' => $currentRoute,
        ]);

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

        $output = str_replace('</body>', $injection . "\n</body>", $output);
        $this->grav['output'] = $output;
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
        $rawUrl = $_SERVER['REQUEST_URI'] ?? '';
        $redirectUrl = $_SERVER['REDIRECT_URL'] ?? '';

        if (
            strpos($rawUrl, 'chatbot-api') !== false || 
            strpos($rawUrl, 'chatbot-export') !== false ||
            strpos($redirectUrl, 'chatbot-api') !== false ||
            strpos($redirectUrl, 'chatbot-export') !== false
        ) {
            if (strpos($rawUrl, 'chatbot-export') !== false || strpos($redirectUrl, 'chatbot-export') !== false) {
                $this->handleAnalyticsExport();
            } else {
                $this->handleChatbotQueryApi();
            }
            exit();
        }
    }

    protected function handleChatbotQueryApi()
    {
        header('Content-Type: application/json');
        header('Deprecation: true');
        header('Sunset: Wed, 31 Dec 2026 23:59:59 GMT');
        header('Warning: 299 - "Endpoint /chatbot-api is deprecated. Migrate to /api/v1/ai-chatbot/query"');
        header('Link: <' . $this->getRestBase() . '/query>; rel="successor-version"');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: Content-Type');
            http_response_code(200);
            exit();
        }

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
        $adminOnly = ['test_api_key', 'fetch_models', 'test_model_health', 'rebuild_rag_index', 'get_metrics', 'get_live_logs', 'get_security_logs', 'release_ip_lockouts', 'clear_security_logs', 'clear_analytics', 'generate_demo_data', 'analytics_report', 'test_connection'];
        if (in_array($action, $adminOnly, true)) {
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

        http_response_code(200);
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
     * Handle export download with user whitelist authentication check.
     * NOTE: preferred path is GET /api/v1/ai-chatbot/export (permission-enforced).
     * This legacy /chatbot-export shim no longer trusts raw cookies/session arrays.
     */
    protected function handleAnalyticsExport()
    {
        $requireAuth = (bool)$this->config->get('plugins.ai-chatbot.export_require_auth', true);

        if ($requireAuth) {
            $user = $this->getAuthenticatedAdminUser();

            $rawAllowed = $this->config->get('plugins.ai-chatbot.export_allowed_users', "admin\nmilkboy");
            $allowedUsers = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)$rawAllowed)));

            $username = '';
            if (is_object($user)) {
                $username = strtolower(trim((string)($user->username ?? $user->name ?? '')));
            } elseif (is_array($user)) {
                $username = strtolower(trim((string)($user['username'] ?? $user['name'] ?? '')));
            }

            $isAuthorized = false;

            // Require an actually-authenticated Grav user object — never a bare cookie.
            $authenticated = false;
            if (is_object($user)) {
                $authenticated = !empty($user->authenticated) || (method_exists($user, 'authorize') && $user->authorize('admin.login'));
            } elseif (is_array($user)) {
                $authenticated = !empty($user['authenticated']);
            } elseif (isset($this->grav['user']) && !empty($this->grav['user']->authenticated)) {
                $user = $this->grav['user'];
                $username = strtolower(trim((string)($user->username ?? '')));
                $authenticated = true;
            }

            if ($authenticated) {
                if (empty($allowedUsers)) {
                    $isAuthorized = true;
                } else {
                    foreach ($allowedUsers as $allowed) {
                        if (strtolower($allowed) === $username) {
                            $isAuthorized = true;
                            break;
                        }
                    }

                    if (!$isAuthorized && is_object($user) && method_exists($user, 'authorize')) {
                        if ($user->authorize('admin.super') || $user->authorize('admin.plugins')) {
                            $isAuthorized = true;
                        }
                    }
                }
            }

            if (!$isAuthorized) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => 403,
                    'error' => 'Forbidden',
                    'message' => "Access Denied: User '" . ($username ?: 'guest') . "' is not authorized to download interaction telemetry data. Please log in as a whitelisted admin user (" . implode(', ', $allowedUsers) . ") or use /api/v1/ai-chatbot/export."
                ], JSON_PRETTY_PRINT);
                exit();
            }
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
     * Inject front-end assets & CSS/JS configuration for floating chat widget based on page visibility rules.
     */
    public function onTwigSiteVariables()
    {
        if (!$this->isPluginEnabled()) {
            return;
        }

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
                return; // Hide widget on this page
            }

            if ($displayMode === 'exclude_selected' && $isListed) {
                return; // Hide widget on this page
            }
        }

        $assets = $this->grav['assets'];
        $assets->addCss('plugin://ai-chatbot/assets/css/chatbot.css');

        // Pass configuration data to JavaScript
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
            'notificationEnabled' => (bool)$this->config->get('plugins.ai-chatbot.notification_enabled', true),
            'notificationText' => $this->config->get('plugins.ai-chatbot.notification_text', 'Hi there! Need help finding anything on our website?'),
            'notificationDelaySeconds' => (int)$this->config->get('plugins.ai-chatbot.notification_delay_seconds', 4),
            'quickRepliesEnabled' => (bool)$this->config->get('plugins.ai-chatbot.quick_replies_enabled', true),
            'quickReplies' => (array)$this->config->get('plugins.ai-chatbot.quick_replies', []),
            'maxTokens' => (int)$this->config->get('plugins.ai-chatbot.max_tokens', 800),
            'maxInputTokens' => (int)$this->config->get('plugins.ai-chatbot.max_input_tokens', 500),
            'contextWindowTokens' => (int)$this->config->get('plugins.ai-chatbot.context_window_tokens', 8192),
            'currentRoute' => $currentRoute,
        ]);

        $assets->addInlineJs("window.GravChatbotConfig = {$jsConfig};");
        $assets->addJs('plugin://ai-chatbot/assets/js/chatbot.js', ['group' => 'bottom']);

        // Render Widget Twig Partial into Page Body
        $twig = $this->grav['twig'];
        $widgetHtml = '';
        try {
            $widgetHtml = $twig->processTemplate('partials/chatbot-widget.html.twig', [
                'config' => $this->config
            ]);
        } catch (\Throwable $t) {}

        $this->grav['assets']->addInlineJs("
            (function() {
                function injectGravChatbot() {
                    if (!document.getElementById('grav-ai-chatbot-root')) {
                        var div = document.createElement('div');
                        div.innerHTML = " . json_encode($widgetHtml) . ";
                        if (document.body) {
                            document.body.appendChild(div.firstElementChild);
                        }
                    }
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', injectGravChatbot);
                } else {
                    injectGravChatbot();
                }
            })();
        ");
    }

    /**
     * Inject admin-specific assets for analytics reporting.
     * NOTE: admin-next/fields/*.js are auto-bundled by Admin2 via
     * GET /api/v1/gpm/plugins/{slug}/fields — do NOT addJs() them here
     * (would double-register with wrong window.__GRAV_FIELD_TAG context).
     */
    public function onAdminTwigSiteVariables()
    {
        $assets = $this->grav['assets'];
        $assets->addCss('plugin://ai-chatbot/assets/css/admin-analytics.css');
        $assets->addJs('plugin://ai-chatbot/assets/js/admin-analytics.js');
        $assets->addJs('plugin://ai-chatbot/assets/js/admin-model-tools.js');
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
            } catch (\Throwable $t) {}
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
            } catch (\Throwable $t) {}
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
        } catch (\Throwable $t) {}
    }
}
