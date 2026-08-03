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
    protected string $fallbackEndpoint;
    protected bool $isOpenRouter;
    protected int $timeout;
    protected int $maxTokens;
    protected int $contextWindowTokens;

    public function __construct(string $apiKey, string $model = 'gpt-4o-mini', string $endpoint = '', bool $isOpenRouter = false, int $timeout = 30, int $maxTokens = 800, int $contextWindowTokens = 8192, string $fallbackEndpoint = '')
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

        if (!empty($fallbackEndpoint)) {
            if (preg_match('/localhost|127\.0\.0\.1/i', $fallbackEndpoint)) {
                $fallbackEndpoint = preg_replace('/localhost|127\.0\.0\.1/i', 'host.docker.internal', $fallbackEndpoint);
            }
            if (!preg_match('/\/v1\/?$/i', $fallbackEndpoint) && !preg_match('/\/v1\/chat\/completions\/?$/i', $fallbackEndpoint)) {
                $fallbackEndpoint = rtrim($fallbackEndpoint, '/') . '/v1';
            }
            $this->fallbackEndpoint = rtrim($fallbackEndpoint, '/') . '/chat/completions';
        } else {
            $this->fallbackEndpoint = '';
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
                'num_ctx' => $this->contextWindowTokens,
                'num_predict' => $this->maxTokens
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
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Fallback retry for secondary endpoint if primary connection timed out or failed
        if (!empty($curlError)) {
            $fallbackUrl = $this->fallbackEndpoint;
            if (empty($fallbackUrl) && (strpos($this->endpoint, '100.100.75.77') !== false || strpos($this->endpoint, '192.168.18.12') !== false)) {
                $fallbackUrl = strpos($this->endpoint, '100.100.75.77') !== false
                    ? str_replace('100.100.75.77', '192.168.18.12', $this->endpoint)
                    : str_replace('192.168.18.12', '100.100.75.77', $this->endpoint);
            }

            if (!empty($fallbackUrl)) {
                $chFallback = curl_init($fallbackUrl);
                curl_setopt($chFallback, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chFallback, CURLOPT_POST, true);
                curl_setopt($chFallback, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($chFallback, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($chFallback, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($chFallback, CURLOPT_TIMEOUT, $this->timeout);
                curl_setopt($chFallback, CURLOPT_SSL_VERIFYPEER, true);

                $responseFallback = curl_exec($chFallback);
                $curlErrorFallback = curl_error($chFallback);
                $httpCodeFallback = curl_getinfo($chFallback, CURLINFO_HTTP_CODE);
                curl_close($chFallback);

                if (empty($curlErrorFallback) && $httpCodeFallback === 200) {
                    $response = $responseFallback;
                    $curlError = null;
                    $httpCode = $httpCodeFallback;
                }
            }
        }

        if (!empty($curlError)) {
            return [
                'success' => false,
                'answer' => 'Our AI model is currently busy or taking longer to warm up. Please try again in a few seconds or check our FAQ for quick answers.',
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
                'answer' => 'AI Service is currently undergoing maintenance. Please try again later.',
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'error' => $errorMsg
            ];
        }

        $messageObj = $data['choices'][0]['message'] ?? [];
        $rawContent = $messageObj['content'] ?? '';
        $rawReasoning = $messageObj['reasoning'] ?? '';

        $cleanContent = $this->sanitizeCotOutput($rawContent);
        $answer = $cleanContent;

        if (empty($answer) && !empty($rawReasoning)) {
            try {
                if (class_exists('Grav\Common\Grav')) {
                    $logger = new Logger(\Grav\Common\Grav::instance());
                    $logger->logError("AI Model returned empty content; fallback to reasoning applied [Model: {$this->model}].", 'AI_MODEL_API');
                }
            } catch (\Throwable $t) {}
            $answer = $this->sanitizeCotOutput($rawReasoning);
        }

        $promptTokens = (int)($data['usage']['prompt_tokens'] ?? 0);
        $completionTokens = (int)($data['usage']['completion_tokens'] ?? 0);

        return [
            'success' => !empty($answer),
            'answer' => $answer,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'error' => null
        ];
    }

    private function sanitizeCotOutput(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Layer 1: Remove closed <think>...</think> tags
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text);

        // Layer 2: Remove unclosed <think>... tags to end of string
        $text = preg_replace('/<think>.*?$/s', '', $text);

        $trimmed = trim($text);

        // Layer 3: Header-based CoT monologue stripping
        $monologueHeaders = '(?:Thinking Process|Thought|Thinking|Scratchpad|Internal Monologue|Reasoning|Analysis|Chain of Thought|Step-by-step|My reasoning)';

        if (preg_match('/^' . $monologueHeaders . ':\s*[\s\S]*?\n\s*\n+(.*)/i', $trimmed, $matches)) {
            $text = $matches[1];
        } elseif (preg_match('/^' . $monologueHeaders . ':\s*[\s\S]*/i', $trimmed)) {
            $text = '';
        }

        // Layer 4: Numbered analysis steps (e.g., "1. **Analyze...**", "2. **Determine Intent:**")
        $trimmed2 = trim($text);
        if (preg_match('/^\d+\.\s*\*\*(?:Analyze|Determine|Formulate|Identify|Evaluate|Check|Understand|Plan|Assess|Consider|Review|Interpret)[^*]*\*\*[\s\S]*?\n\s*\n+(.*)/i', $trimmed2, $m2)) {
            if (!preg_match('/^\d+\.\s*\*\*/i', trim($m2[1]))) {
                $text = $m2[1];
            } else {
                $text = $this->sanitizeCotOutput($m2[1]);
            }
        } elseif (preg_match('/^\d+\.\s*\*\*(?:Analyze|Determine|Formulate|Identify|Evaluate|Check|Understand|Plan|Assess|Consider|Review|Interpret)[^*]*\*\*/i', $trimmed2)) {
            $text = '';
        }

        // Layer 5: General self-referential monologue detector
        // Catches informal reasoning like "Wait, re-reading the input...", "Actually, looking at...",
        // "Let me think...", "Hmm, the user is asking...", "OK so the user..."
        $trimmed3 = trim($text);
        $selfRefIndicators = '/(?:re-reading|the input|the user|the request|the prompt|the question|looking at this|let me (?:think|analyze|consider|re-read|check)|I need to (?:figure|analyze|determine|understand)|what .+ asking|their (?:question|request|query|intent))/i';
        $monologueStarters = '/^(?:Wait|Actually|Hmm|OK|Okay|So|Now|Let me|Alright|Right|First|Well),?\s/i';

        if (preg_match($monologueStarters, $trimmed3) && preg_match($selfRefIndicators, $trimmed3)) {
            // The response starts with a thinking cue and references the user/input — it's internal monologue
            // Try to extract an actual answer after a double-newline break
            if (preg_match('/\n\s*\n+((?!(?:Wait|Actually|Hmm|OK|Okay|So|Now|Let me|Alright|Right|First|Well),?\s).*)/si', $trimmed3, $m3)) {
                $candidate = trim($m3[1]);
                if (!empty($candidate) && !preg_match($selfRefIndicators, $candidate)) {
                    $text = $candidate;
                } else {
                    $text = '';
                }
            } else {
                $text = '';
            }
        }

        return trim($text);
    }
}
