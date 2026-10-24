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
    protected ?Grav $grav = null;
    protected array $config = [];

    public function __construct(?Grav $grav = null, array $config = [])
    {
        $this->grav = $grav ?? (class_exists('Grav\Common\Grav') ? Grav::instance() : null);
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
            $action = trim($data['action'] ?? $_GET['action'] ?? 'query');
            $messagesHistory = $data['history'] ?? [];
            $currentRoute = $data['current_route'] ?? '/';

            if ($action === 'test_api_key') {
                return $this->testApiKey($data);
            }

            if ($action === 'fetch_models') {
                return $this->fetchModels($data);
            }

            if ($action === 'test_model_health') {
                return $this->testModelHealth($data);
            }

            $nonQuestionActions = ['summarize_page', 'rebuild_rag_index', 'get_metrics', 'get_security_logs', 'fetch_models', 'test_model_health', 'test_api_key'];
            if (empty($question) && !in_array($action, $nonQuestionActions, true)) {
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

            if ($action === 'rebuild_rag_index') {
                $ragIndexer = new \Grav\Plugin\AiChatbot\Rag\Indexer($this->grav, $this->config);
                $res = $ragIndexer->runFullIndex(true);
                return [
                    'http_code' => 200,
                    'success' => $res['success'] ?? false,
                    'message' => $res['message'] ?? ($res['error'] ?? 'RAG index update complete.')
                ];
            }

            if ($action === 'get_metrics') {
                $generator = new AnalyticsReportGenerator($this->grav);
                $analytics = $generator->getDashboardAnalyticsData();
                $summary = $analytics['summary'] ?? [];

                return [
                    'http_code' => 200,
                    'success' => true,
                    'provider' => $this->config['provider'] ?? 'gemini',
                    'model' => $this->config['model'] ?? 'chatbot',
                    'stats' => [
                        'total_queries' => $summary['total_queries'] ?? 0,
                        'completion_tokens' => $summary['total_tokens'] ?? 0,
                        'estimated_cost' => $summary['total_cost_usd'] ?? 0.0
                    ],
                    'analytics' => $analytics
                ];
            }

            if ($action === 'get_security_logs') {
                $data = SecurityGuardrail::getSecurityAuditData($this->grav);
                return [
                    'http_code' => 200,
                    'success' => true,
                    'data' => $data
                ];
            }

            // Server-Side Input Query Truncation (Max 500 chars / ~125 tokens)
            if (mb_strlen($question) > 500) {
                $question = mb_substr($question, 0, 500);
            }

            // TIER 0: Security Guardrail Inspection & IP Cool-Off Protection
            $ipHash = hash('sha256', RateLimiter::getClientIp());

            if (SecurityGuardrail::isIpLockedOut($ipHash)) {
                $blockMsg = "⚠️ Security Protection: Excessive security violations detected from your network. Access is temporarily locked out for 15 minutes. Please try again later.";
                return [
                    'http_code' => 429,
                    'success' => false,
                    'answer' => $blockMsg,
                    'source' => 'security_cooloff'
                ];
            }

            if ($this->config['blacklist_filter_enabled'] ?? true) {
                $guardrail = new SecurityGuardrail($this->grav, $this->config);
                $inspection = $guardrail->inspect($question);

                if (!$inspection['allowed']) {
                    SecurityGuardrail::recordViolation($ipHash);

                    $securityData = $this->config['blacklist_response_text'] ?? "⚠️ Safety Guardrail: Your message contains prohibited words or topics that violate our safety policy. Please rephrase your question using appropriate language.";

                    $logger = new Logger($this->grav);
                    // Do not persist raw visitor input in error logs (PII / prompt-injection payloads).
                    $logger->logError("Security Guardrail Blocked Query [IP Hash: " . substr($ipHash, 0, 8) . "]: " . $inspection['reason'] . " (chars=" . mb_strlen($question) . ")", 'GUARDRAIL');
                    $logger->logInteraction([
                        'question' => $question,
                        'answer' => $securityData,
                        'source' => 'guardrail',
                        'provider' => 'security_filter',
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0
                    ]);

                    return [
                        'http_code' => 200,
                        'success' => true,
                        'answer' => $securityData,
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

    protected function sanitizeProvider(string $provider): string
    {
        $allowed = ['gemini', 'groq', 'openai', 'openrouter', 'ollama', 'omniroute'];
        $clean = strtolower(trim($provider));
        return in_array($clean, $allowed, true) ? $clean : 'gemini';
    }

    /**
     * Resolve and validate a request-supplied custom endpoint.
     *
     * @return array{endpoint: string, error: ?string}
     */
    protected function resolveCustomEndpoint(array $data): array
    {
        $endpoint = trim((string)($data['custom_endpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = trim((string)($this->config['custom_endpoint'] ?? ''));
        }
        if ($endpoint === '') {
            return ['endpoint' => '', 'error' => 'Endpoint URL is required for this provider.'];
        }

        $scheme = strtolower((string)parse_url($endpoint, PHP_URL_SCHEME));
        $host = (string)parse_url($endpoint, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['endpoint' => '', 'error' => 'Endpoint URL must be a valid http or https URL.'];
        }

        return ['endpoint' => rtrim($endpoint, '/'), 'error' => null];
    }

    /**
     * Build the client config, validating provider endpoints first.
     *
     * @return array{config: ?array, error: ?string}
     */
    protected function resolveClientConfig(): array
    {
        $config = $this->config;
        $provider = $this->sanitizeProvider((string)($config['provider'] ?? 'gemini'));
        $config['provider'] = $provider;

        if (in_array($provider, ['omniroute', 'ollama'], true)) {
            $resolved = $this->resolveCustomEndpoint($config);
            if ($resolved['error'] !== null) {
                return ['config' => null, 'error' => $resolved['error']];
            }
            $config['custom_endpoint'] = $resolved['endpoint'];
        }

        return ['config' => $config, 'error' => null];
    }

    /**
     * Test API Key authentication with provider API endpoint.
     */
    protected function testApiKey(array $data): array
    {
        $provider = $this->sanitizeProvider($data['provider'] ?? $this->config['provider'] ?? 'gemini');
        $apiKey = trim($data['api_key'] ?? '');
        if (empty($apiKey)) {
            $apiKey = trim($this->config['api_key'] ?? '');
        }
        $customEndpoint = '';
        if (in_array($provider, ['omniroute', 'ollama'], true)) {
            $endpointCheck = $this->resolveCustomEndpoint($data);
            if ($endpointCheck['error'] !== null) {
                return [
                    'http_code' => 400,
                    'success' => false,
                    'message' => '❌ ' . $endpointCheck['error']
                ];
            }
            $customEndpoint = $endpointCheck['endpoint'];
        }

        if (empty($apiKey) && in_array($provider, ['groq', 'gemini', 'openai', 'openrouter'], true)) {
            return [
                'http_code' => 400,
                'success' => false,
                'message' => "❌ API Key is required for provider '{$provider}'."
            ];
        }

        try {
            if ($provider === 'gemini') {
                $url = 'https://generativelanguage.googleapis.com/v1beta/models';
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $apiKey],
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_FOLLOWLOCATION => true
                ]);
                $response = curl_exec($ch);
                $curlErr = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    return [
                        'http_code' => 200,
                        'success' => true,
                        'message' => "✅ Google Gemini API Key is Valid & Authenticated!"
                    ];
                }

                if (!empty($curlErr)) {
                    return [
                        'http_code' => 500,
                        'success' => false,
                        'message' => "❌ cURL Connection Error to Google Gemini API: {$curlErr}"
                    ];
                }

                $json = json_decode($response, true);
                $err = $json['error']['message'] ?? "HTTP {$httpCode} Authentication Failed";

                return [
                    'http_code' => $httpCode,
                    'success' => false,
                    'message' => "❌ Gemini API Key test failed: {$err}"
                ];
            } else {
                $endpoint = '';
                if ($provider === 'groq') {
                    $endpoint = 'https://api.groq.com/openai/v1/models';
                } elseif ($provider === 'openrouter') {
                    $endpoint = 'https://openrouter.ai/api/v1/models';
                } elseif ($provider === 'openai') {
                    $endpoint = 'https://api.openai.com/v1/models';
                } else {
                    $base = rtrim($customEndpoint, '/');
                    $endpoint = str_ends_with($base, '/v1') ? "{$base}/models" : "{$base}/v1/models";
                }

                $headers = ['Content-Type: application/json'];
                if (!empty($apiKey)) {
                    $headers[] = "Authorization: Bearer {$apiKey}";
                }

                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_FOLLOWLOCATION => true
                ]);
                $response = curl_exec($ch);
                $curlErr = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 || $httpCode === 201) {
                    return [
                        'http_code' => 200,
                        'success' => true,
                        'message' => "✅ API Key is Valid & Authenticated against " . strtoupper($provider) . " API!"
                    ];
                }

                if (!empty($curlErr)) {
                    return [
                        'http_code' => 500,
                        'success' => false,
                        'message' => "❌ cURL Connection Error to " . strtoupper($provider) . " API: {$curlErr}"
                    ];
                }

                $json = json_decode($response, true);
                $err = $json['error']['message'] ?? $json['message'] ?? "HTTP {$httpCode} Authentication Failed";
                return [
                    'http_code' => 401,
                    'success' => false,
                    'message' => "❌ API Key test failed (" . strtoupper($provider) . "): {$err}"
                ];
            }
        } catch (\Throwable $e) {
            return [
                'http_code' => 500,
                'success' => false,
                'message' => "❌ API Key verification error: " . $e->getMessage()
            ];
        }
    }

    /**
     * Retrieve active model list from provider's API endpoint.
     */
    protected function fetchModels(array $data): array
    {
        $provider = $this->sanitizeProvider($data['provider'] ?? $this->config['provider'] ?? 'gemini');
        $apiKey = trim($data['api_key'] ?? '');
        if (empty($apiKey)) {
            $apiKey = trim($this->config['api_key'] ?? '');
        }
        $customEndpoint = '';
        if (in_array($provider, ['omniroute', 'ollama'], true)) {
            $endpointCheck = $this->resolveCustomEndpoint($data);
            if ($endpointCheck['error'] !== null) {
                return [
                    'http_code' => 400,
                    'success' => false,
                    'message' => '❌ ' . $endpointCheck['error']
                ];
            }
            $customEndpoint = $endpointCheck['endpoint'];
        }

        try {
            $models = [];
            if ($provider === 'gemini') {
                if (empty($apiKey)) {
                    return ['http_code' => 400, 'success' => false, 'message' => 'API Key is required to fetch Gemini models.'];
                }
                $url = 'https://generativelanguage.googleapis.com/v1beta/models';
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $apiKey],
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && !empty($response)) {
                    $json = json_decode($response, true);
                    foreach ($json['models'] ?? [] as $m) {
                        $name = str_replace('models/', '', $m['name'] ?? '');
                        if (!empty($name) && str_contains($name, 'gemini')) {
                            $models[] = $name;
                        }
                    }
                }
            } else {
                // OpenAI Compatible / Groq / OpenRouter / Ollama / Custom
                $endpoint = '';
                if ($provider === 'groq') {
                    $endpoint = 'https://api.groq.com/openai/v1/models';
                } elseif ($provider === 'openrouter') {
                    $endpoint = 'https://openrouter.ai/api/v1/models';
                } elseif ($provider === 'openai') {
                    $endpoint = 'https://api.openai.com/v1/models';
                } else {
                    $base = rtrim($customEndpoint, '/');
                    $endpoint = str_ends_with($base, '/v1') ? "{$base}/models" : "{$base}/v1/models";
                }

                $headers = ['Content-Type: application/json'];
                if (!empty($apiKey)) {
                    $headers[] = "Authorization: Bearer {$apiKey}";
                }

                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (($httpCode === 200 || $httpCode === 201) && !empty($response)) {
                    $json = json_decode($response, true);
                    foreach ($json['data'] ?? [] as $m) {
                        if (!empty($m['id'])) {
                            $models[] = $m['id'];
                        }
                    }
                }
            }

            if (empty($models)) {
                $defaults = [
                    'gemini' => ['gemini-3.1-flash-lite', 'gemini-2.0-flash', 'gemini-2.0-flash-lite'],
                    'groq' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768'],
                    'openai' => ['gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo'],
                    'openrouter' => ['google/gemini-flash-1.5', 'anthropic/claude-3.5-sonnet', 'meta-llama/llama-3.3-70b-instruct'],
                    'omniroute' => ['gemini/gemini-3.1-flash-lite', 'gemini/gemini-2.5-flash', 'openai/gpt-4o-mini', 'llama3.3', 'qwen2.5-coder']
                ];
                $models = $defaults[$provider] ?? ['gemini/gemini-3.1-flash-lite', 'gpt-4o-mini', 'llama3.3'];
            }

            return [
                'http_code' => 200,
                'success' => true,
                'provider' => $provider,
                'models' => array_values(array_unique($models)),
                'message' => 'Successfully fetched ' . count($models) . ' active models from provider API.'
            ];
        } catch (\Throwable $e) {
            return [
                'http_code' => 500,
                'success' => false,
                'message' => 'Failed to fetch models: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send a live health check ping request to specific upstream AI model endpoint.
     */
    protected function testModelHealth(array $data): array
    {
        $provider = $this->sanitizeProvider($data['provider'] ?? $this->config['provider'] ?? 'gemini');
        $apiKey = trim($data['api_key'] ?? '');
        if (empty($apiKey)) {
            $apiKey = trim($this->config['api_key'] ?? '');
        }
        $model = trim($data['model'] ?? '');
        if (empty($model)) {
            $model = trim($this->config['model'] ?? 'gemini-3.1-flash-lite');
        }
        $customEndpoint = '';
        if (in_array($provider, ['omniroute', 'ollama'], true)) {
            $endpointCheck = $this->resolveCustomEndpoint($data);
            if ($endpointCheck['error'] !== null) {
                return [
                    'http_code' => 400,
                    'success' => false,
                    'message' => '❌ ' . $endpointCheck['error']
                ];
            }
            $customEndpoint = $endpointCheck['endpoint'];
        }

        if (empty($model)) {
            return [
                'http_code' => 400,
                'success' => false,
                'message' => 'Please specify a Model Identifier to test.'
            ];
        }

        $startTime = microtime(true);
        try {
            $client = AiClientFactory::create([
                'provider' => $provider,
                'api_key' => $apiKey,
                'model' => $model,
                'custom_endpoint' => $customEndpoint,
                'api_timeout' => 12,
                'max_tokens' => 10
            ]);

            $res = $client->generateResponse('Ping health check.', [
                ['role' => 'user', 'content' => 'health_check']
            ]);

            $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
            $success = is_array($res) ? !empty($res['success']) : false;

            if ($success) {
                return [
                    'http_code' => 200,
                    'success' => true,
                    'model' => $model,
                    'latency_ms' => $latencyMs,
                    'message' => "✅ Model '{$model}' is awake, responsive, and handling requests properly ({$latencyMs}ms)."
                ];
            }

            $errMsg = is_array($res) && !empty($res['error']) ? $res['error'] : 'Received empty ping payload.';
            return [
                'http_code' => 500,
                'success' => false,
                'model' => $model,
                'latency_ms' => $latencyMs,
                'message' => "❌ Model '{$model}' health test failed ({$latencyMs}ms): {$errMsg}"
            ];
        } catch (\Throwable $e) {
            $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
            return [
                'http_code' => 500,
                'success' => false,
                'model' => $model,
                'latency_ms' => $latencyMs,
                'message' => "❌ Model '{$model}' health test failed ({$latencyMs}ms): " . $e->getMessage()
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
                'source' => 'ai_disabled'
            ];
        }

        $systemPrompt = "You are a concise webpage summarizer. Summarize the key points of the webpage in 3 clear bullet points.";
        $prompt = "Summarize this webpage content:\n\n" . substr($pageContent, 0, 3000);

        try {
            $clientConfig = $this->resolveClientConfig();
            if ($clientConfig['error'] !== null) {
                return [
                    'http_code' => 400,
                    'success' => false,
                    'answer' => 'Chatbot is not configured: ' . $clientConfig['error'],
                ];
            }
            $client = AiClientFactory::create($clientConfig['config']);
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

            $reservedTokens = $maxOutputTokens + $maxInputTokens + 150; // Output + input + prompt framing
            $maxContextTokens = max(150, $contextWindowTokens - $reservedTokens);
            $maxContextChars = $maxContextTokens * 4; // Approx 4 chars per token average

            $siteContext = '';
            $ragEnabled = (bool)($this->config['rag_enabled'] ?? true);

            if ($ragEnabled) {
                try {
                    $retriever = new \Grav\Plugin\AiChatbot\Rag\Retriever($this->grav, $this->config);
                    $ragRes = $retriever->searchAndBuildPrompt($question, $maxContextChars);
                    if (!empty($ragRes['context_prompt'])) {
                        $siteContext = $ragRes['context_prompt'];
                    }
                } catch (\Throwable $rt) {
                    $siteContext = '';
                }
            }

            if (empty($siteContext)) {
                $indexer = new ContextIndexer($this->grav);
                $siteContext = $indexer->buildContextPrompt($maxContextChars);
            }

            $customSystemPrompt = trim($this->config['custom_system_prompt'] ?? '');
            $maxChars = (int)($this->config['max_response_chars'] ?? 1000);
            $defaultSystemPrompt = "Answer the user directly. Do not include any thinking process, reasoning steps, scratchpad, or internal monologue. If you do not understand the question or the input seems unclear, ask the user for clarification instead of analyzing the input internally. Keep your total response under approximately {max_response_chars} characters.";

            $template = !empty($customSystemPrompt) ? $customSystemPrompt : $defaultSystemPrompt;
            $systemRule = str_replace('{max_response_chars}', (string)$maxChars, $template);
            $siteContext = trim($systemRule . "\n\n" . $siteContext);

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

            $clientConfig = $this->resolveClientConfig();
            if ($clientConfig['error'] !== null) {
                return [
                    'http_code' => 400,
                    'success' => false,
                    'answer' => 'Chatbot is not configured: ' . $clientConfig['error'],
                ];
            }
            $client = AiClientFactory::create($clientConfig['config']);
            $res = $client->generateResponse($siteContext, $messages);

            $answer = is_array($res) ? ($res['answer'] ?? '') : (string)$res;
            
            // Secondary safety net sanitization for CoT monologue
            $answer = preg_replace('/<think>.*?<\/think>/s', '', $answer);
            $answer = preg_replace('/<think>.*?$/s', '', $answer);
            $trimmedAns = trim($answer);
            if (preg_match('/^(?:Thinking Process|Thought|Thinking|Scratchpad|Internal Monologue|Reasoning|Analysis|Chain of Thought|Step-by-step|My reasoning):\s*[\s\S]*?\n\s*\n+(.*)/i', $trimmedAns, $matches)) {
                $answer = trim($matches[1]);
            } elseif (preg_match('/^(?:Thinking Process|Thought|Thinking|Scratchpad|Internal Monologue|Reasoning|Analysis|Chain of Thought|Step-by-step|My reasoning):\s*[\s\S]*/i', $trimmedAns)) {
                $answer = '';
            }
            $trimmedAns2 = trim($answer);
            if (preg_match('/^\d+\.\s*\*\*(?:Analyze|Determine|Formulate|Identify|Evaluate|Check|Understand|Plan|Assess|Consider|Review|Interpret)[^*]*\*\*[\s\S]*?\n\s*\n+(.*)/i', $trimmedAns2, $m2)) {
                $answer = trim($m2[1]);
            } elseif (preg_match('/^\d+\.\s*\*\*(?:Analyze|Determine|Formulate|Identify|Evaluate|Check|Understand|Plan|Assess|Consider|Review|Interpret)[^*]*\*\*/i', $trimmedAns2)) {
                $answer = '';
            }
            // Self-referential monologue detector
            $trimmedAns3 = trim($answer);
            $selfRef = '/(?:re-reading|the input|the user|the request|the prompt|the question|looking at this|let me (?:think|analyze|consider|re-read|check)|I need to (?:figure|analyze|determine|understand)|what .+ asking|their (?:question|request|query|intent))/i';
            $starters = '/^(?:Wait|Actually|Hmm|OK|Okay|So|Now|Let me|Alright|Right|First|Well),?\s/i';
            if (preg_match($starters, $trimmedAns3) && preg_match($selfRef, $trimmedAns3)) {
                if (preg_match('/\n\s*\n+((?!(?:Wait|Actually|Hmm|OK|Okay|So|Now|Let me|Alright|Right|First|Well),?\s).*)/si', $trimmedAns3, $m3)) {
                    $candidate = trim($m3[1]);
                    $answer = (!empty($candidate) && !preg_match($selfRef, $candidate)) ? $candidate : '';
                } else {
                    $answer = '';
                }
            }

            $promptTokens = is_array($res) ? (int)($res['prompt_tokens'] ?? 0) : 0;
            $completionTokens = is_array($res) ? (int)($res['completion_tokens'] ?? 0) : 0;

            if (is_array($res) && (!array_key_exists('prompt_tokens', $res) || !array_key_exists('completion_tokens', $res))) {
                (new Logger($this->grav))->logError("AI response is missing token usage [Provider: {$provider}, Model: {$model}].", 'AI_USAGE');
            }
            
            $apiSuccess = is_array($res) ? !empty($res['success']) : false;
            $hasAnswer = !empty($answer);

            if (!$apiSuccess) {
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

            if (!$hasAnswer) {
                $cotMsg = $this->config['cot_empty_response_text'] 
                    ?? "I'm sorry, I didn't quite understand that. Could you please rephrase your question?";
                
                $logger = new Logger($this->grav);
                $logger->logError("AI Model response sanitized to empty (CoT only output) [Provider: {$provider}, Model: {$model}]", 'COT_SANITIZED');
                
                $logger->logInteraction([
                    'question' => $question,
                    'answer' => $cotMsg,
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
                        'answer' => $cotMsg,
                        'prompt_tokens' => $promptTokens,
                        'completion_tokens' => $completionTokens,
                        'error' => 'CoT Sanitized to Empty'
                    ]);
                }

                return [
                    'http_code' => 200,
                    'success' => true,
                    'answer' => $cotMsg,
                    'source' => 'ai_api',
                    'provider' => $provider
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
