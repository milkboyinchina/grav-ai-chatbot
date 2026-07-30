<?php
namespace Grav\Plugin\AiChatbot;

/**
 * Class OpenAiCompatibleClient
 * Driver for OpenAI Chat Completions API, Groq, OpenRouter & Ollama endpoints.
 *
 * @license GPL-3.0-or-later
 */
class OpenAiCompatibleClient implements AiClientInterface
{
    protected string $apiKey;
    protected string $model;
    protected string $endpoint;
    protected bool $isOpenRouter;
    protected int $timeout;
    protected int $maxTokens;
    protected int $contextWindowTokens;

    public function __construct(string $apiKey, string $model = 'gpt-4o-mini', string $endpoint = '', bool $isOpenRouter = false, int $timeout = 30, int $maxTokens = 800, int $contextWindowTokens = 8192)
    {
        $this->apiKey = $apiKey;
        $this->model = $model ?: 'gpt-4o-mini';
        $this->isOpenRouter = $isOpenRouter;
        $this->timeout = max(5, $timeout);
        $this->maxTokens = max(50, $maxTokens);
        $this->contextWindowTokens = max(512, $contextWindowTokens);

        if (!empty($endpoint)) {
            $this->endpoint = rtrim($endpoint, '/') . '/chat/completions';
        } elseif ($isOpenRouter) {
            $this->endpoint = 'https://openrouter.ai/api/v1/chat/completions';
        } else {
            $this->endpoint = 'https://api.openai.com/v1/chat/completions';
        }
    }

    public function generateResponse(string $systemPrompt, array $messages): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'answer' => 'API Key is missing. Please configure your API key in Grav Admin.',
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'error' => 'Missing API Key'
            ];
        }

        $formattedMessages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        foreach ($messages as $msg) {
            $role = ($msg['role'] === 'assistant' || $msg['role'] === 'model') ? 'assistant' : 'user';
            $formattedMessages[] = [
                'role' => $role,
                'content' => $msg['content']
            ];
        }

        $payload = [
            'model' => $this->model,
            'messages' => $formattedMessages,
            'temperature' => 0.4,
            'max_tokens' => $this->maxTokens,
            'options' => [
                'num_ctx' => $this->contextWindowTokens
            ]
        ];

        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$this->apiKey}"
        ];

        if ($this->isOpenRouter) {
            $headers[] = 'HTTP-Referer: https://github.com/milkboyinchina/grav-ai-chatbot';
            $headers[] = 'X-Title: Grav CMS AI Chatbot';
        }

        $ch = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!empty($curlError)) {
            return [
                'success' => false,
                'answer' => 'AI Service Error: ' . $curlError,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'error' => $curlError
            ];
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !empty($data['error'])) {
            $errorMsg = is_array($data['error'] ?? null) ? ($data['error']['message'] ?? 'Unknown Error') : ($data['error'] ?? "HTTP Error {$httpCode}");
            return [
                'success' => false,
                'answer' => 'AI Service Error: ' . $errorMsg,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'error' => $errorMsg
            ];
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        $promptTokens = (int)($data['usage']['prompt_tokens'] ?? 0);
        $completionTokens = (int)($data['usage']['completion_tokens'] ?? 0);

        return [
            'success' => !empty($content),
            'answer' => trim($content),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'error' => null
        ];
    }
}
