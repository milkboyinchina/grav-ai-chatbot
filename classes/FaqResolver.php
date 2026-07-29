<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;
use Grav\Common\Page\Page;

/**
 * Class FaqResolver
 * Semantic pre-matching engine supporting question variations, aliases, and synonym normalization.
 *
 * @license GPL-3.0-or-later
 */
class FaqResolver
{
    protected Grav $grav;
    protected string $faqRoute;
    protected int $threshold;
    protected bool $enableMultilingual;

    // Synonym map for intent normalization
    protected array $synonymGroups = [
        'founding' => ['established', 'founded', 'started', 'launched', 'created', 'incorporation', 'incorporated', 'opened', 'setup', 'origin', 'beginning', 'operations', 'around', 'heritage'],
        'company' => ['company', 'business', 'organization', 'brand', 'firm', 'agency', 'enterprise'],
        'date' => ['when', 'year', 'date', 'how long', 'how many years', 'far back', 'doors']
    ];

    public function __construct(Grav $grav, array $config = [])
    {
        $this->grav = $grav;
        
        if (is_string($config)) {
            $this->faqRoute = $config ?: '/faq';
            $this->threshold = 70;
            $this->enableMultilingual = true;
        } else {
            $this->faqRoute = $config['faq_route'] ?? '/faq';
            $this->threshold = max(50, min(100, (int)($config['faq_similarity_threshold'] ?? 70)));
            $this->enableMultilingual = !empty($config['enable_multilingual_faq']);
        }
    }

    /**
     * Attempts to find a matching question in the FAQ page against primary questions & aliases.
     *
     * @param string $userQuestion
     * @param int|null $customThreshold
     * @return array|null Returns ['matched_question' => string, 'answer' => string, 'similarity' => float] or null
     */
    public function findMatch(string $userQuestion, ?int $customThreshold = null): ?array
    {
        $threshold = $customThreshold !== null ? max(50, min(100, $customThreshold)) : $this->threshold;
        $userClean = $this->normalizeString($userQuestion);
        if (empty($userClean)) {
            return null;
        }

        $userSemanticKey = $this->extractSemanticIntent($userClean);

        $faqPairs = $this->loadFaqItems();
        if (empty($faqPairs)) {
            return null;
        }

        $bestMatch = null;
        $highestScore = 0;

        foreach ($faqPairs as $faq) {
            // Check primary question and all question aliases/variations
            $candidates = array_merge([$faq['question']], $faq['aliases'] ?? []);

            foreach ($candidates as $cand) {
                $candClean = $this->normalizeString($cand);
                if (empty($candClean)) continue;

                // 1. Exact or Substring match
                if ($userClean === $candClean || strpos($userClean, $candClean) !== false || strpos($candClean, $userClean) !== false) {
                    return [
                        'matched_question' => $faq['question'],
                        'answer' => $faq['answer'],
                        'similarity' => 100
                    ];
                }

                // 2. Similar text percentage match
                similar_text($userClean, $candClean, $percent);

                // 3. Semantic Intent Boost
                $candSemanticKey = $this->extractSemanticIntent($candClean);
                if (!empty($userSemanticKey) && $userSemanticKey === $candSemanticKey) {
                    $percent = max($percent, 95.0);
                }

                if ($percent > $highestScore) {
                    $highestScore = $percent;
                    $bestMatch = [
                        'matched_question' => $faq['question'],
                        'answer' => $faq['answer'],
                        'similarity' => round($percent, 1)
                    ];
                }
            }
        }

        // Lower threshold requirement if score is sufficient
        if ($highestScore >= $threshold && $bestMatch !== null) {
            return $bestMatch;
        }

        return null;
    }

    /**
     * Extract normalized semantic intent signature (e.g. intent_founding_date).
     */
    protected function extractSemanticIntent(string $text): string
    {
        $matchedGroups = [];
        foreach ($this->synonymGroups as $groupName => $keywords) {
            foreach ($keywords as $kw) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $text)) {
                    $matchedGroups[$groupName] = true;
                    break;
                }
            }
        }

        if (isset($matchedGroups['founding']) && (isset($matchedGroups['company']) || isset($matchedGroups['date']))) {
            return 'intent_founding_date';
        }

        return '';
    }

    /**
     * Load FAQ Q&A pairs from localized Grav Page headers or Markdown content, including aliases.
     */
    public function loadFaqItems(): array
    {
        $pagesContainer = $this->grav['pages'] ?? null;
        if (!$pagesContainer) {
            return [];
        }

        try {
            if (method_exists($pagesContainer, 'init')) {
                try {
                    $pagesContainer->init();
                } catch (\Throwable $t) {}
            }
        } catch (\Throwable $e) {}

        $lang = '';
        if (isset($this->grav['language']) && method_exists($this->grav['language'], 'getLanguage')) {
            $lang = $this->grav['language']->getLanguage() ?: 'en';
        }
        
        $page = null;

        try {
            if ($this->enableMultilingual && !empty($lang) && $lang !== 'en') {
                $page = $pagesContainer->find('/' . $lang . $this->faqRoute);
                if (!$page instanceof Page || !$page->exists()) {
                    $defaultPage = $pagesContainer->find($this->faqRoute);
                    if ($defaultPage instanceof Page && $defaultPage->exists()) {
                        $translated = $defaultPage->translatedPage($lang);
                        if ($translated instanceof Page && $translated->exists()) {
                            $page = $translated;
                        }
                    }
                }
            }

            if (!$page instanceof Page || !$page->exists()) {
                $page = $pagesContainer->find($this->faqRoute);
            }
        } catch (\Throwable $t) {
            $page = null;
        }

        if (!$page instanceof Page || !$page->exists()) {
            return [];
        }

        $header = $page->header();
        $faqItems = [];

        // 1. Check YAML Frontmatter `faqs:` header list
        if (!empty($header->faqs) && is_array($header->faqs)) {
            foreach ($header->faqs as $item) {
                if (!empty($item['question']) && !empty($item['answer'])) {
                    $faqItems[] = [
                        'question' => trim($item['question']),
                        'aliases' => (array)($item['aliases'] ?? []),
                        'answer' => trim($item['answer'])
                    ];
                }
            }
        }

        // 2. Parse Markdown H2/H3 headers if YAML faqs not defined
        if (empty($faqItems)) {
            $content = $page->rawMarkdown();
            $blocks = preg_split('/^#{2,3}\s+/m', $content);

            foreach ($blocks as $block) {
                $block = trim($block);
                if (empty($block)) continue;

                $lines = explode("\n", $block);
                $qLine = trim(array_shift($lines));
                $answerText = trim(implode("\n", $lines));

                if (!empty($qLine) && !empty($answerText)) {
                    $faqItems[] = [
                        'question' => $qLine,
                        'aliases' => [],
                        'answer' => $answerText
                    ];
                }
            }
        }

        return $faqItems;
    }

    /**
     * Clean string for fuzzy text matching.
     */
    protected function normalizeString(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s]/u', '', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
