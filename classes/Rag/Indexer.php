<?php
namespace Grav\Plugin\AiChatbot\Rag;

use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Plugin\AiChatbot\DataPath;

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

        DataPath::ensureDirectory();
        $this->lockFilePath = DataPath::file('rag_indexing.lock');
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
                'message' => 'Indexing appears to be in progress or was interrupted. If no rebuild is running, wait up to 30 minutes for the lock to expire, or remove user/data/ai-chatbot/rag_indexing.lock manually and try again.'
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

            $status = [
                'last_run' => time(),
                'indexed_chunks' => $indexedCount,
                'skipped_chunks' => $skippedCount,
                'total_chunks' => $this->store->getChunkCount(),
                'embedding_provider' => $this->config['rag_embedding_provider'] ?? 'tfidf_local',
                'embedding_model' => $this->config['rag_embedding_model'] ?? 'tfidf_local',
                'last_error' => null,
            ];
            @file_put_contents(DataPath::file('rag_status.json'), json_encode($status));

            return [
                'success' => true,
                'indexed_chunks' => $indexedCount,
                'skipped_chunks' => $skippedCount,
                'total_chunks' => $this->store->getChunkCount(),
                'message' => "RAG index complete. Indexed: {$indexedCount}, Skipped: {$skippedCount}."
            ];
        } catch (\Throwable $e) {
            $this->releaseLock();
            @file_put_contents(DataPath::file('rag_status.json'), json_encode([
                'last_run' => time(),
                'last_error' => $e->getMessage(),
                'embedding_provider' => $this->config['rag_embedding_provider'] ?? 'tfidf_local',
                'embedding_model' => $this->config['rag_embedding_model'] ?? 'tfidf_local',
            ]));
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Return current RAG index status for admin display.
     *
     * @return array
     */
    public function getStatus(): array
    {
        $status = [];
        $statusFile = DataPath::file('rag_status.json');
        if (file_exists($statusFile)) {
            $decoded = json_decode((string)file_get_contents($statusFile), true);
            if (is_array($decoded)) {
                $status = $decoded;
            }
        }

        $status['chunk_count'] = $this->store->getChunkCount();
        $status['db_size_bytes'] = $this->store->getStorageSize();
        $status['db_size_human'] = $this->formatBytes($status['db_size_bytes']);
        $status['embedding_provider'] = $status['embedding_provider'] ?? ($this->config['rag_embedding_provider'] ?? 'tfidf_local');
        $status['embedding_model'] = $status['embedding_model'] ?? ($this->config['rag_embedding_model'] ?? 'tfidf_local');
        $status['last_run'] = $status['last_run'] ?? null;
        $status['last_error'] = $status['last_error'] ?? null;
        $status['indexing_enabled'] = (bool)($this->config['rag_indexing_enabled'] ?? true);
        $status['scheduler_enabled'] = $this->isSchedulerEnabled();
        $status['scheduler_cron'] = (string)($this->config['rag_scheduler_cron'] ?? '0 2 * * *');

        return $status;
    }

    /**
     * Whether automatic page indexing is enabled.
     */
    public function isIndexingEnabled(): bool
    {
        return (bool)($this->config['rag_indexing_enabled'] ?? true);
    }

    /**
     * Whether the background scheduler re-indexing job is enabled.
     */
    public function isSchedulerEnabled(): bool
    {
        return (bool)($this->config['rag_indexing_enabled'] ?? true)
            && (bool)($this->config['rag_scheduler_enabled'] ?? true);
    }

    /**
     * Return all currently indexed pages (routes) with chunk counts.
     *
     * @return array
     */
    public function getIndexedPages(): array
    {
        return $this->store->getIndexedRoutes();
    }

    /**
     * Incrementally index or re-index a single saved page.
     *
     * @param Page $page
     * @return bool
     */
    public function indexSinglePage(Page $page): bool
    {
        $route = $page->route();

        if (!$page->published() || !$page->routable()) {
            $this->store->deleteRouteChunks($route);
            return true;
        }

        try {
            $supported = $this->grav['config']->get('system.languages.supported', ['en']);
            if (!is_array($supported) || empty($supported)) {
                $supported = ['en'];
            }

            $languageManager = $this->grav['language'] ?? null;
            $pagesContainer = $this->grav['pages'] ?? null;

            $this->store->deleteRouteChunks($route);

            foreach ($supported as $lang) {
                $lang = (string)$lang;
                try {
                    if ($languageManager && method_exists($languageManager, 'setActive')) {
                        $languageManager->setActive($lang);
                    }
                    if ($pagesContainer) {
                        try {
                            $ref = new \ReflectionProperty($pagesContainer, 'initialized');
                            $ref->setAccessible(true);
                            $ref->setValue($pagesContainer, false);
                        } catch (\Throwable $t) {
                        }
                        if (method_exists($pagesContainer, 'init')) {
                            try {
                                $pagesContainer->init();
                            } catch (\Throwable $t) {
                            }
                        }
                    }

                    $targetPage = null;
                    if ($pagesContainer && method_exists($pagesContainer, 'find')) {
                        try {
                            $targetPage = $pagesContainer->find($route, true);
                        } catch (\Throwable $t) {
                        }
                    }
                    if (!$targetPage instanceof Page) {
                        $targetPage = $page;
                    }
                    if (!$targetPage->published() || !$targetPage->routable()) {
                        continue;
                    }

                    if (method_exists($targetPage, 'language')) {
                        try {
                            $targetPage->language($lang);
                        } catch (\Throwable $t) {
                        }
                    }

                    $chunks = $this->chunker->chunkPage($targetPage);
                    foreach ($chunks as &$chunk) {
                        $chunk['language'] = $lang;
                    }
                    unset($chunk);

                    foreach ($chunks as $chunk) {
                        $embedding = $this->embedder->generateEmbedding($chunk['content']);
                        if (!empty($embedding)) {
                            $this->store->saveChunk($chunk, $embedding);
                        }
                    }
                } catch (\Throwable $t) {
                    continue;
                }
            }

            if ($languageManager && method_exists($languageManager, 'setActive')) {
                try {
                    $default = $this->grav['config']->get('system.languages.default', 'en');
                    $languageManager->setActive($default);
                } catch (\Throwable $t) {
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
            // Lock expires after 30 minutes (assumes crashed process)
            if (time() - filemtime($this->lockFilePath) > 1800) {
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

    /**
     * Format bytes into a human-readable string.
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int)floor(log($bytes, 1024));
        if ($i > count($units) - 1) $i = count($units) - 1;
        $val = $bytes / pow(1024, $i);
        return round($val, 2) . ' ' . $units[$i];
    }
}
