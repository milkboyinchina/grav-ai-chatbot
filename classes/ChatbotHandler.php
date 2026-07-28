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
                        'provider' => $this->config['provider'] ?? 'gemini'
                    ]);
                }

                return [
                    'http_code' => 429,
                    'success' => false,
                    'answer' => $errMsg
                ];
            }
        }

        // 2. Strict Scope & External URL Guardrail Check
        if (!empty($this->config['strict_site_scope']) && !empty($question)) {
            if (preg_match('/https?:\/\/(?!' . preg_quote($_SERVER['HTTP_HOST'] ?? '', '/') . ')[^\s]+/i', $question)) {
                $guardrailMsg = "I can only process content from this website. I cannot fetch or analyze external URLs.";
                return [
                    'http_code' => 200,
                    'success' => true,
                    'source' => 'guardrail',
                    'answer' => $guardrailMsg
                ];
            }
        }

        // 3. Page Summarization Action
        if ($action === 'summarize_page') {
            return $this->handlePageSummarize($currentRoute);
        }

        // 4. Local FAQ Pre-Matching (0 API calls)
        if (!empty($this->config['faq_enabled']) && !empty($question)) {
            $faqRoute = $this->config['faq_route'] ?? '/faq';
            $threshold = (int)($this->config['faq_similarity_threshold'] ?? 70);
            $enableMulti = !empty($this->config['enable_multilingual_faq']);

            $faqResolver = new FaqResolver($this->grav, $faqRoute, $threshold, $enableMulti);
            $faqMatch = $faqResolver->matchQuestion($question);

            if ($faqMatch !== null) {
                $faqAnswer = $faqMatch['answer'];

                if (!empty($this->config['logging_enabled'])) {
                    $this->logger->logInteraction([
                        'question' => $question,
                        'answer' => $faqAnswer,
                        'source' => 'faq_match',
                        'provider' => 'local_faq',
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0
                    ]);
                }

                return [
                    'http_code' => 200,
                    'success' => true,
                    'source' => 'faq_match',
                    'matched_question' => $faqMatch['question'],
                    'similarity' => $faqMatch['similarity'],
                    'answer' => $faqAnswer
                ];
            }
        }

        // 5. Check if AI API calls are enabled
        $aiEnabled = isset($this->config['ai_enabled']) ? (bool)$this->config['ai_enabled'] : true;

        if (!$aiEnabled) {
            $faqOnlyMsg = "AI Assistant features are currently turned off. I can only answer questions that are listed in our website FAQ. Please visit our FAQ page or contact us directly for assistance.";
            
            if (!empty($this->config['logging_enabled'])) {
                $this->logger->logInteraction([
                    'question' => $question,
                    'answer' => $faqOnlyMsg,
                    'source' => 'faq_only',
                    'provider' => 'none'
                ]);
            }

            return [
                'http_code' => 200,
                'success' => true,
                'source' => 'faq_only',
                'answer' => $faqOnlyMsg
            ];
        }

        // 6. Contact Query Intent Check
        $contactKeywords = '/(contact|phone|email|address|office|reach|speak|talk|engineer|support)/i';
        $contactInfoSnippet = '';
        if (preg_match($contactKeywords, $question)) {
            $contactRoute = $this->config['contact_route'] ?? '/contact';
            $hiddenRoute = $this->config['hidden_contact_route'] ?? '/hidden-contacts';
            $enableHidden = !empty($this->config['enable_hidden_contacts']);

            $contactResolver = new ContactPageResolver($this->grav, $contactRoute, $hiddenRoute, $enableHidden);
            $contactInfoSnippet = $contactResolver->getContactInformation($question);
        }

        // 6. Build Site Context Index
        $indexer = new ContextIndexer($this->grav);
        $siteContext = $indexer->buildSiteContext([$this->config['contact_route'] ?? '/contact']);

        // 7. System Prompt Construction with Strict Scope Enforcement
        $systemPrompt = "You are a helpful, courteous AI Assistant specifically representing this website.\n";
        $systemPrompt .= "STRICT GUARDRAILS:\n";
        $systemPrompt .= "- Answer user inquiries using ONLY the provided website context and contact information below.\n";
        $systemPrompt .= "- If the answer is not contained in the website content, politely state that you can only answer questions regarding this website.\n";
        $systemPrompt .= "- NEVER analyze or attempt to browse external URLs or off-topic general knowledge subjects.\n\n";

        if (!empty($contactInfoSnippet)) {
            $systemPrompt .= "WEBSITE CONTACT DETAILS:\n{$contactInfoSnippet}\n\n";
        }

        if (!empty($siteContext)) {
            $systemPrompt .= "INDEXED WEBSITE CONTENT:\n{$siteContext}\n\n";
        }

        // 8. Invoke AI Engine via Factory
        $aiClient = AiClientFactory::create($this->config);
        
        $messages = [];
        if (!empty($messagesHistory) && is_array($messagesHistory)) {
            foreach (array_slice($messagesHistory, -4) as $h) {
                if (!empty($h['role']) && !empty($h['content'])) {
                    $messages[] = ['role' => $h['role'], 'content' => $h['content']];
                }
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $aiResult = $aiClient->generateResponse($systemPrompt, $messages);

        // 9. Log Interaction
        if (!empty($this->config['logging_enabled'])) {
            $this->logger->logInteraction([
                'question' => $question,
                'answer' => $aiResult['answer'],
                'source' => $aiResult['success'] ? 'ai_api' : 'ai_error',
                'provider' => $this->config['provider'] ?? 'gemini',
                'prompt_tokens' => $aiResult['prompt_tokens'],
                'completion_tokens' => $aiResult['completion_tokens']
            ]);
        }

        return [
            'http_code' => $aiResult['success'] ? 200 : 500,
            'success' => $aiResult['success'],
            'source' => 'ai_api',
            'answer' => $aiResult['answer']
        ];
    }

    /**
     * Handle page summarization request for current route.
     */
    protected function handlePageSummarize(string $route): array
    {
        $pages = $this->grav['pages'];
        $page = $pages->find($route);

        if (!$page || !$page->exists()) {
            return [
                'http_code' => 404,
                'success' => false,
                'answer' => 'Unable to locate current page content for summarization.'
            ];
        }

        $title = $page->title();
        $rawText = strip_tags($page->content());
        $cleanText = trim(preg_replace('/\s+/', ' ', $rawText));

        if (empty($cleanText)) {
            return [
                'http_code' => 200,
                'success' => true,
                'source' => 'summarize',
                'answer' => "The page '{$title}' does not contain sufficient text content to summarize."
            ];
        }

        $aiEnabled = isset($this->config['ai_enabled']) ? (bool)$this->config['ai_enabled'] : true;
        if (!$aiEnabled) {
            return [
                'http_code' => 200,
                'success' => true,
                'source' => 'faq_only',
                'answer' => 'AI Page Summarization is disabled when AI API Fallback is turned off.'
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
