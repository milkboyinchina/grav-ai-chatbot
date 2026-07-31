<?php
namespace Grav\Plugin\AiChatbot\Rag;

use Grav\Common\Grav;
use Grav\Common\Page\Page;

/**
 * Class Indexer
 * Full and incremental batch page indexing engine for Grav CMS RAG.
 *
 * @license GPL-3.0-or-later
 */
class Indexer
{
    protected Grav $grav;
    protected array $config;
    protected Chunker $chunker;
    protected VectorStore $store;
    protected EmbeddingProvider $embedder;
    protected string $lockFilePath;

    public function __construct(Grav $grav, array $config)
    {
        $this->grav = $grav;
        $this->config = $config;
        $this->chunker = new Chunker($grav);
        $this->store = new VectorStore($grav);
        $this->embedder = new EmbeddingProvider($config);

        $dataDir = $grav['locator']->findResource('user://data/ai-chatbot', true, true) ?: '/var/www/html/user/data/ai-chatbot';
        $this->lockFilePath = $dataDir . '/rag_indexing.lock';
    }

    /**
     * Static helper for Grav Scheduler job execution.
     *
     * @param Grav $grav
     * @param array $config
     * @return array Results summary
     */
    public static function reindexAll(Grav $grav, array $config): array
    {
        $indexer = new self($grav, $config);
        return $indexer->runFullIndex();
    }

    /**
     * Run full re-indexing across all published site pages.
     *
     * @param bool $forceRebuild Force re-embedding even if hash matches
     * @return array Statistics
     */
    public function runFullIndex(bool $forceRebuild = false): array
    {
        if ($this->isLocked()) {
            return [
                'success' => false,
                'message' => 'Indexing already in progress (Lock file present).'
            ];
        }

        $this->acquireLock();

        try {
            if ($forceRebuild) {
                $this->store->clearAllChunks();
            }

            $chunks = $this->chunker->chunkAllPages();
            $indexedCount = 0;
            $skippedCount = 0;

            foreach ($chunks as $chunk) {
                $existingHash = $this->store->getChunkHash($chunk['chunk_id']);

                if (!$forceRebuild && $existingHash === $chunk['hash']) {
                    $skippedCount++;
                    continue;
                }

                $embedding = $this->embedder->generateEmbedding($chunk['content']);
                if (!empty($embedding)) {
                    $this->store->saveChunk($chunk, $embedding);
                    $indexedCount++;
                }
            }

            $this->releaseLock();

            return [
                'success' => true,
                'indexed_chunks' => $indexedCount,
                'skipped_chunks' => $skippedCount,
                'total_chunks' => $this->store->getChunkCount(),
                'message' => "RAG index complete. Indexed: {$indexedCount}, Skipped: {$skippedCount}."
            ];
        } catch (\Throwable $e) {
            $this->releaseLock();
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Incrementally index or re-index a single saved page.
     *
     * @param Page $page
     * @return bool
     */
    public function indexSinglePage(Page $page): bool
    {
        if (!$page->published() || !$page->routable()) {
            $this->store->deleteRouteChunks($page->route());
            return true;
        }

        try {
            $chunks = $this->chunker->chunkPage($page);
            $this->store->deleteRouteChunks($page->route());

            foreach ($chunks as $chunk) {
                $embedding = $this->embedder->generateEmbedding($chunk['content']);
                if (!empty($embedding)) {
                    $this->store->saveChunk($chunk, $embedding);
                }
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Remove page chunks when a page is deleted.
     *
     * @param string $route
     * @return bool
     */
    public function removePageByRoute(string $route): bool
    {
        return $this->store->deleteRouteChunks($route);
    }

    protected function isLocked(): bool
    {
        if (file_exists($this->lockFilePath)) {
            // Lock expires after 10 minutes
            if (time() - filemtime($this->lockFilePath) > 600) {
                @unlink($this->lockFilePath);
                return false;
            }
            return true;
        }
        return false;
    }

    protected function acquireLock(): void
    {
        @file_put_contents($this->lockFilePath, (string)time());
    }

    protected function releaseLock(): void
    {
        if (file_exists($this->lockFilePath)) {
            @unlink($this->lockFilePath);
        }
    }
}
