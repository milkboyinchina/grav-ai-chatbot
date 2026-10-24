<?php
namespace Grav\Plugin\AiChatbot;

/**
 * Class AiClientFactory
 * Creates the appropriate AI driver instance based on plugin config.
 * Supports Groq, Google Gemini, OpenRouter, OpenAI, Ollama, and OmniRoute.
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
        $provider = strtolower(trim((string)($config['provider'] ?? 'gemini')));
        $apiKey = trim((string)($config['api_key'] ?? ''));
        $model = trim((string)($config['model'] ?? ''));
        $customEndpoint = rtrim(trim((string)($config['custom_endpoint'] ?? '')), '/');
        $timeout = (int)($config['api_timeout'] ?? 30);
        $maxTokens = (int)($config['max_tokens'] ?? 800);
        $contextWindowTokens = (int)($config['context_window_tokens'] ?? 8192);

        switch ($provider) {
            case 'groq':
                return new OpenAiCompatibleClient(
                    $apiKey,
                    $model ?: 'llama-3.3-70b-versatile',
                    'https://api.groq.com/openai/v1',
                    false,
                    $timeout,
                    $maxTokens,
                    $contextWindowTokens
                );

            case 'openrouter':
                return new OpenAiCompatibleClient(
                    $apiKey,
                    $model ?: 'google/gemini-flash-1.5',
                    'https://openrouter.ai/api/v1',
                    true,
                    $timeout,
                    $maxTokens,
                    $contextWindowTokens
                );

            case 'openai':
                return new OpenAiCompatibleClient(
                    $apiKey,
                    $model ?: 'gpt-4o-mini',
                    'https://api.openai.com/v1',
                    false,
                    $timeout,
                    $maxTokens,
                    $contextWindowTokens
                );

            case 'ollama':
                if ($customEndpoint === '') {
                    throw new \InvalidArgumentException('Ollama requires a custom endpoint URL.');
                }
                if (!preg_match('#/v1/?$#i', $customEndpoint)) {
                    $customEndpoint .= '/v1';
                }
                return new OpenAiCompatibleClient(
                    $apiKey ?: 'ollama',
                    $model ?: 'llama3.2',
                    $customEndpoint,
                    false,
                    $timeout,
                    $maxTokens,
                    $contextWindowTokens
                );

            case 'omniroute':
                if ($customEndpoint === '') {
                    throw new \InvalidArgumentException('OmniRoute requires a custom endpoint URL.');
                }
                return new OpenAiCompatibleClient(
                    $apiKey,
                    $model,
                    $customEndpoint,
                    false,
                    $timeout,
                    $maxTokens,
                    $contextWindowTokens
                );

            case 'gemini':
            default:
                return new GeminiClient(
                    $apiKey,
                    $model ?: 'gemini-3.1-flash-lite',
                    $timeout,
                    $maxTokens
                );
        }
    }
}
