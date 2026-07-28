<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;

/**
 * Class Logger
 * Logs visitor interactions, source (FAQ vs AI), prompt & completion tokens, and estimated API costs.
 *
 * @license GPL-3.0-or-later
 */
class Logger
{
    protected Grav $grav;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
    }

    /**
     * Record an interaction entry.
     */
    public function logInteraction(array $entry): void
    {
        $logFile = $this->getLogFilePath();
        $dir = dirname($logFile);

        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        $logs = [];
        if (file_exists($logFile)) {
            $raw = file_get_contents($logFile);
            $logs = json_decode($raw, true) ?: [];
        }

        // Calculate estimated cost in USD (approx $0.15 per 1M tokens for Gemini/Flash)
        $promptTokens = $entry['prompt_tokens'] ?? 0;
        $completionTokens = $entry['completion_tokens'] ?? 0;
        $totalTokens = $promptTokens + $completionTokens;
        $estCost = ($totalTokens / 1000000) * 0.15;

        $record = [
            'id' => uniqid('log_', true),
            'timestamp' => date('c'),
            'ip_hash' => substr(md5($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 8),
            'question' => trim($entry['question'] ?? ''),
            'answer' => trim($entry['answer'] ?? ''),
            'source' => $entry['source'] ?? 'ai_api', // 'faq_match', 'ai_api', 'rate_limit', 'guardrail'
            'provider' => $entry['provider'] ?? 'gemini',
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost_usd' => round($estCost, 6)
        ];

        // Append record and keep last 500 records
        array_unshift($logs, $record);
        if (count($logs) > 500) {
            $logs = array_slice($logs, 0, 500);
        }

        file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));
    }

    /**
     * Read all logged interaction entries.
     */
    public function getLogs(): array
    {
        $logFile = $this->getLogFilePath();
        if (!file_exists($logFile)) {
            return [];
        }
        $raw = file_get_contents($logFile);
        return json_decode($raw, true) ?: [];
    }

    protected function getLogFilePath(): string
    {
        $locator = $this->grav['locator'];
        return $locator->findResource('user://data') . '/ai-chatbot/interactions.json';
    }
}
