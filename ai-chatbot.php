<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\AiChatbot\ChatbotHandler;
use Grav\Plugin\AiChatbot\AnalyticsReportGenerator;

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
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onPageNotFound' => ['onPageNotFound', 0],
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
        $enabled = $this->config->get('plugins.ai-chatbot.enabled', true);
        if (!$enabled) {
            return;
        }

        if ($this->isAdmin()) {
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables' => ['onAdminTwigSiteVariables', 0],
                'onPageInitialized' => ['onPageInitialized', 0],
                'onPageNotFound' => ['onPageNotFound', 0],
                'onAdminMenu' => ['onAdminMenu', 0],
            ]);
        } else {
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
                'onPageInitialized' => ['onPageInitialized', 0],
                'onPageNotFound' => ['onPageNotFound', 0],
            ]);
        }
    }

    /**
     * Intercept custom routes if standard page lookup returns Page Not Found.
     */
    public function onPageNotFound()
    {
        $this->routeCheck();
    }

    /**
     * Intercept API & Export calls after Grav Pages object is fully loaded.
     */
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
                    const div = document.createElement('div');
                    div.innerHTML = " . json_encode($widgetHtml) . ";
                    document.body.appendChild(div.firstElementChild);
                }
            });
        ", ['group' => 'bottom']);
    }

    /**
     * Inject Admin Analytics Dashboard CSS & JS and register admin sidebar menu link.
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
    }

    /**
     * Controller method for AJAX Chatbot API requests.
     */
    protected function handleChatbotQueryApi()
    {
        header('Content-Type: application/json');
        $config = (array)$this->config->get('plugins.ai-chatbot');

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true) ?: $_POST ?: $_GET;

        $handler = new ChatbotHandler($this->grav, $config);
        $response = $handler->processRequest($data);

        http_response_code($response['http_code'] ?? 200);
        echo json_encode($response);
        exit();
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
