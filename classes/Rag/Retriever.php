<?php
namespace Grav\Plugin\AiChatbot\Rag;

use Grav\Common\Grav;

/**
 * Class Retriever
 * Context retriever and LLM prompt builder for RAG queries.
 *
 * @license GPL-3.0-or-later
 */
class Retriever
{
    protected Grav $grav;
    protected array $config;
    protected VectorStore $store;
    protected EmbeddingProvider $embedder;

    public function __construct(Grav $grav, array $config)
    {
        $this->grav = $grav;
        $this->config = $config;
        $this->store = new VectorStore($grav);
        $this->embedder = new EmbeddingProvider($config);
    }

    /**
     * Search vector index for visitor question and build LLM system context prompt.
     *
     * @param string $question Visitor query
     * @param int $maxContextChars Character budget limit
     * @return array Contains 'context_prompt', 'sources', and 'chunks_found'
     */
    public function searchAndBuildPrompt(string $question, int $maxContextChars = 2000): array
    {
        if (!empty($this->config['rag_enabled']) && !empty($this->config['rag_indexing_enabled'])
            && ($this->config['rag_embedding_provider'] ?? 'tfidf_local') === 'tfidf_local'
            && $this->store->getChunkCount() === 0) {
            try {
                $indexer = new Indexer($this->grav, $this->config);
                $indexer->runFullIndex();
            } catch (\Throwable $t) {
                if (class_exists('Grav\\Plugin\\AiChatbot\\Logger')) {
                    (new \Grav\Plugin\AiChatbot\Logger($this->grav))->logError($t->getMessage(), 'RAG_AUTOBOOTSTRAP');
                }
            }
        }

        $topK = (int)($this->config['rag_top_k'] ?? 5);
        $minSimilarity = ((float)($this->config['rag_min_similarity'] ?? 50)) / 100.0;

        $queryVector = $this->embedder->generateEmbedding($question);

        // Optional HyDE query expansion: generate a hypothetical answer and
        // merge its retrieval results with the original query's results.
        $expansionEnabled = !empty($this->config['rag_query_expansion']);
        $candidateK = $expansionEnabled ? max($topK * 2, $topK + 5) : $topK * 2;

        $merged = [];
        $seen = [];

        $primaryChunks = $this->store->searchSimilar($queryVector, $question, $candidateK, $minSimilarity);
        foreach ($primaryChunks as $c) {
            $merged[$c['chunk_id']] = $c;
            $seen[$c['chunk_id']] = true;
        }

        if ($expansionEnabled) {
            try {
                $hypothetical = $this->generateHypotheticalDocument($question);
                if ($hypothetical !== '') {
                    $hypVector = $this->embedder->generateEmbedding($hypothetical);
                    $hypChunks = $this->store->searchSimilar($hypVector, $question, $candidateK, $minSimilarity);
                    foreach ($hypChunks as $c) {
                        if (isset($merged[$c['chunk_id']])) {
                            // Keep the higher similarity
                            if ($c['similarity'] > $merged[$c['chunk_id']]['similarity']) {
                                $merged[$c['chunk_id']] = $c;
                            }
                        } else {
                            $merged[$c['chunk_id']] = $c;
                        }
                    }
                }
            } catch (\Throwable $t) {
            }
        }

        // Hybrid re-ranking: boost chunks where query terms appear in title/section
        $queryWords = array_unique(array_filter(str_word_count(strtolower($question), 1), function ($w) {
            return strlen($w) > 2;
        }));
        foreach ($merged as $cid => $c) {
            $titleLower = strtolower((string)($c['title'] ?? '') . ' ' . (string)($c['section'] ?? ''));
            $boost = 0.0;
            $matched = 0;
            foreach ($queryWords as $qw) {
                if (strpos($titleLower, $qw) !== false) {
                    $matched++;
                }
            }
            if (!empty($queryWords)) {
                $boost = ($matched / count($queryWords)) * 0.15;
            }
            $merged[$cid]['similarity'] = round(min(1.0, (float)$c['similarity'] + $boost), 4);
        }

        // Sort descending
        $chunks = array_values($merged);
        usort($chunks, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        // Deduplicate by route+section to avoid returning the same content
        // in multiple languages
        $deduped = [];
        $seenKeys = [];
        foreach ($chunks as $c) {
            $key = ($c['route'] ?? '') . '|' . ($c['section'] ?? '');
            if (isset($seenKeys[$key])) {
                continue;
            }
            $seenKeys[$key] = true;
            $deduped[] = $c;
            if (count($deduped) >= $topK) break;
        }
        $chunks = $deduped;

        if (empty($chunks)) {
            return [
                'context_prompt' => '',
                'sources' => [],
                'chunks_found' => 0
            ];
        }

        $formattedChunks = [];
        $sources = [];
        $accumulatedLen = 0;

        foreach ($chunks as $idx => $chunk) {
            $sourceNum = $idx + 1;
            $title = $chunk['title'];
            $section = $chunk['section'];
            $anchor = $chunk['anchor'];
            $content = $chunk['content'];

            $block = "[Source {$sourceNum}: {$title} - {$section} (URL: {$anchor})]\nSummary: {$content}";
            $blockLen = strlen($block);

            if ($accumulatedLen + $blockLen > $maxContextChars && !empty($formattedChunks)) {
                break;
            }

            $formattedChunks[] = $block;
            $sources[] = [
                'title' => $title,
                'section' => $section,
                'anchor' => $anchor,
                'similarity' => $chunk['similarity']
            ];
            $accumulatedLen += $blockLen + 5;
        }

        $contextPrompt = "Relevant Website Documentation Context for answering visitor query:\n\n" . implode("\n\n---\n\n", $formattedChunks);

        return [
            'context_prompt' => $contextPrompt,
            'sources' => $sources,
            'chunks_found' => count($formattedChunks)
        ];
    }

    /**
     * Generate a hypothetical document passage that would answer the question.
     * Used for HyDE query expansion when rag_query_expansion is enabled.
     *
     * @param string $question
     * @return string
     */
    protected function generateHypotheticalDocument(string $question): string
    {
        try {
            $clientConfig = null;
            if (class_exists('Grav\\Plugin\\AiChatbot\\ChatbotHandler')) {
                // Reuse handler's client resolution is not accessible; build minimal config
            }
            $config = $this->config;
            $provider = $config['provider'] ?? 'gemini';
            $model = $config['model'] ?? '';
            $apiKey = $config['api_key'] ?? '';
            $customEndpoint = $config['custom_endpoint'] ?? '';
            $client = \Grav\Plugin\AiChatbot\AiClientFactory::create([
                'provider' => $provider,
                'api_key' => $apiKey,
                'model' => $model,
                'custom_endpoint' => $customEndpoint,
                'api_timeout' => 15,
                'max_tokens' => 200,
            ]);
            $res = $client->generateResponse(
                'Write a short hypothetical passage that would answer the user question. No preamble.',
                [['role' => 'user', 'content' => $question]]
            );
            $answer = is_array($res) ? ($res['answer'] ?? '') : (string)$res;
            return trim($answer);
        } catch (\Throwable $t) {
            return '';
        }
    }
}
