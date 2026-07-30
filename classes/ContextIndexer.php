<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Page\Collection;

/**
 * Class ContextIndexer
 * Extracts published website pages and builds a concise text index for LLM prompt context injection.
 *
 * @license GPL-3.0-or-later
 */
class ContextIndexer
{
    protected Grav $grav;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
    }

    /**
     * Convenience method for building site context prompt.
     *
     * @param string $currentRoute
     * @param int $maxContextChars
     * @return string
     */
    public function buildContextPrompt(string $currentRoute = '/', int $maxContextChars = 3000): string
    {
        return $this->buildSiteContext([], $maxContextChars);
    }

    /**
     * Return indexed pages array with routes and text content for summarization.
     *
     * @return array
     */
    public function getIndexedContext(): array
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
            $allPages = $pagesContainer->all() ?: [];
        } catch (\Throwable $e) {
            $allPages = [];
        }

        $result = [];
        foreach ($allPages as $page) {
            if ($page instanceof Page && $page->published() && $page->routable()) {
                $result[] = [
                    'route' => $page->route(),
                    'title' => $page->title(),
                    'content' => strip_tags($page->content())
                ];
            }
        }
        return $result;
    }

    /**
     * Builds site summary text from published pages, strictly constrained by maxContextChars to honor model context limits.
     *
     * @param array $excludeRoutes List of routes to exclude from indexing
     * @param int $maxContextChars Maximum character count allowed for context prompt
     * @return string
     */
    public function buildSiteContext(array $excludeRoutes = [], int $maxContextChars = 3000): string
    {
        $pages = [];
        try {
            $pagesContainer = $this->grav['pages'] ?? null;
            if ($pagesContainer) {
                if (method_exists($pagesContainer, 'init')) {
                    try {
                        $pagesContainer->init();
                    } catch (\Throwable $t) {
                        // Already initialized or safely handled
                    }
                }
                $pages = $pagesContainer->all() ?: [];
            }
        } catch (\Throwable $e) {
            $pages = [];
        }

        $siteContext = [];
        $accumulatedLen = 0;

        /** @var Page $page */
        foreach ($pages as $page) {
            try {
                if (!$page instanceof Page || !$page->published() || !$page->routable()) {
                    continue;
                }

                $route = $page->route();
                if (in_array($route, $excludeRoutes, true) || strpos($route, '/hidden-') !== false) {
                    continue;
                }

                $title = $page->title();
                $content = strip_tags($page->content());
                // Truncate individual page length to prevent excessive tokens
                $cleanContent = trim(preg_replace('/\s+/', ' ', $content));
                if (strlen($cleanContent) > 400) {
                    $cleanContent = substr($cleanContent, 0, 400) . '...';
                }

                if (!empty($cleanContent)) {
                    $entry = "Page Title: {$title} (Route: {$route})\nSummary: {$cleanContent}";
                    $entryLen = strlen($entry);

                    if ($accumulatedLen + $entryLen > $maxContextChars && !empty($siteContext)) {
                        break;
                    }

                    $siteContext[] = $entry;
                    $accumulatedLen += $entryLen + 7;
                }
            } catch (\Throwable $t) {
                continue;
            }
        }

        $result = implode("\n\n---\n\n", $siteContext);
        if (strlen($result) > $maxContextChars) {
            $result = substr($result, 0, $maxContextChars) . '...';
        }

        return $result;
    }

    /**
     * Search context chunks relevant to question.
     *
     * @param string $question User query
     * @param int $maxChunks Maximum chunks to return
     * @return array Chunks array
     */
    public function searchContext(string $question, int $maxChunks = 3): array
    {
        $fullContext = $this->buildSiteContext();
        if (empty($fullContext)) {
            return [];
        }

        $chunks = explode("\n\n---\n\n", $fullContext);
        return array_slice($chunks, 0, $maxChunks);
    }
}
