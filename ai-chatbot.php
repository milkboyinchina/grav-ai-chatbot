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
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 1000],
            'onPageNotFound' => ['onPageNotFound', 1000],
            'onPageInitialized' => ['onPageInitialized', 1000],
            'onAdminMenu' => ['onAdminMenu', 0],
            'onBlueprintCreated' => ['onBlueprintCreated', 0],
        ];
    }

    /**
     * Add AI Chatbot entry to Grav Admin sidebar navigation menu.
     */
    public function onAdminMenu()
    {
        $this->grav['twig']->plugins_hook['nav']['ai-chatbot'] = [
            'route' => 'plugins/ai-chatbot',
            'icon' => 'fa-robot',
            'title' => 'AI Chatbot',
            'authorize' => 'admin.plugins',
            'priority' => 10
        ];

        if (isset($this->grav['admin'])) {
            $this->grav['admin']->sidebar['ai-chatbot'] = [
                'route' => 'plugins/ai-chatbot',
                'icon' => 'fa-robot',
                'title' => 'AI Chatbot',
                'authorize' => 'admin.plugins',
                'priority' => 10
            ];
        }
    }

    /**
     * Populate blueprint fields dynamically with live analytics metrics, visual bar charts, and full absolute export URLs.
     */
    public function onBlueprintCreated($event)
    {
        $blueprint = $event['blueprint'];
        if ($blueprint->getFilename() === 'ai-chatbot') {
            try {
                $logger = new Logger($this->grav);
                $action = $this->config->get('plugins.ai-chatbot.analytics_action', 'none');

                if ($action === 'generate_demo') {
                    $handler = new ChatbotHandler($this->grav, $this->config->get('plugins.ai-chatbot', []));
                    $handler->generateDemoTelemetryData($logger);
                } elseif ($action === 'clear_data') {
                    $logger->clearLogs();
                }

                $range = $this->config->get('plugins.ai-chatbot.analytics_range', 'all');
                $generator = new AnalyticsReportGenerator($this->grav);
                $data = $generator->getDashboardAnalyticsData($range);

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
                $chartLines = ["📈 DAILY INTERACTION VOLUME:"];
                $dailyLabels = $data['daily_chart']['labels'] ?? [];
                $dailyValues = $data['daily_chart']['values'] ?? [];
                $maxDaily = max(1, ...($dailyValues ?: [1]));

                if (empty($dailyLabels)) {
                    $chartLines[] = "  (No interaction data for selected period)";
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
                $chartLines[] = "📊 QUERY SOURCE DISTRIBUTION RATIO:";
                $chartLines[] = sprintf("  ⚡ FAQ Matches (Free) : %s %d (%d%%)", str_repeat('█', (int)round(($faqPct / 100) * 20)), $faqHits, $faqPct);
                $chartLines[] = sprintf("  🤖 AI Model Calls     : %s %d (%d%%)", str_repeat('█', (int)round(($aiPct / 100) * 20)), $aiHits, $aiPct);
                $chartLines[] = sprintf("  🛡️ Rate Limit Shield  : %s %d (%d%%)", str_repeat('█', (int)round(($ratePct / 100) * 20)), $rateHits, $ratePct);

                $chartStr = implode("\n", $chartLines);

                // Read full site URL from site.yaml or fallback to rootUrl(true)
                $siteUrl = rtrim($this->config->get('site.url') ?: $this->grav['uri']->rootUrl(true), '/');
                if (empty($siteUrl) || $siteUrl === '/') {
                    $siteUrl = 'http://localhost';
                }

                $csvUrl = "{$siteUrl}/chatbot-export?format=csv&range=" . urlencode($range);
                $jsonUrl = "{$siteUrl}/chatbot-export?format=json&range=" . urlencode($range);
                $rawUrl = "{$siteUrl}/chatbot-export?format=raw_interactions&range=" . urlencode($range);

                // Recommendations
                $recs = $data['recommendations'] ?? [];
                $recLines = [];
                if (!empty($recs)) {
                    foreach ($recs as $rec) {
                        $recLines[] = "• [{$rec['count']}x asked] Q: {$rec['sample_question']} => A: " . substr($rec['suggested_answer'], 0, 100) . "...";
                    }
                    $recStr = implode("\n\n", $recLines);
                } else {
                    $recStr = "No candidate FAQ recommendations for selected period. All interactions logged in user/data/ai-chatbot/interactions.json.";
                }

                $blueprint->set('form.fields.section_analytics.fields.analytics_summary_text.default', $summaryStr);
                $blueprint->set('form.fields.section_analytics.fields.analytics_chart_display.default', $chartStr);
                $blueprint->set('form.fields.section_analytics.fields.download_csv_link.default', $csvUrl);
                $blueprint->set('form.fields.section_analytics.fields.download_json_link.default', $jsonUrl);
                $blueprint->set('form.fields.section_analytics.fields.download_raw_link.default', $rawUrl);
                $blueprint->set('form.fields.section_analytics.fields.analytics_recommendations_text.default', $recStr);
            } catch (\Throwable $e) {
                // Ignore gracefully
            }
        }
    }

    /**
     * Plugin initialization. Subscribes necessary events.
     */
    public function onPluginsInitialized()
    {
        $enabled = $this->config->get('plugins.ai-chatbot.enabled', true);
        if (!$enabled) {
            return;
        }

        if ($this->isAdmin()) {
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables' => ['onAdminTwigSiteVariables', 0],
                'onPageInitialized' => ['onPageInitialized', 1000],
                'onPageNotFound' => ['onPageNotFound', 1000],
                'onAdminMenu' => ['onAdminMenu', 0],
                'onBlueprintCreated' => ['onBlueprintCreated', 0],
            ]);
        } else {
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
                'onPageInitialized' => ['onPageInitialized', 1000],
                'onPageNotFound' => ['onPageNotFound', 1000],
            ]);
        }
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
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true) ?: $_POST;

        $handler = new ChatbotHandler($this->grav, $this->config->get('plugins.ai-chatbot', []));
        $response = $handler->processRequest($data);

        http_response_code(200);
        echo json_encode($response);
        exit();
    }

    /**
     * Inspect all session sources to find authenticated Grav Admin user.
     */
    protected function getAuthenticatedAdminUser()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        // 1. Check Grav admin object user
        if (isset($this->grav['admin']) && !empty($this->grav['admin']->user) && !empty($this->grav['admin']->user->authenticated)) {
            return $this->grav['admin']->user;
        }

        // 2. Check Grav core user object
        if (isset($this->grav['user']) && !empty($this->grav['user']->authenticated)) {
            return $this->grav['user'];
        }

        // 3. Check Grav session user
        if (isset($this->grav['session']) && !empty($this->grav['session']->user) && !empty($this->grav['session']->user->authenticated)) {
            return $this->grav['session']->user;
        }

        // 4. Check $_SESSION array
        if (!empty($_SESSION)) {
            if (isset($_SESSION['admin']['user'])) {
                return $_SESSION['admin']['user'];
            }
            if (isset($_SESSION['user'])) {
                return $_SESSION['user'];
            }
            foreach ($_SESSION as $val) {
                if (is_object($val) && (!empty($val->authenticated) || !empty($val->username))) {
                    return $val;
                }
                if (is_array($val) && (!empty($val['authenticated']) || !empty($val['username']))) {
                    return $val;
                }
            }
        }

        return null;
    }

    /**
     * Handle export download with user whitelist authentication check.
     */
    protected function handleAnalyticsExport()
    {
        $requireAuth = (bool)$this->config->get('plugins.ai-chatbot.export_require_auth', true);

        if ($requireAuth) {
            $user = $this->getAuthenticatedAdminUser();

            $rawAllowed = $this->config->get('plugins.ai-chatbot.export_allowed_users', "admin\nmilkboy");
            $allowedUsers = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $rawAllowed)));

            $username = '';
            if (is_object($user)) {
                $username = strtolower(trim($user->username ?? $user->name ?? ''));
            } elseif (is_array($user)) {
                $username = strtolower(trim($user['username'] ?? $user['name'] ?? ''));
            }

            $isAuthorized = false;

            // Check if user is authenticated in Grav Admin
            if ($user || !empty($_COOKIE['grav-site-40d1b2d']) || !empty($_COOKIE['admin-session'])) {
                if (empty($allowedUsers)) {
                    $isAuthorized = true;
                } else {
                    if (empty($username)) {
                        $username = 'admin'; // Admin session cookie present
                    }
                    foreach ($allowedUsers as $allowed) {
                        if (strtolower($allowed) === 'all' || strtolower($allowed) === '*' || strtolower($allowed) === $username) {
                            $isAuthorized = true;
                            break;
                        }
                    }

                    if (!$isAuthorized && is_object($user) && method_exists($user, 'authorize')) {
                        if ($user->authorize('admin.super') || $user->authorize('admin.plugins') || $user->authorize('admin.login')) {
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
                    'message' => "Access Denied: User '" . ($username ?: 'guest') . "' is not authorized to download interaction telemetry data. Please log in as a whitelisted admin user (" . implode(', ', $allowedUsers) . ")."
                ], JSON_PRETTY_PRINT);
                exit();
            }
        }

        $format = $_GET['format'] ?? 'csv';
        $range = $_GET['range'] ?? 'all';
        $generator = new AnalyticsReportGenerator($this->grav);
        $generator->exportReport($format, $range);
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
        $enabled = $this->config->get('plugins.ai-chatbot.enabled', true);
        if (!$enabled) {
            return;
        }

        // Page Display Visibility Rules (all, selected_only, exclude_selected)
        $currentRoute = $this->grav['uri']->path() ?: '/';
        $displayMode = $this->config->get('plugins.ai-chatbot.display_mode', 'all');

        if ($displayMode !== 'all') {
            $rawPages = $this->config->get('plugins.ai-chatbot.display_pages', '');
            $pagesList = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $rawPages))));

            $isListed = in_array($currentRoute, $pagesList, true);

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
            'position' => $this->config->get('plugins.ai-chatbot.position', 'bottom-right'),
            'welcomeMessage' => $this->config->get('plugins.ai-chatbot.welcome_message', 'Hello! How can I help you with this website today?'),
            'accentColor' => $this->config->get('plugins.ai-chatbot.accent_color', '#3b82f6'),
            'themePreset' => $this->config->get('plugins.ai-chatbot.theme_preset', 'glass_blue'),
            'sessionRetentionDays' => (int)$this->config->get('plugins.ai-chatbot.session_retention_days', 7),
            'notificationEnabled' => (bool)$this->config->get('plugins.ai-chatbot.notification_enabled', true),
            'notificationText' => $this->config->get('plugins.ai-chatbot.notification_text', '👋 Hi there! Need help finding anything on our website?'),
            'notificationDelaySeconds' => (int)$this->config->get('plugins.ai-chatbot.notification_delay_seconds', 4),
            'quickRepliesEnabled' => (bool)$this->config->get('plugins.ai-chatbot.quick_replies_enabled', true),
            'quickReplies' => (array)$this->config->get('plugins.ai-chatbot.quick_replies', []),
            'currentRoute' => $currentRoute,
        ]);

        $assets->addInlineJs("window.GravChatbotConfig = {$jsConfig};");
        $assets->addJs('plugin://ai-chatbot/assets/js/chatbot.js', ['group' => 'bottom']);

        // Render Widget Twig Partial into Page Body
        $twig = $this->grav['twig'];
        $widgetHtml = $twig->processTemplate('partials/chatbot-widget.html.twig', [
            'config' => $this->config
        ]);

        $this->grav['assets']->addInlineJs("
            document.addEventListener('DOMContentLoaded', function() {
                if (!document.getElementById('grav-ai-chatbot-root')) {
                    var div = document.createElement('div');
                    div.innerHTML = " . json_encode($widgetHtml) . ";
                    document.body.appendChild(div.firstElementChild);
                }
            });
        ");
    }

    /**
     * Inject admin-specific assets for analytics reporting.
     */
    public function onAdminTwigSiteVariables()
    {
        $assets = $this->grav['assets'];
        $assets->addCss('plugin://ai-chatbot/assets/css/admin-analytics.css');
        $assets->addJs('plugin://ai-chatbot/assets/js/admin-analytics.js', ['group' => 'bottom']);

        if (isset($this->grav['twig']->plugins_hook['nav'])) {
            $this->grav['twig']->plugins_hook['nav']['ai-chatbot'] = [
                'route' => 'plugins/ai-chatbot',
                'icon' => 'fa-robot',
                'title' => 'AI Chatbot',
                'authorize' => 'admin.plugins',
                'priority' => 10
            ];
        }

        if (isset($this->grav['admin'])) {
            $this->grav['admin']->sidebar['ai-chatbot'] = [
                'route' => 'plugins/ai-chatbot',
                'icon' => 'fa-robot',
                'title' => 'AI Chatbot',
                'authorize' => 'admin.plugins',
                'priority' => 10
            ];
        }
    }
}
