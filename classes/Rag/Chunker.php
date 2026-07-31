<?php
namespace Grav\Plugin\AiChatbot\Rag;

use Grav\Common\Grav;
use Grav\Common\Page\Page;

/**
 * Class Chunker
 * Heading-aware page parser and text chunking engine for Grav CMS RAG.
 *
 * @license GPL-3.0-or-later
 */
class Chunker
{
    protected Grav $grav;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
    }

    /**
     * Chunk all published and routable Grav pages into structural content blocks.
     *
     * @param array $excludeRoutes Routes to skip
     * @return array Array of structured chunks
     */
    public function chunkAllPages(array $excludeRoutes = []): array
    {
        $allChunks = [];

        try {
            $pagesContainer = $this->grav['pages'] ?? null;
            if (!$pagesContainer) {
                return [];
            }

            if (method_exists($pagesContainer, 'init')) {
                try {
                    $pagesContainer->init();
                } catch (\Throwable $t) {}
            }

            $pages = $pagesContainer->all() ?: [];
        } catch (\Throwable $e) {
            $pages = [];
        }

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

                $pageChunks = $this->chunkPage($page);
                foreach ($pageChunks as $chunk) {
                    $allChunks[] = $chunk;
                }
            } catch (\Throwable $t) {
                continue;
            }
        }

        return $allChunks;
    }

    /**
     * Parse a single Grav Page into heading-aware structural text chunks.
     *
     * @param Page $page
     * @return array
     */
    public function chunkPage(Page $page): array
    {
        $route = $page->route();
        $title = $page->title();
        $rawMarkdown = $page->rawMarkdown();

        if (empty(trim($rawMarkdown))) {
            $rawMarkdown = strip_tags($page->content());
        }

        // Clean raw markdown metadata frontmatter
        $cleanMarkdown = preg_replace('/^---[\s\S]*?---\s*/', '', $rawMarkdown);
        $lines = explode("\n", $cleanMarkdown);

        $sections = [];
        $currentHeading = $title;
        $currentBuffer = [];

        foreach ($lines as $line) {
            // Match Markdown headings (H1 - H4)
            if (preg_match('/^(#{1,4})\s+(.+)$/', trim($line), $matches)) {
                if (!empty($currentBuffer)) {
                    $sections[] = [
                        'heading' => $currentHeading,
                        'content' => implode("\n", $currentBuffer)
                    ];
                    $currentBuffer = [];
                }
                $currentHeading = trim($matches[2]);
            } else {
                $currentBuffer[] = $line;
            }
        }

        if (!empty($currentBuffer)) {
            $sections[] = [
                'heading' => $currentHeading,
                'content' => implode("\n", $currentBuffer)
            ];
        }

        $chunks = [];
        $chunkIndex = 0;

        foreach ($sections as $section) {
            $headingText = $section['heading'];
            $cleanText = trim(preg_replace('/\s+/', ' ', strip_tags($section['content'])));

            if (empty($cleanText) || strlen($cleanText) < 20) {
                continue;
            }

            // Generate heading slug for anchor
            $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $headingText), '-'));
            $anchor = !empty($slug) ? "{$route}#{$slug}" : $route;

            // Split section into ~300 word sub-chunks if text is large
            $words = explode(' ', $cleanText);
            $totalWords = count($words);
            $chunkSize = 300;
            $overlap = 50;

            if ($totalWords <= $chunkSize) {
                $contentStr = implode(' ', $words);
                $chunkId = md5("{$route}_{$chunkIndex}_" . substr($contentStr, 0, 50));
                $chunks[] = [
                    'chunk_id' => $chunkId,
                    'route' => $route,
                    'title' => $title,
                    'section' => $headingText,
                    'anchor' => $anchor,
                    'content' => $contentStr,
                    'hash' => hash('sha256', $contentStr)
                ];
                $chunkIndex++;
            } else {
                for ($i = 0; $i < $totalWords; $i += ($chunkSize - $overlap)) {
                    $slice = array_slice($words, $i, $chunkSize);
                    if (empty($slice)) break;
                    $contentStr = implode(' ', $slice);
                    $chunkId = md5("{$route}_{$chunkIndex}_" . substr($contentStr, 0, 50));
                    $chunks[] = [
                        'chunk_id' => $chunkId,
                        'route' => $route,
                        'title' => $title,
                        'section' => $headingText,
                        'anchor' => $anchor,
                        'content' => $contentStr,
                        'hash' => hash('sha256', $contentStr)
                    ];
                    $chunkIndex++;
                    if ($i + $chunkSize >= $totalWords) break;
                }
            }
        }

        return $chunks;
    }
}
