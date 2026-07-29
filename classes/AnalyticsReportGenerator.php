<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;

/**
 * Class AnalyticsReportGenerator
 * Aggregates interaction metrics, generates chart data for the Admin dashboard, and formats CSV/JSON exports.
 *
 * @license GPL-3.0-or-later
 */
class AnalyticsReportGenerator
{
    protected Grav $grav;
    protected Logger $logger;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
        $this->logger = new Logger($grav);
    }

    /**
     * Download or output report according to format (csv or json).
     */
    public function exportReport(string $format = 'csv'): void
    {
        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="ai-chatbot-analytics-' . date('Y-m-d') . '.json"');
            echo json_encode($this->generateJsonReport(), JSON_PRETTY_PRINT);
            exit();
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="ai-chatbot-analytics-' . date('Y-m-d') . '.csv"');
        echo $this->generateCsvReport();
        exit();
    }

    /**
     * Get pre-processed data structure for Admin Dashboard graphs.
     */
    public function getDashboardAnalyticsData(): array
    {
        $logs = $this->logger->getLogs();

        $totalQueries = count($logs);
        $faqHits = 0;
        $aiHits = 0;
        $rateLimitHits = 0;
        $totalTokens = 0;
        $totalEstCost = 0.0;

        $dailyVolume = [];

        foreach ($logs as $log) {
            $src = $log['source'] ?? 'ai_api';
            if ($src === 'faq_match') {
                $faqHits++;
            } elseif ($src === 'ai_api') {
                $aiHits++;
            } elseif ($src === 'rate_limit') {
                $rateLimitHits++;
            }

            $totalTokens += ($log['total_tokens'] ?? 0);
            $totalEstCost += ($log['estimated_cost_usd'] ?? 0.0);

            // Daily chart grouping
            $dateKey = !empty($log['timestamp']) ? substr($log['timestamp'], 0, 10) : date('Y-m-d');
            $dailyVolume[$dateKey] = ($dailyVolume[$dateKey] ?? 0) + 1;
        }

        ksort($dailyVolume);

        $recommendations = FaqRecommender::getRecommendations($logs, 2);

        return [
            'summary' => [
                'total_queries' => $totalQueries,
                'faq_hits' => $faqHits,
                'ai_hits' => $aiHits,
                'rate_limit_hits' => $rateLimitHits,
                'saved_api_calls' => $faqHits,
                'total_tokens' => $totalTokens,
                'total_cost_usd' => round($totalEstCost, 4)
            ],
            'daily_chart' => [
                'labels' => array_keys($dailyVolume),
                'values' => array_values($dailyVolume)
            ],
            'ratio_chart' => [
                'faq_hits' => $faqHits,
                'ai_hits' => $aiHits,
                'rate_limit_hits' => $rateLimitHits
            ],
            'recommendations' => $recommendations
        ];
    }

    /**
     * Generate CSV export string.
     */
    public function generateCsvReport(): string
    {
        $logs = $this->logger->getLogs();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['ID', 'Timestamp', 'IP Hash', 'Question', 'Answer', 'Source', 'Provider', 'Prompt Tokens', 'Completion Tokens', 'Total Tokens', 'Est Cost (USD)']);

        foreach ($logs as $log) {
            fputcsv($output, [
                $log['id'] ?? '',
                $log['timestamp'] ?? '',
                $log['ip_hash'] ?? '',
                $log['question'] ?? '',
                $log['answer'] ?? '',
                $log['source'] ?? '',
                $log['provider'] ?? '',
                $log['prompt_tokens'] ?? 0,
                $log['completion_tokens'] ?? 0,
                $log['total_tokens'] ?? 0,
                $log['estimated_cost_usd'] ?? 0.0
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Generate JSON export structure.
     */
    public function generateJsonReport(): array
    {
        return [
            'generated_at' => date('c'),
            'analytics' => $this->getDashboardAnalyticsData(),
            'logs' => $this->logger->getLogs()
        ];
    }
}
