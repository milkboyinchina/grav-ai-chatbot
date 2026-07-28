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
     * Builds site summary text from published pages.
     *
     * @param array $excludeRoutes List of routes to exclude from indexing
     * @return string
     */
    public function buildSiteContext(array $excludeRoutes = []): string
    {
        /** @var Collection $pages */
        $pages = $this->grav['pages']->all();
        $siteContext = [];

        /** @var Page $page */
        foreach ($pages as $page) {
            if (!$page->published() || !$page->routable()) {
                continue;
            }

            $route = $page->route();
            if (in_array($route, $excludeRoutes) || strpos($route, '/hidden-') !== false) {
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
        }

        return implode("\n\n---\n\n", array_slice($siteContext, 0, 15));
    }
}
