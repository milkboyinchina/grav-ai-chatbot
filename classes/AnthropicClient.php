<?php
declare(strict_types=1);

namespace Grav\Plugin\AiChatbot;

/**
 * Anthropic Claude client using the Messages API.
 */
class AnthropicClient implements AiClientInterface
{
    private string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxTokens;

    public function __construct(string $apiKey, string $model = 'claude-sonnet-4-5', int $timeout = 30, int $maxTokens = 800)
    {
        $this->apiKey = trim($apiKey);
        $this->model = $model ?: 'claude-sonnet-4-5';
        $this->timeout = max(5, $timeout);
        $this->maxTokens = max(50, $maxTokens);
    }

    public function generateResponse(string $systemPrompt, array $messages): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'answer' => 'API Key is missing. Please configure your Anthropic API key in Grav Admin.',
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'error' => 'Missing API Key'
            ];
        }

        $contents = [];
        foreach ($messages as $msg) {
            $role = (!empty($msg['role']) && ($msg['role'] === 'assistant' || $msg['role'] === 'model')) ? 'assistant' : 'user';
            $text = $msg['content'] ?? $msg['text'] ?? '';
            if (!empty($text)) {
                $contents[] = [
                    'role' => $role,
                    'content' => $text
                ];
            }
        }

        if (empty($contents)) {
            $contents[] = [
                'role' => 'user',
                'content' => 'Hello'
            ];
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $systemPrompt,
            'messages' => $contents
        ];

        $url = 'https://api.anthropic.com/v1/messages';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'answer' => 'cURL Connection Error: ' . $curlError,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'error' => $curlError
            ];
        }

        $json = json_decode((string)$result, true);
        if (!is_array($json)) {
            return [
                'success' => false,
                'answer' => 'Invalid JSON response from Anthropic API.',
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'error' => 'Invalid JSON'
            ];
        }

        if ($httpCode >= 400) {
            $errMsg = $json['error']['message'] ?? ('HTTP ' . $httpCode);
            return [
                'success' => false,
                'answer' => 'Anthropic API Error: ' . $errMsg,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'error' => $errMsg
            ];
        }

        $answer = '';
        if (isset($json['content']) && is_array($json['content'])) {
            foreach ($json['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $answer .= ($block['text'] ?? '');
                }
            }
        }

        $promptTokens = (int)($json['usage']['input_tokens'] ?? 0);
        $completionTokens = (int)($json['usage']['output_tokens'] ?? 0);

        return [
            'success' => !empty($answer),
            'answer' => trim($answer),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'error' => empty($answer) ? 'Empty response from Anthropic API.' : null
        ];
    }
}
