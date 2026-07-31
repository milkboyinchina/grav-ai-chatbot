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
        $topK = (int)($this->config['rag_top_k'] ?? 3);
        $minSimilarity = ((float)($this->config['rag_min_similarity'] ?? 65)) / 100.0;

        $queryVector = $this->embedder->generateEmbedding($question);
        $chunks = $this->store->searchSimilar($queryVector, $question, $topK, $minSimilarity);

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
}
