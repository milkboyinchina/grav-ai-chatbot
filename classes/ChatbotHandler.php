<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;

/**
 * Class ChatbotHandler
 * Main request router & orchestrator for AJAX chatbot queries, page summaries, connection tests, and analytics management.
 *
 * @license GPL-3.0-or-later
 */
class ChatbotHandler
{
    protected Grav $grav;
    protected array $config;

    public function __construct(Grav $grav, array $config)
    {
        $this->grav = $grav;
        $this->config = $config;
    }

    /**
     * Entrypoint for processing incoming JSON payload.
     *
     * @param array $data Request payload
     * @return array Response payload
     */
    public function processRequest(array $data): array
    {
        try {
            $question = trim($data['question'] ?? $data['message'] ?? '');
            $action = trim($data['action'] ?? 'query');
            $messagesHistory = $data['history'] ?? [];
            $currentRoute = $data['current_route'] ?? '/';

            if ($action === 'analytics_report') {
                $range = trim($data['range'] ?? 'all');
                $generator = new AnalyticsReportGenerator($this->grav);
                return [
                    'http_code' => 200,
                    'success' => true,
                    'analytics' => $generator->getDashboardAnalyticsData($range)
                ];
            }

            if ($action === 'clear_analytics') {
                $logger = new Logger($this->grav);
                $logger->clearLogs();
                return [
                    'http_code' => 200,
                    'success' => true,
                    'message' => 'All interaction telemetry records have been cleared successfully.'
                ];
            }

            if ($action === 'generate_demo_data') {
                $logger = new Logger($this->grav);
                $count = $this->generateDemoTelemetryData($logger);
                return [
                    'http_code' => 200,
                    'success' => true,
                    'message' => "Successfully generated {$count} realistic sample interaction logs spanning the last 180 days!"
                ];
            }

            if ($action === 'test_connection') {
                return $this->testAiConnection($data);
            }

            if (empty($question) && $action !== 'summarize_page') {
                return [
                    'http_code' => 400,
                    'success' => false,
                    'answer' => 'Please enter a valid question.'
                ];
            }

            // Input Token / Character Length Limit Check
            $maxInputTokens = (int)($this->config['max_input_tokens'] ?? 500);
            $maxInputChars = $maxInputTokens * 4; // Approx 4 chars per token average
            if (!empty($question) && mb_strlen($question) > $maxInputChars) {
                $logger = new Logger($this->grav);
                $logger->logError("Input Limit Exceeded", "User message (" . mb_strlen($question) . " chars) exceeds maximum allowed limit of {$maxInputTokens} tokens ({$maxInputChars} chars).", "INPUT_LIMIT");

                return [
                    'http_code' => 400,
                    'success' => false,
                    'answer' => "⚠️ Message length limit exceeded: Your question exceeds the maximum input limit of {$maxInputTokens} tokens (~{$maxInputChars} characters). Please shorten your question and try again."
                ];
            }

            // IP-Based Rate Limiting Check
            if (!empty($this->config['rate_limit_enabled'])) {
                $maxRequests = (int)($this->config['rate_limit_max_requests'] ?? 10);
                $windowSeconds = (int)($this->config['rate_limit_window_seconds'] ?? 60);

                $limiter = new RateLimiter($this->grav, $this->config);
                $limResult = $limiter->checkRateLimit($maxRequests, $windowSeconds);

                if (!empty($limResult) && empty($limResult['allowed'])) {
                    $logger = new Logger($this->grav);
                    $logger->logInteraction([
                        'question' => $question,
                        'answer' => 'Rate limit exceeded.',
                        'source' => 'rate_limit',
                        'provider' => $this->config['provider'] ?? 'groq'
                    ]);

                    return [
                        'http_code' => 429,
                        'success' => false,
                        'answer' => 'Too many requests. Please wait a minute before asking again.'
                    ];
                }
            }

            if ($action === 'summarize_page') {
                return $this->handleSummarizePage($currentRoute);
            }

            // TIER 0: Blacklisted Words Guardrail Check
            if ($this->config['blacklist_filter_enabled'] ?? true) {
                $rawBlacklist = $this->config['blacklist_words'] ?? "spam, scam, hack, exploit, bypass, admin_password, secret_token, leak, porn, casino, gambling, illegal";
                $words = array_filter(array_map('trim', preg_split('/[\r\n,]+/', strtolower($rawBlacklist))));

                $qLower = strtolower($question);
                $matchedWord = null;

                foreach ($words as $word) {
                    if (!empty($word) && preg_match('/\b' . preg_quote($word, '/') . '\b/i', $qLower)) {
                        $matchedWord = $word;
                        break;
                    }
                }

                if ($matchedWord) {
                    $blockMsg = $this->config['blacklist_response_text'] ?? "Safety Guardrail: Your message contains prohibited words or topics that violate our safety policy. Please rephrase your question using appropriate language.";
                    $logger = new Logger($this->grav);
                    $logger->logInteraction([
                        'question' => $question,
                        'answer' => $blockMsg,
                        'source' => 'guardrail',
                        'provider' => 'safety_filter',
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0
                    ]);

                    return [
                        'http_code' => 200,
                        'success' => true,
                        'answer' => $blockMsg,
                        'source' => 'guardrail'
                    ];
                }
            }

            // TIER 1: Semantic Local FAQ Pre-Matching
            if ($action !== 'force_ai' && ($this->config['faq_enabled'] ?? true)) {
                $faqResolver = new FaqResolver($this->grav, $this->config);
                $faqMatch = $faqResolver->findMatch($question);

                if ($faqMatch) {
                    $logger = new Logger($this->grav);
                    $logger->logInteraction([
                        'question' => $question,
                        'answer' => $faqMatch['answer'],
                        'source' => 'faq_match',
                        'provider' => 'local_faq',
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0
                    ]);

                    return [
                        'http_code' => 200,
                        'success' => true,
                        'answer' => $faqMatch['answer'],
                        'source' => 'faq_match',
                        'similarity' => $faqMatch['similarity']
                    ];
                }
            }

            // TIER 2: Contact Page Intent Resolution
            if ($action !== 'force_ai') {
                $contactResolver = new ContactPageResolver($this->grav, $this->config);
                $contactResponse = $contactResolver->resolveContactIntent($question);
                if ($contactResponse) {
                    $logger = new Logger($this->grav);
                    $logger->logInteraction([
                        'question' => $question,
                        'answer' => $contactResponse['answer'],
                        'source' => 'contact_resolver',
                        'provider' => 'local_contact',
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0
                    ]);

                    return [
                        'http_code' => 200,
                        'success' => true,
                        'answer' => $contactResponse['answer'],
                        'source' => 'contact_resolver'
                    ];
                }
            }

            // TIER 3: AI Model Call (Groq, Gemini, OpenRouter, OpenAI, Ollama, Custom)
            if (!$this->isAiEnabled()) {
                $aiDisabledReply = $this->config['ai_disabled_response_text'] ?? "AI assistant is currently disabled. Please search our FAQ or contact site support for assistance.";
                $logger = new Logger($this->grav);
                $logger->logInteraction([
                    'question' => $question,
                    'answer' => $aiDisabledReply,
                    'source' => 'ai_disabled',
                    'provider' => 'system',
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0
                ]);

                return [
                    'http_code' => 200,
                    'success' => true,
                    'answer' => $aiDisabledReply,
                    'source' => 'ai_disabled'
                ];
            }

            return $this->queryAiModel($question, $currentRoute, $messagesHistory);
        } catch (\Throwable $e) {
            $logger = new Logger($this->grav);
            $logger->logError("Uncaught ChatbotHandler Exception: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString(), 'HANDLER_CRITICAL');

            $customMsg = $this->config['custom_error_message'] ?? 'An unexpected connection error occurred. Please try again later.';
            return [
                'http_code' => 500,
                'success' => false,
                'answer' => $customMsg
            ];
        }
    }

    /**
     * Generate demo telemetry data for testing.
     */
    public function generateDemoTelemetryData(Logger $logger): int
    {
        $logger->clearLogs();
        $sampleQuestions = [
            ["q" => "When was this company established?", "src" => "faq_match", "ans" => "Our company was established in 2020.", "prov" => "groq", "p_tok" => 0, "c_tok" => 0],
            ["q" => "What are your business operating hours?", "src" => "faq_match", "ans" => "Our team operates Monday through Friday from 9:00 AM to 5:00 PM PST.", "prov" => "groq", "p_tok" => 0, "c_tok" => 0],
            ["q" => "How can I contact customer support?", "src" => "faq_match", "ans" => "You can reach our support team by emailing support@example.com.", "prov" => "groq", "p_tok" => 0, "c_tok" => 0],
            ["q" => "What is the corporate office address?", "src" => "faq_match", "ans" => "Our headquarters are located at 100 Tech Plaza, San Francisco, CA.", "prov" => "groq", "p_tok" => 0, "c_tok" => 0],
            ["q" => "Do you offer enterprise custom pricing packages?", "src" => "ai_api", "ans" => "Yes, we offer custom enterprise SLA and volume plans tailored to your organization.", "prov" => "groq", "p_tok" => 480, "c_tok" => 120],
            ["q" => "What AI models are supported by this chatbot?", "src" => "ai_api", "ans" => "The plugin supports Groq (Llama 3), Google Gemini 1.5, OpenRouter, and OpenAI models.", "prov" => "gemini", "p_tok" => 520, "c_tok" => 140],
            ["q" => "Is this chatbot compatible with dark mode themes?", "src" => "ai_api", "ans" => "Yes, it includes 5 visual presets including Emerald Dark and Glassmorphic Blue.", "prov" => "groq", "p_tok" => 410, "c_tok" => 95],
            ["q" => "How can I reset my administrative password?", "src" => "ai_api", "ans" => "You can reset your admin password via CLI or the admin login link.", "prov" => "openai", "p_tok" => 610, "c_tok" => 160],
            ["q" => "Can I export analytics data to CSV or JSON?", "src" => "ai_api", "ans" => "Yes, full interaction reports are exportable in CSV and JSON formats from the dashboard.", "prov" => "groq", "p_tok" => 390, "c_tok" => 85],
            ["q" => "Is my data stored securely?", "src" => "ai_api", "ans" => "Yes, all data remains strictly within your self-hosted Grav instance without external tracking.", "prov" => "groq", "p_tok" => 450, "c_tok" => 110],
        ];

        $records = [];
        $now = time();

        for ($i = 0; $i < 60; $i++) {
            $daysAgo = (int)floor(($i / 60) * 175);
            $timestamp = date('c', $now - ($daysAgo * 86400) - rand(100, 7200));

            $item = $sampleQuestions[$i % count($sampleQuestions)];
            $promptTok = $item['p_tok'] > 0 ? $item['p_tok'] + rand(-50, 50) : 0;
            $compTok = $item['c_tok'] > 0 ? $item['c_tok'] + rand(-20, 30) : 0;
            $totalTok = $promptTok + $compTok;
            $estCost = ($totalTok / 1000000) * 0.15;

            $records[] = [
                'id' => uniqid('demo_', true),
                'timestamp' => $timestamp,
                'ip_hash' => substr(md5('demo_user_' . ($i % 8)), 0, 8),
                'question' => $item['q'],
                'answer' => $item['ans'],
                'source' => $item['src'],
                'provider' => $item['prov'],
                'prompt_tokens' => $promptTok,
                'completion_tokens' => $compTok,
                'total_tokens' => $totalTok,
                'estimated_cost_usd' => round($estCost, 6)
            ];
        }

        $logger->saveLogs($records);
        return count($records);
    }

    /**
     * Test AI Connection via API parameters.
     */
    protected function testAiConnection(array $data): array
    {
        $provider = strtolower(trim($data['provider'] ?? $this->config['provider'] ?? 'groq'));
        $apiKey = trim($data['api_key'] ?? $this->config['api_key'] ?? '');
        $model = trim($data['model'] ?? $this->config['model'] ?? 'llama-3.3-70b-versatile');
        $customEndpoint = trim($data['custom_endpoint'] ?? $this->config['custom_endpoint'] ?? '');

        if (empty($apiKey) && in_array($provider, ['groq', 'gemini', 'openai', 'openrouter'], true)) {
            $msg = "API Key is required for provider '{$provider}'.";
            $logger = new Logger($this->grav);
            $logger->logError($msg, 'TEST_CONNECTION');
            return [
                'http_code' => 400,
                'success' => false,
                'message' => $msg
            ];
        }

        try {
            $client = AiClientFactory::create([
                'provider' => $provider,
                'api_key' => $apiKey,
                'model' => $model,
                'custom_endpoint' => $customEndpoint
            ]);

            $res = $client->generateResponse('Reply with "PONG".', [
                ['role' => 'user', 'content' => 'Ping test connection']
            ]);

            $answer = is_array($res) ? ($res['answer'] ?? '') : (string)$res;
            $success = is_array($res) ? !empty($res['success']) : !empty($answer);

            if ($success && !empty($answer)) {
                return [
                    'http_code' => 200,
                    'success' => true,
                    'message' => "Successfully connected to {$provider} ({$model})! Response: {$answer}"
                ];
            }

            $errMsg = is_array($res) && !empty($res['error']) ? $res['error'] : "Connected to {$provider}, but received empty response payload.";
            $logger = new Logger($this->grav);
            $logger->logError($errMsg, 'TEST_CONNECTION');
            return [
                'http_code' => 500,
                'success' => false,
                'message' => $errMsg
            ];
        } catch (\Throwable $e) {
            $errMsg = "Connection test failed for {$provider} ({$model}): " . $e->getMessage();
            $logger = new Logger($this->grav);
            $logger->logError($errMsg, 'TEST_CONNECTION');
            return [
                'http_code' => 500,
                'success' => false,
                'message' => $errMsg
            ];
        }
    }

    /**
     * Handle page summarization request.
     */
    protected function handleSummarizePage(string $route): array
    {
        $indexer = new ContextIndexer($this->grav);
        $context = $indexer->getIndexedContext();

        $pageContent = '';
        foreach ($context as $page) {
            if ($page['route'] === $route || ($route === '/' && $page['route'] === '/home')) {
                $pageContent = $page['content'];
                break;
            }
        }

        if (empty($pageContent)) {
            $pageContent = implode("\n\n", array_column(array_slice($context, 0, 2), 'content'));
        }

        if (empty($pageContent)) {
            return [
                'http_code' => 200,
                'success' => true,
                'answer' => 'This page does not contain enough text content to summarize.',
                'source' => 'summarize_page'
            ];
        }

        if (!$this->isAiEnabled()) {
            $aiDisabledReply = $this->config['ai_disabled_response_text'] ?? "AI assistant is currently disabled. Please search our FAQ or contact site support for assistance.";
            return [
                'http_code' => 200,
                'success' => true,
                'answer' => $aiDisabledReply,
                'source' => 'ai_disabled',
                'debug_raw_ai_enabled' => $this->config['ai_enabled'] ?? 'MISSING'
            ];
        }

        $systemPrompt = "You are a concise webpage summarizer. Summarize the key points of the webpage in 3 clear bullet points.";
        $prompt = "Summarize this webpage content:\n\n" . substr($pageContent, 0, 3000);

        try {
            $client = AiClientFactory::create($this->config);
            $res = $client->generateResponse($systemPrompt, [
                ['role' => 'user', 'content' => $prompt]
            ]);

            $summary = is_array($res) ? ($res['answer'] ?? '') : (string)$res;

            $logger = new Logger($this->grav);
            $logger->logInteraction([
                'question' => "Summarize Page ({$route})",
                'answer' => $summary,
                'source' => 'summarize_page',
                'provider' => $this->config['provider'] ?? 'groq'
            ]);

            return [
                'http_code' => 200,
                'success' => true,
                'answer' => $summary,
                'source' => 'summarize_page'
            ];
        } catch (\Throwable $e) {
            $errMsg = "Could not summarize page ({$route}): " . $e->getMessage();
            $logger = new Logger($this->grav);
            $logger->logError($errMsg, 'SUMMARIZE_PAGE');
            $customMsg = $this->config['custom_error_message'] ?? 'An unexpected connection error occurred. Please try again later.';
            return [
                'http_code' => 500,
                'success' => false,
                'answer' => $customMsg
            ];
        }
    }

    /**
     * Dispatch prompt to AI Client.
     */
    protected function queryAiModel(string $question, string $currentRoute, array $history): array
    {
        $provider = $this->config['provider'] ?? 'groq';
        $model = $this->config['model'] ?? 'llama-3.3-70b-versatile';

        try {
            $contextWindowTokens = (int)($this->config['context_window_tokens'] ?? 8192);
            $maxOutputTokens = (int)($this->config['max_tokens'] ?? 800);
            $maxInputTokens = (int)($this->config['max_input_tokens'] ?? 500);

            // Dynamically calculate max allowed context prompt characters to respect context_window_tokens limit
            $reservedTokens = $maxOutputTokens + $maxInputTokens + 150; // Output + input + prompt framing
            $maxContextTokens = max(150, $contextWindowTokens - $reservedTokens);
            $maxContextChars = $maxContextTokens * 4; // Approx 4 chars per token average

            $indexer = new ContextIndexer($this->grav);
            $siteContext = $indexer->buildContextPrompt($currentRoute, $maxContextChars);

            $messages = [];
            if (!empty($history) && is_array($history)) {
                foreach ($history as $h) {
                    if (!empty($h['text'])) {
                        $messages[] = [
                            'role' => ($h['role'] === 'user') ? 'user' : 'assistant',
                            'content' => $h['text']
                        ];
                    }
                }
            }
            $messages[] = [
                'role' => 'user',
                'content' => $question
            ];

            $client = AiClientFactory::create($this->config);
            $res = $client->generateResponse($siteContext, $messages);

            $answer = is_array($res) ? ($res['answer'] ?? '') : (string)$res;
            $promptTokens = is_array($res) ? (int)($res['prompt_tokens'] ?? 450) : 450;
            $completionTokens = is_array($res) ? (int)($res['completion_tokens'] ?? 120) : 120;
            $success = is_array($res) ? !empty($res['success']) : !empty($answer);

            if (!$success) {
                $errMsg = is_array($res) && !empty($res['error']) ? $res['error'] : 'Empty or invalid response from AI provider.';
                $logger = new Logger($this->grav);
                $logger->logError("AI Model Query Error [Provider: {$provider}, Model: {$model}]: {$errMsg}", 'AI_MODEL_API');

                if (!empty($this->config['log_ai_responses'])) {
                    $logger->logAiResponse([
                        'provider' => $provider,
                        'model' => $model,
                        'success' => false,
                        'question' => $question,
                        'answer' => '',
                        'prompt_tokens' => $promptTokens,
                        'completion_tokens' => $completionTokens,
                        'error' => $errMsg
                    ]);
                }

                $customMsg = $this->config['custom_error_message'] ?? 'An unexpected connection error occurred. Please try again later.';
                return [
                    'http_code' => 500,
                    'success' => false,
                    'answer' => $customMsg
                ];
            }

            $logger = new Logger($this->grav);
            $logger->logInteraction([
                'question' => $question,
                'answer' => $answer,
                'source' => 'ai_api',
                'provider' => $provider,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens
            ]);

            if (!empty($this->config['log_ai_responses'])) {
                $logger->logAiResponse([
                    'provider' => $provider,
                    'model' => $model,
                    'success' => true,
                    'question' => $question,
                    'answer' => $answer,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'error' => null
                ]);
            }

            return [
                'http_code' => 200,
                'success' => true,
                'answer' => $answer,
                'source' => 'ai_api',
                'provider' => $provider
            ];
        } catch (\Throwable $e) {
            $errMsg = "AI Model Query Failed [Provider: {$provider}, Model: {$model}]: " . $e->getMessage();
            $logger = new Logger($this->grav);
            $logger->logError($errMsg, 'AI_MODEL_API');

            $customMsg = $this->config['custom_error_message'] ?? 'An unexpected connection error occurred. Please try again later.';
            return [
                'http_code' => 500,
                'success' => false,
                'answer' => $customMsg
            ];
        }
    }

    /**
     * Check if AI features are enabled in plugin configuration.
     */
    protected function isAiEnabled(): bool
    {
        $val = $this->config['ai_enabled'] ?? true;
        if (is_bool($val)) {
            return $val;
        }
        if (is_numeric($val)) {
            return (int)$val === 1;
        }
        if (is_string($val)) {
            return in_array(strtolower(trim($val)), ['1', 'true', 'yes', 'on', 'enabled'], true);
        }
        return (bool)$val;
    }
}
