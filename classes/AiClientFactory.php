<?php
namespace Grav\Plugin\AiChatbot;

/**
 * Class AiClientFactory
 * Creates the appropriate AI driver instance based on plugin config.
 * Supports Groq, Google Gemini, OpenRouter, OpenAI, Ollama (Local/Remote), and Custom endpoints.
 *
 * @license GPL-3.0-or-later
 */
class AiClientFactory
{
    /**
     * Create AI Client instance
     *
     * @param array $config
     * @return AiClientInterface
     */
    public static function create(array $config): AiClientInterface
    {
        $provider = strtolower($config['provider'] ?? 'gemini');
        $apiKey = trim($config['api_key'] ?? '');
        $model = trim($config['model'] ?? '');
        $customEndpoint = trim($config['custom_endpoint'] ?? '');
        $timeout = (int)($config['api_timeout'] ?? 30);
        $maxTokens = (int)($config['max_tokens'] ?? 800);

        switch ($provider) {
            case 'groq':
                return new OpenAiCompatibleClient(
                    $apiKey,
                    $model ?: 'llama-3.3-70b-versatile',
                    'https://api.groq.com/openai/v1',
                    false,
                    $timeout,
                    $maxTokens
                );

            case 'openrouter':
                return new OpenAiCompatibleClient(
                    $apiKey,
                    $model ?: 'google/gemini-flash-1.5',
                    'https://openrouter.ai/api/v1',
                    true,
                    $timeout,
                    $maxTokens
                );

            case 'openai':
                return new OpenAiCompatibleClient(
                    $apiKey,
                    $model ?: 'gpt-4o-mini',
                    'https://api.openai.com/v1',
                    false,
                    $timeout,
                    $maxTokens
                );

            case 'ollama':
                $endpoint = $customEndpoint ?: 'http://host.docker.internal:11434/v1';
                // If endpoint uses localhost or 127.0.0.1 inside container environment, translate to host.docker.internal
                if (preg_match('/localhost|127\.0\.0\.1/i', $endpoint)) {
                    $endpoint = preg_replace('/localhost|127\.0\.0\.1/i', 'host.docker.internal', $endpoint);
                }
                if (!preg_match('/\/v1\/?$/i', $endpoint)) {
                    $endpoint = rtrim($endpoint, '/') . '/v1';
                }
                return new OpenAiCompatibleClient(
                    $apiKey ?: 'ollama',
                    $model ?: 'llama3.2',
                    $endpoint,
                    false,
                    $timeout,
                    $maxTokens
                );

            case 'custom':
                return new OpenAiCompatibleClient(
                    $apiKey,
                    $model ?: 'default',
                    $customEndpoint,
                    false,
                    $timeout,
                    $maxTokens
                );

            case 'gemini':
            default:
                return new GeminiClient(
                    $apiKey,
                    $model ?: 'gemini-2.0-flash',
                    $timeout,
                    $maxTokens
                );
        }
    }
}
