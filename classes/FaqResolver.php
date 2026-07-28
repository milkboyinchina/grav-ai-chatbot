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

    public function __construct(Grav $grav, string $faqRoute = '/faq', int $threshold = 70, bool $enableMultilingual = true)
    {
        $this->grav = $grav;
        $this->faqRoute = $faqRoute ?: '/faq';
        $this->threshold = max(50, min(100, $threshold));
        $this->enableMultilingual = $enableMultilingual;
    }

    /**
     * Attempts to find a matching question in the FAQ page against primary questions & aliases.
     *
     * @param string $userQuestion
     * @return array|null Returns ['question' => string, 'answer' => string, 'similarity' => float] or null
     */
    public function matchQuestion(string $userQuestion): ?array
    {
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
                $candSemanticKey = $this->extractSemanticIntent($candClean);

                // 1. Direct semantic intent match (e.g. founding + company + date)
                if (!empty($userSemanticKey) && $userSemanticKey === $candSemanticKey) {
                    return [
                        'question' => $faq['question'],
                        'answer' => $faq['answer'],
                        'similarity' => 95.0
                    ];
                }

                // 2. String similarity scoring
                similar_text($userClean, $candClean, $percent);

                // 3. Token overlap scoring
                $tokenScore = $this->calculateTokenOverlap($userClean, $candClean);
                $combinedScore = max($percent, $tokenScore);

                if ($combinedScore > $highestScore) {
                    $highestScore = $combinedScore;
                    $bestMatch = [
                        'question' => $faq['question'],
                        'answer' => $faq['answer'],
                        'similarity' => round($combinedScore, 1)
                    ];
                }
            }
        }

        // Lower threshold requirement if semantic intent group matches strongly
        if ($highestScore >= $this->threshold && $bestMatch !== null) {
            return $bestMatch;
        }

        return null;
    }

    /**
     * Extract normalized semantic intent signature (e.g. founding:company:date).
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
        $pages = $this->grav['pages'];
        $lang = $this->grav['language']->getLanguage() ?: 'en';
        
        $page = null;

        if ($this->enableMultilingual && !empty($lang) && $lang !== 'en') {
            $page = $pages->find('/' . $lang . $this->faqRoute);
            if (!$page instanceof Page || !$page->exists()) {
                $defaultPage = $pages->find($this->faqRoute);
                if ($defaultPage instanceof Page && $defaultPage->exists()) {
                    $translated = $defaultPage->translatedPage($lang);
                    if ($translated instanceof Page && $translated->exists()) {
                        $page = $translated;
                    }
                }
            }
        }

        if (!$page instanceof Page || !$page->exists()) {
            $page = $pages->find($this->faqRoute);
        }

        if (!$page instanceof Page || !$page->exists()) {
            return [];
        }

        $items = [];
        $header = $page->header();

        // 1. Header YAML (faq: array with aliases support)
        if (!empty($header->faq) && is_array($header->faq)) {
            foreach ($header->faq as $f) {
                if (!empty($f['question']) && !empty($f['answer'])) {
                    $items[] = [
                        'question' => trim($f['question']),
                        'aliases' => is_array($f['aliases'] ?? null) ? $f['aliases'] : [],
                        'answer' => trim($f['answer'])
                    ];
                }
            }
        }

        // 2. Markdown headers (### Q: Main Question | Alias 1 | Alias 2)
        $rawMarkdown = $page->rawMarkdown();
        if (preg_match_all('/^###?\s+(?:Q:\s*)?(.+?)$(.*?)(?=^###?|\z)/msi', $rawMarkdown, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $headerTitle = trim($m[1]);
                $answer = trim(strip_tags($m[2]));
                
                if (!empty($headerTitle) && !empty($answer) && strlen($answer) > 5) {
                    $parts = array_map('trim', explode('|', $headerTitle));
                    $mainQ = array_shift($parts);
                    $items[] = [
                        'question' => $mainQ,
                        'aliases' => $parts,
                        'answer' => $answer
                    ];
                }
            }
        }

        return $items;
    }

    protected function normalizeString(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s]/u', '', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    protected function calculateTokenOverlap(string $str1, string $str2): float
    {
        $tokens1 = array_unique(explode(' ', $str1));
        $tokens2 = array_unique(explode(' ', $str2));
        
        $stopWords = ['is', 'the', 'this', 'a', 'an', 'what', 'when', 'where', 'how', 'who', 'does', 'do', 'can', 'you', 'your', 'our', 'que', 'como', 'donde', 'cuando', 'quien', 'es', 'el', 'la', 'los', 'las', 'un', 'una'];
        $tokens1 = array_diff($tokens1, $stopWords);
        $tokens2 = array_diff($tokens2, $stopWords);

        if (empty($tokens1) || empty($tokens2)) {
            return 0.0;
        }

        $intersection = array_intersect($tokens1, $tokens2);
        $overlap = count($intersection) / min(count($tokens1), count($tokens2));
        return $overlap * 100.0;
    }
}
