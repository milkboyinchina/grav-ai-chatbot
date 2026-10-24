<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;

/**
 * Class Logger
 * Logs visitor interactions, source (FAQ vs AI), prompt & completion tokens, estimated API costs,
 * server AI response payloads, and maintains dedicated error logs in user/data/ai-chatbot/error.log.
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
            'ip_hash' => substr(hash('sha256', RateLimiter::getClientIp() . '|ai-chatbot'), 0, 16),
            'question' => trim($entry['question'] ?? ''),
            'answer' => trim($entry['answer'] ?? ''),
            'source' => $entry['source'] ?? 'ai_api', // 'faq_match', 'ai_api', 'rate_limit', 'guardrail'
            'source_page' => $entry['source_page'] ?? $_SERVER['HTTP_REFERER'] ?? $_SERVER['REQUEST_URI'] ?? '/',
            'provider' => $entry['provider'] ?? 'gemini',
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost_usd' => round($estCost, 6)
        ];

        // Append record and keep last 500 records under a single exclusive lock.
        $this->updateJsonLocked($logFile, static function (array $logs) use ($record): array {
            array_unshift($logs, $record);
            return array_slice($logs, 0, 500);
        });
    }

    /**
     * Clear all interaction logs.
     */
    public function clearLogs(): void
    {
        $this->writeJsonLocked($this->getLogFilePath(), []);
    }

    /**
     * Bulk save interaction logs.
     */
    public function saveLogs(array $logs): void
    {
        $this->writeJsonLocked($this->getLogFilePath(), array_values($logs));
    }

    /**
     * Read all logged interaction entries.
     */
    public function getLogs(): array
    {
        return $this->readJsonLocked($this->getLogFilePath());
    }

    /**
     * Record raw AI provider response to user/data/ai-chatbot/ai_responses.log.
     */
    public function logAiResponse(array $data): void
    {
        $logFile = $this->getAiResponseLogFilePath();

        $timestamp = date('Y-m-d H:i:s P');
        $provider = $data['provider'] ?? 'ai';
        $model = $data['model'] ?? 'default';
        $status = !empty($data['success']) ? 'SUCCESS' : 'FAILURE';
        $promptTokens = (int)($data['prompt_tokens'] ?? 0);
        $completionTokens = (int)($data['completion_tokens'] ?? 0);
        $question = trim($data['question'] ?? '');
        $answer = trim($data['answer'] ?? '');
        $error = $data['error'] ?? null;

        $logEntry = sprintf(
            "[%s] [%s] [PROVIDER: %s] [MODEL: %s] [TOKENS: %d in / %d out]\nQuestion: %s\nAnswer: %s%s\n--------------------------------------------------\n",
            $timestamp,
            $status,
            strtoupper($provider),
            $model,
            $promptTokens,
            $completionTokens,
            $question,
            $answer,
            $error ? "\nError: {$error}" : ''
        );

        $this->appendLocked($logFile, $logEntry);
    }

    /**
     * Record a formatted error entry to user/data/ai-chatbot/error.log.
     */
    public function logError(string $message, string $context = 'GENERAL'): void
    {
        $errorFile = $this->getErrorLogFilePath();

        $timestamp = date('Y-m-d H:i:s P');
        $logLine = sprintf("[%s] [ERROR] [%s] %s\n", $timestamp, strtoupper($context), trim($message));

        $this->appendLocked($errorFile, $logLine);
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
            file_put_contents($errorFile, '', LOCK_EX);
        }
    }

    protected function getLogFilePath(): string
    {
        return DataPath::file('interactions.json');
    }

    public function getAiResponseLogFilePath(): string
    {
        return DataPath::file('ai_responses.log');
    }

    public function getErrorLogFilePath(): string
    {
        return DataPath::file('error.log');
    }

    /**
     * Read a JSON array while holding a shared lock.
     */
    protected function readJsonLocked(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return [];
            }
            $raw = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return json_decode((string)$raw, true) ?: [];
    }

    /**
     * Mutate a JSON array while holding an exclusive lock across read and write.
     */
    protected function updateJsonLocked(string $file, callable $mutator): void
    {
        DataPath::ensureDirectory();

        $handle = fopen($file, 'c+b');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            $raw = stream_get_contents($handle);
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                $data = [];
            }
            $data = $mutator($data);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Replace a JSON file while holding an exclusive lock.
     */
    protected function writeJsonLocked(string $file, array $data): void
    {
        DataPath::ensureDirectory();

        $handle = fopen($file, 'cb');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Append a text line while holding an exclusive lock.
     */
    protected function appendLocked(string $file, string $line): void
    {
        DataPath::ensureDirectory();

        $handle = fopen($file, 'ab');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}
