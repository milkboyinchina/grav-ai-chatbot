<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;

/**
 * Class AnalyticsReportGenerator
 * Aggregates interaction metrics with date range filtering (7d, 1m, 3m, 6m, 12m, all),
 * generates chart data for the Admin dashboard, and formats CSV, JSON, and raw interaction exports.
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
     * Download or output report according to format (csv, json, or raw_interactions) and date range.
     *
     * @param string $format Export format: 'csv', 'json', or 'raw_interactions'
     * @param string $range Date range: '7d', '1m', '3m', '6m', '12m', 'all'
     */
    public function exportReport(string $format = 'csv', string $range = 'all'): void
    {
        $dateStr = date('Y-m-d');
        $rangeSlug = preg_replace('/[^\w\-]/', '', $range ?: 'all');

        if ($format === 'raw_interactions') {
            header('Content-Type: application/json');
            header("Content-Disposition: attachment; filename=\"ai-chatbot-interactions-{$rangeSlug}-{$dateStr}.json\"");
            $logs = $this->filterLogsByRange($this->logger->getLogs(), $range);
            echo json_encode(array_values($logs), JSON_PRETTY_PRINT);
            exit();
        }

        if ($format === 'json') {
            header('Content-Type: application/json');
            header("Content-Disposition: attachment; filename=\"ai-chatbot-analytics-{$rangeSlug}-{$dateStr}.json\"");
            echo json_encode($this->generateJsonReport($range), JSON_PRETTY_PRINT);
            exit();
        }

        header('Content-Type: text/csv');
        header("Content-Disposition: attachment; filename=\"ai-chatbot-analytics-{$rangeSlug}-{$dateStr}.csv\"");
        echo $this->generateCsvReport($range);
        exit();
    }

    /**
     * Get pre-processed data structure for Admin Dashboard graphs filtered by date range.
     *
     * @param string $range Date range: '7d', '1m', '3m', '6m', '12m', 'all'
     * @return array
     */
    public function getDashboardAnalyticsData(string $range = 'all'): array
    {
        $rawLogs = $this->logger->getLogs();
        $logs = $this->filterLogsByRange($rawLogs, $range);

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
            'range' => $range,
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
     * Filter log records according to date range string.
     */
    protected function filterLogsByRange(array $logs, string $range = 'all'): array
    {
        if (empty($range) || $range === 'all') {
            return $logs;
        }

        $daysMap = [
            '7d' => 7,
            '1m' => 30,
            '3m' => 90,
            '6m' => 180,
            '12m' => 365,
        ];

        $days = $daysMap[$range] ?? 0;
        if ($days <= 0) {
            return $logs;
        }

        $cutoff = strtotime("-{$days} days");
        $filtered = array_filter($logs, function ($log) use ($cutoff) {
            $ts = strtotime($log['timestamp'] ?? '');
            return $ts !== false && $ts >= $cutoff;
        });

        return array_values($filtered);
    }

    /**
     * Generate CSV export string for given date range.
     */
    public function generateCsvReport(string $range = 'all'): string
    {
        $logs = $this->filterLogsByRange($this->logger->getLogs(), $range);

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
     * Generate JSON export structure for given date range.
     */
    public function generateJsonReport(string $range = 'all'): array
    {
        return [
            'generated_at' => date('c'),
            'range' => $range,
            'analytics' => $this->getDashboardAnalyticsData($range),
            'logs' => $this->filterLogsByRange($this->logger->getLogs(), $range)
        ];
    }
}
