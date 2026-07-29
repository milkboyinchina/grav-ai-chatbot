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

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $limiter = new RateLimiter($this->grav, $this->config);
        if (!$limiter->checkRateLimit($ip)) {
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

        if ($action === 'summarize_page') {
            return $this->handleSummarizePage($currentRoute);
        }

        // TIER 1: FAQ Local Pre-Matching
        if ($this->config['faq_enabled'] ?? true) {
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

        // TIER 3: AI Model Call (Groq, Gemini, OpenRouter, OpenAI, Custom)
        if (!($this->config['ai_enabled'] ?? true)) {
            return [
                'http_code' => 200,
                'success' => true,
                'answer' => "I'm currently operating in offline mode. I couldn't find an exact match in our FAQ or Contact pages.",
                'source' => 'offline_fallback'
            ];
        }

        return $this->queryAiModel($question, $currentRoute, $messagesHistory);
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
            $daysAgo = (int)floor(($i / 60) * 175); // 0 to 175 days ago
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
            return [
                'http_code' => 400,
                'success' => false,
                'message' => "API Key is required for provider '{$provider}'."
            ];
        }

        try {
            $client = AiClientFactory::create([
                'provider' => $provider,
                'api_key' => $apiKey,
                'model' => $model,
                'custom_endpoint' => $customEndpoint
            ]);

            $testPrompt = "Ping! Reply with 'OK' if you can read this message.";
            $systemPrompt = "You are testing AI API connection. Keep response under 5 words.";
            $response = $client->generateResponse($testPrompt, $systemPrompt);

            if (!empty($response)) {
                return [
                    'http_code' => 200,
                    'success' => true,
                    'message' => "Successfully connected to {$provider} ({$model})! Response: " . trim($response)
                ];
            }

            return [
                'http_code' => 500,
                'success' => false,
                'message' => "Connected to {$provider}, but received empty response payload."
            ];
        } catch (\Throwable $e) {
            return [
                'http_code' => 500,
                'success' => false,
                'message' => "Connection test failed for {$provider}: " . $e->getMessage()
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

        $systemPrompt = "You are a concise webpage summarizer. Summarize the key points of the webpage in 3 clear bullet points.";
        $prompt = "Summarize this webpage content:\n\n" . substr($pageContent, 0, 3000);

        try {
            $client = AiClientFactory::create($this->config);
            $summary = $client->generateResponse($prompt, $systemPrompt);

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
            return [
                'http_code' => 500,
                'success' => false,
                'answer' => "Could not summarize page: " . $e->getMessage()
            ];
        }
    }

    /**
     * Dispatch prompt to AI Client.
     */
    protected function queryAiModel(string $question, string $currentRoute, array $history): array
    {
        try {
            $indexer = new ContextIndexer($this->grav);
            $siteContext = $indexer->buildContextPrompt($currentRoute);

            $client = AiClientFactory::create($this->config);
            $response = $client->generateResponse($question, $siteContext, $history);

            $logger = new Logger($this->grav);
            $logger->logInteraction([
                'question' => $question,
                'answer' => $response,
                'source' => 'ai_api',
                'provider' => $this->config['provider'] ?? 'groq',
                'prompt_tokens' => 450,
                'completion_tokens' => 120
            ]);

            return [
                'http_code' => 200,
                'success' => true,
                'answer' => $response,
                'source' => 'ai_api',
                'provider' => $this->config['provider'] ?? 'groq'
            ];
        } catch (\Throwable $e) {
            return [
                'http_code' => 500,
                'success' => false,
                'answer' => "An unexpected error occurred while communicating with the AI service: " . $e->getMessage()
            ];
        }
    }
}
