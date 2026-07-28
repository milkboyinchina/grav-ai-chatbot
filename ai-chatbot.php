<?php
namespace Grav\Plugin;

// Autoload plugin classes
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
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
}

use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Plugin\AiChatbot\ChatbotHandler;
use Grav\Plugin\AiChatbot\AnalyticsReportGenerator;

/**
 * Grav AI Chatbot Plugin
 *
 * Provides AI chatbot capabilities, local FAQ pre-matching, multi-tier contact resolution,
 * rate limiting, interaction logging, and an Admin Analytics dashboard.
 *
 * @license GPL-3.0-or-later
 */
class AiChatbotPlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onPageInitialized' => ['onPageInitialized', 0],
        ];
    }

    /**
     * Initialize plugin configuration and asset injectors.
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if plugin is disabled
        if (!$this->config->get('plugins.ai-chatbot.enabled')) {
            return;
        }

        // Handle Analytics Exports (CSV / JSON)
        $rawUrl = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($rawUrl, '/chatbot-export') !== false) {
            $this->handleAnalyticsExport();
            exit();
        }

        if ($this->isAdmin()) {
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables' => ['onAdminTwigSiteVariables', 0],
            ]);
        } else {
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            ]);
        }
    }

    /**
     * Intercept API calls after Grav Pages object is fully loaded.
     */
    public function onPageInitialized()
    {
        $rawUrl = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($rawUrl, '/api/chatbot/query') !== false || strpos($rawUrl, '/chatbot-api') !== false) {
            $this->handleChatbotQueryApi();
            exit();
        }
    }

    /**
     * Register plugin Twig templates directory.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Inject front-end assets & CSS/JS configuration for floating chat widget.
     */
    public function onTwigSiteVariables()
    {
        $config = $this->config->get('plugins.ai-chatbot');

        $assets = $this->grav['assets'];
        $assets->addCss('plugin://ai-chatbot/assets/css/chatbot.css');

        // Pass configuration data to JavaScript
        $jsConfig = json_encode([
            'apiEndpoint' => '/chatbot-api',
            'position' => $config['position'] ?? 'bottom-right',
            'welcomeMessage' => $config['welcome_message'] ?? 'Hello! How can I help you with this website today?',
            'accentColor' => $config['accent_color'] ?? '#3b82f6',
            'themePreset' => $config['theme_preset'] ?? 'glass_blue',
            'sessionRetentionDays' => (int)($config['session_retention_days'] ?? 7),
            'notificationEnabled' => !empty($config['notification_enabled']),
            'notificationText' => $config['notification_text'] ?? '👋 Hi there! Need help finding anything on our website?',
            'notificationDelaySeconds' => (int)($config['notification_delay_seconds'] ?? 4),
            'currentRoute' => $this->grav['uri']->path(),
        ]);

        $assets->addInlineJs("window.GravChatbotConfig = {$jsConfig};");
        $assets->addJs('plugin://ai-chatbot/assets/js/chatbot.js', ['group' => 'bottom', 'loading' => 'defer']);
    }

    /**
     * Inject Admin Analytics CSS/JS assets when accessing plugin settings in Admin.
     */
    public function onAdminTwigSiteVariables()
    {
        $uri = $this->grav['uri'];
        if (strpos($uri->path(), '/admin/plugins/ai-chatbot') !== false) {
            $assets = $this->grav['assets'];
            $assets->addCss('plugin://ai-chatbot/assets/css/admin-analytics.css');
            $assets->addJs('plugin://ai-chatbot/assets/js/admin-analytics.js', ['group' => 'bottom']);

            // Inject Analytics Summary Data for Admin Dashboard Graphs
            $reportGen = new AnalyticsReportGenerator($this->grav);
            $analyticsData = json_encode($reportGen->getDashboardAnalyticsData());
            $assets->addInlineJs("window.GravChatbotAnalytics = {$analyticsData};");
        }
    }

    /**
     * Handles POST AJAX calls to /api/chatbot/query
     */
    protected function handleChatbotQueryApi()
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
            return;
        }

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true) ?? $_POST;

        $handler = new ChatbotHandler($this->grav, $this->config->get('plugins.ai-chatbot'));
        $response = $handler->processRequest($data);

        if (isset($response['http_code']) && $response['http_code'] !== 200) {
            http_response_code($response['http_code']);
        }

        echo json_encode($response);
    }

    /**
     * Handles Admin Export calls (CSV / JSON)
     */
    protected function handleAnalyticsExport()
    {
        $reportGen = new AnalyticsReportGenerator($this->grav);
        $format = $_GET['format'] ?? 'csv';

        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="ai-chatbot-report.json"');
            echo json_encode($reportGen->generateJsonReport(), JSON_PRETTY_PRINT);
        } else {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="ai-chatbot-report.csv"');
            echo $reportGen->generateCsvReport();
        }
    }
}
