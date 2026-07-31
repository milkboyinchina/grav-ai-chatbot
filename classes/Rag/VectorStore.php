<?php
namespace Grav\Plugin\AiChatbot\Rag;

use Grav\Common\Grav;

/**
 * Class VectorStore
 * Local SQLite storage and similarity search engine for RAG page chunks.
 *
 * @license GPL-3.0-or-later
 */
class VectorStore
{
    protected Grav $grav;
    protected string $dbPath;
    protected ?\PDO $pdo = null;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
        $dataDir = $grav['locator']->findResource('user://data/ai-chatbot', true, true);
        if (!$dataDir) {
            $dataDir = '/var/www/html/user/data/ai-chatbot';
        }
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }

        $this->dbPath = $dataDir . '/rag_index.sqlite';
        $this->initDb();
    }

    protected function initDb(): void
    {
        try {
            $this->pdo = new \PDO("sqlite:{$this->dbPath}");
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS rag_chunks (
                    chunk_id TEXT PRIMARY KEY,
                    route TEXT NOT NULL,
                    title TEXT NOT NULL,
                    section TEXT NOT NULL,
                    anchor TEXT NOT NULL,
                    content TEXT NOT NULL,
                    hash TEXT NOT NULL,
                    embedding_blob TEXT NOT NULL,
                    updated_at INTEGER NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_rag_route ON rag_chunks(route);
            ");
        } catch (\Throwable $e) {
            $this->pdo = null;
        }
    }

    public function saveChunk(array $chunk, array $embedding): bool
    {
        if (!$this->pdo) return false;

        try {
            $stmt = $this->pdo->prepare("
                INSERT OR REPLACE INTO rag_chunks (chunk_id, route, title, section, anchor, content, hash, embedding_blob, updated_at)
                VALUES (:chunk_id, :route, :title, :section, :anchor, :content, :hash, :embedding_blob, :updated_at)
            ");

            return $stmt->execute([
                ':chunk_id' => $chunk['chunk_id'],
                ':route' => $chunk['route'],
                ':title' => $chunk['title'],
                ':section' => $chunk['section'],
                ':anchor' => $chunk['anchor'],
                ':content' => $chunk['content'],
                ':hash' => $chunk['hash'],
                ':embedding_blob' => json_encode($embedding),
                ':updated_at' => time()
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getChunkHash(string $chunkId): ?string
    {
        if (!$this->pdo) return null;
        try {
            $stmt = $this->pdo->prepare("SELECT hash FROM rag_chunks WHERE chunk_id = :id");
            $stmt->execute([':id' => $chunkId]);
            $res = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $res ? $res['hash'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function deleteRouteChunks(string $route): bool
    {
        if (!$this->pdo) return false;
        try {
            $stmt = $this->pdo->prepare("DELETE FROM rag_chunks WHERE route = :route");
            return $stmt->execute([':route' => $route]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function clearAllChunks(): bool
    {
        if (!$this->pdo) return false;
        try {
            return $this->pdo->exec("DELETE FROM rag_chunks") !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getChunkCount(): int
    {
        if (!$this->pdo) return 0;
        try {
            $res = $this->pdo->query("SELECT COUNT(*) as cnt FROM rag_chunks")->fetch(\PDO::FETCH_ASSOC);
            return (int)($res['cnt'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Search Top-K similar chunks matching query vector or text keywords.
     *
     * @param array $queryVector Query float vector
     * @param string $queryText Raw user question string
     * @param int $topK Maximum chunks to return
     * @param float $minSimilarity Minimum score (0.0 - 1.0)
     * @return array Matches array with similarity score
     */
    public function searchSimilar(array $queryVector, string $queryText, int $topK = 3, float $minSimilarity = 0.5): array
    {
        if (!$this->pdo) return [];

        try {
            $stmt = $this->pdo->query("SELECT * FROM rag_chunks");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        if (empty($rows)) return [];

        $queryWords = array_unique(str_word_count(strtolower($queryText), 1));

        $results = [];
        foreach ($rows as $row) {
            $vector = json_decode($row['embedding_blob'], true) ?: [];
            $cosineSim = 0.0;

            if (!empty($vector) && !empty($queryVector) && count($vector) === count($queryVector)) {
                $cosineSim = $this->cosineSimilarity($queryVector, $vector);
            }

            // Keyword overlap score boost
            $contentLower = strtolower($row['content'] . ' ' . $row['title'] . ' ' . $row['section']);
            $matchedWords = 0;
            foreach ($queryWords as $qw) {
                if (strlen($qw) > 2 && strpos($contentLower, $qw) !== false) {
                    $matchedWords++;
                }
            }

            $keywordBoost = !empty($queryWords) ? ($matchedWords / count($queryWords)) * 0.4 : 0.0;
            $finalScore = min(1.0, ($cosineSim * 0.6) + $keywordBoost);

            if ($finalScore >= $minSimilarity || (!empty($matchedWords) && $matchedWords >= 2)) {
                $row['similarity'] = round($finalScore, 4);
                $results[] = $row;
            }
        }

        // Sort descending by similarity score
        usort($results, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($results, 0, $topK);
    }

    protected function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $count = count($vecA);
        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);
        return ($denom > 0) ? ($dotProduct / $denom) : 0.0;
    }
}
