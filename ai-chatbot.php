<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Plugin;

// Register PSR-4 Autoloader for Plugin Classes
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
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onPageInitialized' => ['onPageInitialized', 0],
            'onAdminMenu' => ['onAdminMenu', 0],
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
     * Inject front-end assets & CSS/JS configuration for floating chat widget based on page visibility rules.
     */
    public function onTwigSiteVariables()
    {
        $config = $this->config->get('plugins.ai-chatbot');
        if (empty($config['enabled'])) {
            return;
        }

        // Page Display Visibility Rules (all, selected_only, exclude_selected)
        $currentRoute = $this->grav['uri']->path() ?: '/';
        $displayMode = $config['display_mode'] ?? 'all';

        if ($displayMode !== 'all') {
            $rawPages = $config['display_pages'] ?? '';
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
            'position' => $config['position'] ?? 'bottom-right',
            'welcomeMessage' => $config['welcome_message'] ?? 'Hello! How can I help you with this website today?',
            'accentColor' => $config['accent_color'] ?? '#3b82f6',
            'themePreset' => $config['theme_preset'] ?? 'glass_blue',
            'sessionRetentionDays' => (int)($config['session_retention_days'] ?? 7),
            'notificationEnabled' => !empty($config['notification_enabled']),
            'notificationText' => $config['notification_text'] ?? '👋 Hi there! Need help finding anything on our website?',
            'notificationDelaySeconds' => (int)($config['notification_delay_seconds'] ?? 4),
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
                    const div = document.createElement('div');
                    div.innerHTML = " . json_encode($widgetHtml) . ";
                    document.body.appendChild(div.firstElementChild);
                }
            });
        ", ['group' => 'bottom']);
    }

    /**
     * Inject Admin Analytics Dashboard CSS & JS.
     */
    public function onAdminTwigSiteVariables()
    {
        $assets = $this->grav['assets'];
        $assets->addCss('plugin://ai-chatbot/assets/css/admin-analytics.css');
        $assets->addJs('plugin://ai-chatbot/assets/js/admin-analytics.js', ['group' => 'bottom']);
    }

    /**
     * Controller method for AJAX Chatbot API requests.
     */
    protected function handleChatbotQueryApi()
    {
        header('Content-Type: application/json');
        $config = $this->config->get('plugins.ai-chatbot');

        $handler = new ChatbotHandler($this->grav, $config);
        $response = $handler->handleRequest();

        http_response_code($response['http_code'] ?? 200);
        echo json_encode($response);
    }

    /**
     * Controller method for CSV and JSON Analytics Reports exports.
     */
    protected function handleAnalyticsExport()
    {
        $format = $_GET['format'] ?? 'csv';
        $reportGen = new AnalyticsReportGenerator($this->grav);
        $reportGen->exportReport($format);
    }
}
