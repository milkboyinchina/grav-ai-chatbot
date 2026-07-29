<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\AiChatbot\ChatbotHandler;
use Grav\Plugin\AiChatbot\AnalyticsReportGenerator;

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
     * Dynamic Markdown injection into blueprint for Admin 2 (SvelteKit SPA) and classic Admin.
     */
    public function onBlueprintCreated($event)
    {
        $blueprint = $event['blueprint'];
        if ($blueprint->getFilename() === 'ai-chatbot') {
            $generator = new AnalyticsReportGenerator($this->grav);
            $data = $generator->getDashboardAnalyticsData();

            $summary = $data['summary'] ?? [];
            $totalQueries = $summary['total_queries'] ?? 0;
            $faqHits = $summary['faq_hits'] ?? 0;
            $aiHits = $summary['ai_hits'] ?? 0;
            $totalTokens = number_format($summary['total_tokens'] ?? 0);
            $totalCost = number_format($summary['total_cost_usd'] ?? 0, 4);

            $faqPct = $totalQueries > 0 ? round(($faqHits / $totalQueries) * 100) : 0;

            $markdown = "### 🤖 AI Chatbot Analytics & Performance Reports\n\n";
            $markdown .= "Track visitor query trends, FAQ pre-matching cost savings, token consumption, and automated FAQ recommendations.\n\n";
            $markdown .= "| 🔢 Total Queries | ⚡ FAQ Matches (Free Hits) | 🤖 AI API Calls | 🪙 Est. Tokens | 💵 Est. Cost |\n";
            $markdown .= "| --- | --- | --- | --- | --- |\n";
            $markdown .= "| **{$totalQueries}** | **{$faqHits}** ({$faqPct}% saved) | **{$aiHits}** | **{$totalTokens}** | **\${$totalCost}** |\n\n";
            $markdown .= "---\n\n";
            $markdown .= "### 📥 Export Telemetry Reports\n\n";
            $markdown .= "[📥 Export CSV Report](/chatbot-export?format=csv) &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; [📄 Export JSON Data](/chatbot-export?format=json)\n\n";
            $markdown .= "---\n\n";
            $markdown .= "### 💡 Candidate FAQ Recommendations\n\n";
            $markdown .= "> The following visitor questions were frequently handled by the AI API. Adding them to your `/faq` page will answer future queries instantly for free!\n\n";

            $recs = $data['recommendations'] ?? [];
            if (empty($recs)) {
                $markdown .= "*No FAQ recommendations available yet. Log more AI queries to see candidates!*\n";
            } else {
                $markdown .= "| Frequency | Sample Visitor Question | Suggested AI Response |\n";
                $markdown .= "| --- | --- | --- |\n";
                foreach ($recs as $rec) {
                    $q = str_replace('|', '-', $rec['sample_question']);
                    $a = str_replace('|', '-', substr($rec['suggested_answer'], 0, 120)) . '...';
                    $markdown .= "| **{$rec['count']}x asked** | {$q} | {$a} |\n";
                }
            }

            $blueprint->set('form.fields.section_analytics.fields.analytics_dashboard_display.content', $markdown);
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

    protected function handleAnalyticsExport()
    {
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
