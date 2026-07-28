<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;
use Grav\Common\Page\Page;

/**
 * Class FaqResolver
 * Pre-matches visitor questions against local FAQ page content, supporting multilingual sites.
 *
 * @license GPL-3.0-or-later
 */
class FaqResolver
{
    protected Grav $grav;
    protected string $faqRoute;
    protected int $threshold;
    protected bool $enableMultilingual;

    public function __construct(Grav $grav, string $faqRoute = '/faq', int $threshold = 70, bool $enableMultilingual = true)
    {
        $this->grav = $grav;
        $this->faqRoute = $faqRoute ?: '/faq';
        $this->threshold = max(50, min(100, $threshold));
        $this->enableMultilingual = $enableMultilingual;
    }

    /**
     * Attempts to find a matching question in the FAQ page.
     *
     * @param string $userQuestion
     * @return array|null Returns ['question' => string, 'answer' => string, 'similarity' => float] or null
     */
    public function matchQuestion(string $userQuestion): ?array
    {
        $userQuestionClean = $this->normalizeString($userQuestion);
        if (empty($userQuestionClean)) {
            return null;
        }

        $faqPairs = $this->loadFaqItems();
        if (empty($faqPairs)) {
            return null;
        }

        $bestMatch = null;
        $highestScore = 0;

        foreach ($faqPairs as $faq) {
            $faqQClean = $this->normalizeString($faq['question']);
            
            similar_text($userQuestionClean, $faqQClean, $percent);

            $tokenScore = $this->calculateTokenOverlap($userQuestionClean, $faqQClean);
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

        if ($highestScore >= $this->threshold && $bestMatch !== null) {
            return $bestMatch;
        }

        return null;
    }

    /**
     * Load FAQ Q&A pairs from localized Grav Page headers or Markdown content.
     */
    public function loadFaqItems(): array
    {
        $pages = $this->grav['pages'];
        $lang = $this->grav['language']->getLanguage() ?: 'en';
        
        $page = null;

        if ($this->enableMultilingual && !empty($lang) && $lang !== 'en') {
            // 1. Check language-prefixed route e.g. /es/faq
            $page = $pages->find('/' . $lang . $this->faqRoute);

            // 2. Check translated page instance
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

        // 3. Fallback to default route page
        if (!$page instanceof Page || !$page->exists()) {
            $page = $pages->find($this->faqRoute);
        }

        if (!$page instanceof Page || !$page->exists()) {
            return [];
        }

        $items = [];
        $header = $page->header();

        // 1. Header YAML (faq: array)
        if (!empty($header->faq) && is_array($header->faq)) {
            foreach ($header->faq as $f) {
                if (!empty($f['question']) && !empty($f['answer'])) {
                    $items[] = [
                        'question' => trim($f['question']),
                        'answer' => trim($f['answer'])
                    ];
                }
            }
        }

        // 2. Markdown headers (### Q: / ### ... followed by paragraph)
        $rawMarkdown = $page->rawMarkdown();
        if (preg_match_all('/^###?\s+(?:Q:\s*)?(.+?)$(.*?)(?=^###?|\z)/msi', $rawMarkdown, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $q = trim($m[1]);
                $a = trim(strip_tags($m[2]));
                if (!empty($q) && !empty($a) && strlen($a) > 5) {
                    $items[] = [
                        'question' => $q,
                        'answer' => $a
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
