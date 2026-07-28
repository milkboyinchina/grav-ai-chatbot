<?php
namespace Grav\Plugin\AiChatbot;

/**
 * Class AiClientFactory
 * Creates the appropriate AI driver instance based on plugin config.
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

        switch ($provider) {
            case 'openrouter':
                return new OpenAiCompatibleClient($apiKey, $model ?: 'google/gemini-flash-1.5', '', true);

            case 'openai':
                return new OpenAiCompatibleClient($apiKey, $model ?: 'gpt-4o-mini', '', false);

            case 'custom':
                return new OpenAiCompatibleClient($apiKey, $model ?: 'default', $customEndpoint, false);

            case 'gemini':
            default:
                return new GeminiClient($apiKey, $model ?: 'gemini-1.5-flash');
        }
    }
}
