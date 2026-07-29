<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;

/**
 * Class ChatbotHandler
 * Main request controller orchestrating rate limits, guardrails, FAQ pre-matching,
 * contact resolution, AI calls, and logging.
 *
 * @license GPL-3.0-or-later
 */
class ChatbotHandler
{
    protected Grav $grav;
    protected array $config;
    protected Logger $logger;

    public function __construct(Grav $grav, array $config)
    {
        $this->grav = $grav;
        $this->config = $config;
        $this->logger = new Logger($grav);
    }

    /**
     * Process incoming AJAX chat request payload.
     *
     * @param array $data Input data payload
     * @return array Response payload
     */
    public function processRequest(array $data): array
    {
        $question = trim($data['question'] ?? $data['message'] ?? '');
        $action = trim($data['action'] ?? 'query');
        $messagesHistory = $data['history'] ?? [];
        $currentRoute = $data['current_route'] ?? '/';

        if ($action === 'analytics_report') {
            $generator = new AnalyticsReportGenerator($this->grav);
            return [
                'http_code' => 200,
                'success' => true,
                'analytics' => $generator->getDashboardAnalyticsData()
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

        // 1. Rate Limiting Check
        if (!empty($this->config['rate_limit_enabled'])) {
            $limiter = new RateLimiter($this->grav);
            $maxReq = (int)($this->config['rate_limit_max_requests'] ?? 10);
            $windowSec = (int)($this->config['rate_limit_window_seconds'] ?? 60);

            $limitCheck = $limiter->checkRateLimit($maxReq, $windowSec);
            if (!$limitCheck['allowed']) {
                $errMsg = "Too many requests. Please wait {$limitCheck['reset_seconds']} seconds before asking again.";
                
                if (!empty($this->config['logging_enabled'])) {
                    $this->logger->logInteraction([
                        'question' => $question,
                        'answer' => $errMsg,
                        'source' => 'rate_limit',
                        'provider' => 'none'
                    ]);
                }

                return [
                    'http_code' => 429,
                    'success' => false,
                    'source' => 'rate_limit',
                    'answer' => $errMsg
                ];
            }
        }

        // 2. Handle Page Summarization Action
        if ($action === 'summarize_page') {
            return $this->handlePageSummarization($currentRoute);
        }

        // 3. FAQ Pre-Matching Stage (If action != 'force_ai')
        if ($action !== 'force_ai' && !empty($this->config['faq_enabled'])) {
            $faqResolver = new FaqResolver($this->grav, $this->config);
            $threshold = (int)($this->config['faq_similarity_threshold'] ?? 70);
            
            $faqMatch = $faqResolver->findMatch($question, $threshold);

            if ($faqMatch) {
                if (!empty($this->config['logging_enabled'])) {
                    $this->logger->logInteraction([
                        'question' => $question,
                        'answer' => $faqMatch['answer'],
                        'source' => 'faq_match',
                        'provider' => 'local_faq'
                    ]);
                }

                return [
                    'http_code' => 200,
                    'success' => true,
                    'source' => 'faq_match',
                    'matched_question' => $faqMatch['matched_question'],
                    'similarity' => $faqMatch['similarity'],
                    'answer' => $faqMatch['answer']
                ];
            }
        }

        // 4. Contact Resolution Fallback Check (If AI is disabled or unavailable)
        $aiEnabled = !empty($this->config['ai_enabled']);
        if (!$aiEnabled) {
            $contactResolver = new ContactPageResolver($this->grav, $this->config);
            $contactInfo = $contactResolver->resolveContactDetails($question);

            $contactFallbackMsg = "I couldn't find an exact answer in our FAQ, and AI generation is currently disabled. Please contact our team directly for assistance:\n\n" . $contactInfo;

            if (!empty($this->config['logging_enabled'])) {
                $this->logger->logInteraction([
                    'question' => $question,
                    'answer' => $contactFallbackMsg,
                    'source' => 'contact_fallback',
                    'provider' => 'contact_resolver'
                ]);
            }

            return [
                'http_code' => 200,
                'success' => false,
                'source' => 'contact_fallback',
                'answer' => $contactFallbackMsg
            ];
        }

        // 5. Context Indexing & RAG Construction
        $indexer = new ContextIndexer($this->grav, $this->config);
        $contextChunks = $indexer->searchContext($question, 3);
        $siteContextStr = implode("\n---\n", $contextChunks);

        // 6. Build AI Prompt with RAG Context & Site Constraints
        $siteName = $this->grav['config']->get('site.title', 'this website');

        $systemPrompt = "You are a helpful, professional AI Assistant representing {$siteName}.\n"
            . "Answer visitor questions based ON THE PROVIDED WEBSITE CONTEXT below.\n"
            . "If the context does not contain enough information, politely say so and recommend contacting support.\n"
            . "Do NOT invent facts, URLs, or products outside of {$siteName}.\n\n"
            . "=== WEBSITE CONTEXT ===\n{$siteContextStr}\n=======================";

        // Build Conversation Messages Payload
        $messages = [];
        foreach ($messagesHistory as $msg) {
            if (!empty($msg['role']) && !empty($msg['text'])) {
                $messages[] = [
                    'role' => ($msg['role'] === 'user') ? 'user' : 'assistant',
                    'content' => $msg['text']
                ];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        // 7. Dispatch Request to AI Client Factory (Gemini, OpenRouter, OpenAI, Custom)
        $aiClient = AiClientFactory::create($this->config);
        $aiResult = $aiClient->generateResponse($systemPrompt, $messages);

        // If AI call failed, log error and return polite Contact Fallback
        if (!$aiResult['success'] || !empty($aiResult['error'])) {
            $contactResolver = new ContactPageResolver($this->grav, $this->config);
            $contactInfo = $contactResolver->resolveContactDetails($question);
            
            $errorAnswerMsg = "The AI Assistant is currently unavailable. Please contact our team directly for assistance:\n\n" . $contactInfo;

            if (!empty($this->config['logging_enabled'])) {
                $this->logger->logInteraction([
                    'question' => $question,
                    'answer' => $errorAnswerMsg,
                    'source' => 'ai_error',
                    'error_detail' => $aiResult['error'] ?? 'API Error',
                    'provider' => $this->config['provider'] ?? 'gemini'
                ]);
            }

            return [
                'http_code' => 200,
                'success' => false,
                'source' => 'ai_error',
                'error_detail' => $aiResult['error'] ?? 'API Error',
                'answer' => $errorAnswerMsg
            ];
        }

        // 8. Log Success Interaction & Token Cost Metrics
        if (!empty($this->config['logging_enabled'])) {
            $this->logger->logInteraction([
                'question' => $question,
                'answer' => $aiResult['answer'],
                'source' => 'ai_api',
                'provider' => $this->config['provider'] ?? 'gemini',
                'prompt_tokens' => $aiResult['prompt_tokens'],
                'completion_tokens' => $aiResult['completion_tokens']
            ]);
        }

        return [
            'http_code' => 200,
            'success' => true,
            'source' => 'ai_api',
            'provider' => $this->config['provider'] ?? 'gemini',
            'answer' => $aiResult['answer']
        ];
    }

    /**
     * Test AI Provider API connection with provided key & model.
     */
    protected function testAiConnection(array $data): array
    {
        $provider = trim($data['provider'] ?? $this->config['provider'] ?? 'gemini');
        $apiKey = trim($data['api_key'] ?? $this->config['api_key'] ?? '');
        $model = trim($data['model'] ?? $this->config['model'] ?? 'gemini-1.5-flash');
        $customEndpoint = trim($data['custom_endpoint'] ?? $this->config['custom_endpoint'] ?? '');

        if (empty($apiKey) && $provider !== 'custom') {
            return [
                'http_code' => 400,
                'success' => false,
                'message' => 'API Key is missing. Please enter a valid API key in the configuration field.'
            ];
        }

        $testPrompt = "Test connection request from Grav CMS AI Chatbot.";
        $testConfig = array_merge($this->config, [
            'provider' => $provider,
            'api_key' => $apiKey,
            'model' => $model,
            'custom_endpoint' => $customEndpoint
        ]);

        $aiClient = AiClientFactory::create($testConfig);
        $result = $aiClient->generateResponse("You are a system health checker. Respond with 'Connection verified successfully.'", [
            ['role' => 'user', 'content' => $testPrompt]
        ]);

        if (!$result['success'] || !empty($result['error'])) {
            return [
                'http_code' => 400,
                'success' => false,
                'message' => "Connection Failed ({$provider}): " . ($result['error'] ?? $result['answer'] ?? 'Invalid response')
            ];
        }

        return [
            'http_code' => 200,
            'success' => true,
            'message' => "Connection Successful! Verified with provider '{$provider}' using model '{$model}'."
        ];
    }

    /**
     * Handle Page Summarization Action
     */
    protected function handlePageSummarization(string $route): array
    {
        $pages = $this->grav['pages'];
        $page = $pages->find($route);

        if (!$page) {
            return [
                'http_code' => 404,
                'success' => false,
                'answer' => 'Page not found for summarization.'
            ];
        }

        $rawText = strip_tags($page->content());
        $cleanText = preg_replace('/\s+/', ' ', $rawText);
        $cleanText = substr($cleanText, 0, 3000);
        $title = $page->title();

        $aiEnabled = !empty($this->config['ai_enabled']);
        if (!$aiEnabled) {
            return [
                'http_code' => 200,
                'success' => false,
                'answer' => "Page Title: {$title}\nSummary feature requires AI API enablement."
            ];
        }

        $systemPrompt = "You are a concise AI Assistant. Provide a clear 3-bullet point executive summary of the following webpage text.";
        $messages = [
            ['role' => 'user', 'content' => "Summarize page title '{$title}':\n{$cleanText}"]
        ];

        $aiClient = AiClientFactory::create($this->config);
        $aiResult = $aiClient->generateResponse($systemPrompt, $messages);

        if (!empty($this->config['logging_enabled'])) {
            $this->logger->logInteraction([
                'question' => "Summarize page: {$title}",
                'answer' => $aiResult['answer'],
                'source' => 'summarize_page',
                'provider' => $this->config['provider'] ?? 'gemini',
                'prompt_tokens' => $aiResult['prompt_tokens'],
                'completion_tokens' => $aiResult['completion_tokens']
            ]);
        }

        return [
            'http_code' => $aiResult['success'] ? 200 : 500,
            'success' => $aiResult['success'],
            'source' => 'summarize_page',
            'answer' => $aiResult['answer']
        ];
    }
}
