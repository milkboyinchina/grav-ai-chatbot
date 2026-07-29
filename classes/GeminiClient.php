<?php
namespace Grav\Plugin\AiChatbot;

/**
 * Class GeminiClient
 * Driver for Google Gemini API (REST v1beta generateContent).
 *
 * @license GPL-3.0-or-later
 */
class GeminiClient implements AiClientInterface
{
    protected string $apiKey;
    protected string $model;

    public function __construct(string $apiKey, string $model = 'gemini-1.5-flash')
    {
        $this->apiKey = $apiKey;
        $this->model = $model ?: 'gemini-1.5-flash';
    }

    public function generateResponse(string $systemPrompt, array $messages): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'answer' => 'API Key is missing. Please configure your Google Gemini API key in Grav Admin.',
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'error' => 'Missing API Key'
            ];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        // Format contents payload for Gemini REST API
        $contents = [];
        foreach ($messages as $msg) {
            $role = ($msg['role'] === 'assistant' || $msg['role'] === 'model') ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg['content']]]
            ];
        }

        $payload = [
            'contents' => $contents,
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]]
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => 800
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            $errData = json_decode($result, true);
            $errMsg = $errData['error']['message'] ?? $curlError ?: "HTTP Error {$httpCode}";
            return [
                'success' => false,
                'answer' => "AI Service Error: {$errMsg}",
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'error' => $errMsg
            ];
        }

        $data = json_decode($result, true);
        $answerText = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'No response generated.';
        $promptTokens = $data['usageMetadata']['promptTokenCount'] ?? 0;
        $completionTokens = $data['usageMetadata']['candidatesTokenCount'] ?? 0;

        return [
            'success' => true,
            'answer' => trim($answerText),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'error' => null
        ];
    }
}
