<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;

/**
 * Class Logger
 * Logs visitor interactions, source (FAQ vs AI), prompt & completion tokens, estimated API costs,
 * and maintains dedicated error logs in user/data/ai-chatbot/error.log.
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

        $config = $this->grav['config']->get('plugins.ai-chatbot', []);
        $inputPricePerM = (float)($config['cost_input_token_price_per_m'] ?? 0.15);
        $outputPricePerM = (float)($config['cost_output_token_price_per_m'] ?? 0.60);

        $promptTokens = (int)($entry['prompt_tokens'] ?? 0);
        $completionTokens = (int)($entry['completion_tokens'] ?? 0);
        $totalTokens = $promptTokens + $completionTokens;

        // Formula: (Prompt Tokens / 1,000,000 * Input Price) + (Completion Tokens / 1,000,000 * Output Price)
        $estCost = (($promptTokens / 1000000) * $inputPricePerM) + (($completionTokens / 1000000) * $outputPricePerM);

        $record = [
            'id' => uniqid('log_', true),
            'timestamp' => $entry['timestamp'] ?? date('c'),
            'ip_hash' => substr(md5($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 8),
            'question' => trim($entry['question'] ?? ''),
            'answer' => trim($entry['answer'] ?? ''),
            'source' => $entry['source'] ?? 'ai_api', // 'faq_match', 'ai_api', 'rate_limit', 'guardrail'
            'provider' => $entry['provider'] ?? 'groq',
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
     * Clear all interaction logs.
     */
    public function clearLogs(): void
    {
        $logFile = $this->getLogFilePath();
        if (file_exists($logFile)) {
            file_put_contents($logFile, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    /**
     * Bulk save interaction logs.
     */
    public function saveLogs(array $logs): void
    {
        $logFile = $this->getLogFilePath();
        $dir = dirname($logFile);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($logFile, json_encode(array_values($logs), JSON_PRETTY_PRINT));
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

    /**
     * Record a formatted error entry to user/data/ai-chatbot/error.log.
     */
    public function logError(string $message, string $context = 'GENERAL'): void
    {
        $errorFile = $this->getErrorLogFilePath();
        $dir = dirname($errorFile);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s P');
        $logLine = sprintf("[%s] [ERROR] [%s] %s\n", $timestamp, strtoupper($context), trim($message));

        file_put_contents($errorFile, $logLine, FILE_APPEND);
    }

    /**
     * Get error log contents.
     */
    public function getErrorLogs(): string
    {
        $errorFile = $this->getErrorLogFilePath();
        if (!file_exists($errorFile) || filesize($errorFile) === 0) {
            return "No error logs recorded. Plugin operating normally.";
        }

        return file_get_contents($errorFile);
    }

    /**
     * Clear error log file.
     */
    public function clearErrorLogs(): void
    {
        $errorFile = $this->getErrorLogFilePath();
        if (file_exists($errorFile)) {
            file_put_contents($errorFile, '');
        }
    }

    protected function getLogFilePath(): string
    {
        $locator = $this->grav['locator'];
        return $locator->findResource('user://data') . '/ai-chatbot/interactions.json';
    }

    public function getErrorLogFilePath(): string
    {
        $locator = $this->grav['locator'];
        return $locator->findResource('user://data') . '/ai-chatbot/error.log';
    }
}
