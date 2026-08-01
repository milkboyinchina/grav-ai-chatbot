<?php

declare(strict_types=1);

namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;

/**
 * Admin2IntegrationService
 *
 * [Admin2-Integration] Handles Admin2 extension points including:
 * 1. Dashboard Widget Payload (onApiDashboardWidgets)
 * 2. Recent 5 Query Terms & Source Page Routes
 * 3. Menubar Action Links & Notification Badges
 */
class Admin2IntegrationService
{
    /** @var Grav */
    protected Grav $grav;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
        require_once __DIR__ . '/Logger.php';
        require_once __DIR__ . '/FaqRecommender.php';
    }

    /**
     * Build Admin2 Dashboard Widget Definition for onApiDashboardWidgets event.
     *
     * @return array<string, mixed>
     */
    public function getDashboardWidgetDefinition(): array
    {
        $stats = $this->getDashboardStatsPayload();

        return [
            'id' => 'ai-chatbot-analytics',
            'title' => 'AI Chatbot & RAG Telemetry',
            'icon' => 'fa-robot',
            'defaultSize' => 'lg',
            'sizes' => ['md', 'lg', 'xl'],
            'priority' => 85,
            'authorize' => 'admin.plugins',
            'data' => $stats
        ];
    }

    /**
     * Gather telemetry metrics including recent queries, FAQ match ratio, and cost savings.
     *
     * @return array<string, mixed>
     */
    public function getDashboardStatsPayload(): array
    {
        $logger = new Logger($this->grav);
        $logs = $logger->getLogs();

        $totalQueries = count($logs);
        $faqHits = 0;
        $aiHits = 0;
        $totalTokens = 0;
        $totalCost = 0.0;
        $todayQueries = 0;
        $todayStr = date('Y-m-d');

        $recentQueries = [];

        foreach ($logs as $index => $log) {
            $source = $log['source'] ?? 'ai_api';
            if ($source === 'faq_match') {
                $faqHits++;
            } else {
                $aiHits++;
            }

            $totalTokens += (int)($log['total_tokens'] ?? 0);
            $totalCost += (float)($log['estimated_cost_usd'] ?? 0.0);

            $ts = $log['timestamp'] ?? '';
            if (str_starts_with($ts, $todayStr)) {
                $todayQueries++;
            }

            if ($index < 5) {
                $rawPage = $log['source_page'] ?? $log['url'] ?? '/';
                $pagePath = parse_url($rawPage, PHP_URL_PATH) ?: '/';

                $recentQueries[] = [
                    'question' => $log['question'] ?? 'N/A',
                    'page' => $pagePath,
                    'source' => $source,
                    'timestamp' => $ts ? date('H:i', strtotime($ts)) : 'Recent'
                ];
            }
        }

        $config = $this->grav['config']->get('plugins.ai-chatbot', []);
        $provider = strtoupper((string)($config['provider'] ?? 'gemini'));
        $model = (string)($config['model'] ?? 'gemini-2.0-flash');
        $faqRatio = $totalQueries > 0 ? round(($faqHits / $totalQueries) * 100, 1) : 0;

        return [
            'total_queries' => $totalQueries,
            'today_queries' => $todayQueries,
            'faq_hits' => $faqHits,
            'ai_hits' => $aiHits,
            'faq_cache_ratio' => $faqRatio,
            'total_tokens' => $totalTokens,
            'estimated_cost_usd' => round($totalCost, 4),
            'provider' => $provider,
            'model' => $model,
            'recent_queries' => $recentQueries
        ];
    }

    /**
     * Build top-header menubar action links & notification badges for Admin2.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMenubarItems(): array
    {
        $logger = new Logger($this->grav);
        $logs = $logger->getLogs();

        // Calculate candidate FAQ recommendations count
        $recs = FaqRecommender::getRecommendations($logs, 2);
        $recCount = count($recs);

        $route = (string)$this->grav['config']->get('plugins.admin2.route', '/admin');
        $pluginUrl = rtrim($route, '/') . '/plugins/ai-chatbot';

        $items = [
            [
                'id' => 'ai-chatbot-copilot',
                'plugin' => 'ai-chatbot',
                'label' => 'AI Chatbot',
                'icon' => 'fa-robot',
                'href' => $pluginUrl,
                'priority' => 90,
                'showLabel' => false,
                'variant' => 'default'
            ]
        ];

        if ($recCount > 0) {
            $items[] = [
                'id' => 'ai-chatbot-faq-recs',
                'plugin' => 'ai-chatbot',
                'label' => "{$recCount} Candidate FAQs",
                'icon' => 'fa-lightbulb',
                'href' => $pluginUrl,
                'badgeCount' => $recCount,
                'priority' => 88,
                'showLabel' => true,
                'variant' => 'warning'
            ];
        }

        return $items;
    }
}
