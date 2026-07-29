<?php
namespace Grav\Plugin\AiChatbot;

/**
 * Interface AiClientInterface
 * Unified contract for AI model REST API client implementations.
 *
 * @license GPL-3.0-or-later
 */
interface AiClientInterface
{
    /**
     * Send prompt messages to the AI provider and return the string response.
     *
     * @param string $systemPrompt
     * @param array $messages Array of message objects [['role' => 'user|assistant', 'content' => '...']]
     * @return array Responding with ['success' => bool, 'answer' => string, 'prompt_tokens' => int, 'completion_tokens' => int, 'error' => string|null]
     */
    public function generateResponse(string $systemPrompt, array $messages): array;
}
