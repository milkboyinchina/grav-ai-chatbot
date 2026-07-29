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
     * @return string
     */
    public function buildContextPrompt(string $currentRoute = '/'): string
    {
        return $this->buildSiteContext();
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
     * Builds site summary text from published pages.
     *
     * @param array $excludeRoutes List of routes to exclude from indexing
     * @return string
     */
    public function buildSiteContext(array $excludeRoutes = []): string
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
                if (strlen($cleanContent) > 600) {
                    $cleanContent = substr($cleanContent, 0, 600) . '...';
                }

                if (!empty($cleanContent)) {
                    $siteContext[] = "Page Title: {$title} (Route: {$route})\nSummary: {$cleanContent}";
                }
            } catch (\Throwable $t) {
                continue;
            }
        }

        return implode("\n\n---\n\n", array_slice($siteContext, 0, 15));
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
