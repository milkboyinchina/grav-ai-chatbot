<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;

/**
 * Class AnalyticsReportGenerator
 * Aggregates interaction metrics using configured Input/Output token pricing,
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
     * Download or output report according to format (csv, json, or raw_interactions).
     *
     * @param string $format Export format: 'csv', 'json', or 'raw_interactions'
     */
    public function exportReport(string $format = 'csv'): void
    {
        $dateStr = date('Y-m-d');

        if ($format === 'raw_interactions') {
            header('Content-Type: application/json');
            header("Content-Disposition: attachment; filename=\"ai-chatbot-interactions-{$dateStr}.json\"");
            $logs = $this->logger->getLogs();
            echo json_encode(array_values($logs), JSON_PRETTY_PRINT);
            exit();
        }

        if ($format === 'json') {
            header('Content-Type: application/json');
            header("Content-Disposition: attachment; filename=\"ai-chatbot-analytics-{$dateStr}.json\"");
            echo json_encode($this->generateJsonReport(), JSON_PRETTY_PRINT);
            exit();
        }

        header('Content-Type: text/csv');
        header("Content-Disposition: attachment; filename=\"ai-chatbot-analytics-{$dateStr}.csv\"");
        echo $this->generateCsvReport();
        exit();
    }

    /**
     * Get pre-processed data structure for Admin Dashboard graphs using configured token prices.
     *
     * @return array
     */
    public function getDashboardAnalyticsData(): array
    {
        $logs = $this->logger->getLogs();

        $config = $this->grav['config']->get('plugins.ai-chatbot', []);
        $inputPricePerM = (float)($config['cost_input_token_price_per_m'] ?? 0.15);
        $outputPricePerM = (float)($config['cost_output_token_price_per_m'] ?? 0.60);

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

            $pTok = (int)($log['prompt_tokens'] ?? 0);
            $cTok = (int)($log['completion_tokens'] ?? 0);
            $totTok = (int)($log['total_tokens'] ?? ($pTok + $cTok));

            $totalTokens += $totTok;

            // Recalculate cost using user's configured per-million input/output token pricing
            if ($pTok > 0 || $cTok > 0) {
                $entryCost = (($pTok / 1000000) * $inputPricePerM) + (($cTok / 1000000) * $outputPricePerM);
            } else {
                $entryCost = (float)($log['estimated_cost_usd'] ?? 0.0);
            }
            $totalEstCost += $entryCost;

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
