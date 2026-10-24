<?php
namespace Grav\Plugin\AiChatbot\Rag;

/**
 * Class EmbeddingProvider
 * Vector embedding generator for Ollama, Google Gemini, OpenAI, and local TF-IDF.
 *
 * @license GPL-3.0-or-later
 */
class EmbeddingProvider
{
    protected array $config;
    protected string $provider;
    protected string $model;
    protected string $apiKey;
    protected string $endpoint;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->apiKey = trim((string)($config['api_key'] ?? ''));
        $this->model = trim((string)($config['rag_embedding_model'] ?? ''));

        $requested = strtolower(trim((string)($config['rag_embedding_provider'] ?? 'tfidf_local')));
        $allowed = ['ollama', 'gemini', 'openai', 'tfidf_local'];
        $this->provider = in_array($requested, $allowed, true) ? $requested : 'tfidf_local';

        $customEndpoint = rtrim(trim((string)($config['custom_endpoint'] ?? '')), '/');
        $this->endpoint = '';

        if ($this->provider === 'ollama') {
            if ($customEndpoint === '') {
                $this->provider = 'tfidf_local';
            } else {
                $this->endpoint = $customEndpoint;
                $this->model = $this->model ?: 'nomic-embed-text';
            }
        } elseif ($this->provider === 'gemini') {
            $this->endpoint = 'https://generativelanguage.googleapis.com/v1beta';
            $this->model = $this->model ?: 'text-embedding-004';
        } elseif ($this->provider === 'openai') {
            $this->endpoint = 'https://api.openai.com/v1';
            $this->model = $this->model ?: 'text-embedding-3-small';
        }

        if ($this->provider === 'tfidf_local') {
            $this->model = 'tfidf_local';
            $this->endpoint = '';
        }
    }

    /**
     * Generate float vector embedding array for text string.
     *
     * @param string $text
     * @return array Vector array of floats
     */
    public function generateEmbedding(string $text): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (empty($text)) {
            return [];
        }

        switch ($this->provider) {
            case 'ollama':
                return $this->generateOllamaEmbedding($text);
            case 'gemini':
                return $this->generateGeminiEmbedding($text);
            case 'openai':
                return $this->generateOpenAiEmbedding($text);
            case 'tfidf_local':
            default:
                return $this->generateLocalTfIdfVector($text);
        }
    }

    protected function generateOllamaEmbedding(string $text): array
    {
        $url = (strpos($this->endpoint, '/v1') !== false)
            ? rtrim($this->endpoint, '/') . '/embeddings'
            : rtrim($this->endpoint, '/') . '/api/embeddings';

        $payload = [
            'model' => $this->model,
            'prompt' => $text,
            'input' => $text
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            if (!empty($data['embedding']) && is_array($data['embedding'])) {
                return $data['embedding'];
            }
            if (!empty($data['data'][0]['embedding']) && is_array($data['data'][0]['embedding'])) {
                return $data['data'][0]['embedding'];
            }
        }

        // Fallback to local TF-IDF if Ollama embedding call fails
        return $this->generateLocalTfIdfVector($text);
    }

    protected function generateGeminiEmbedding(string $text): array
    {
        if (empty($this->apiKey)) {
            return $this->generateLocalTfIdfVector($text);
        }

        $url = "{$this->endpoint}/models/{$this->model}:embedContent";
        $payload = [
            'model' => "models/{$this->model}",
            'content' => [
                'parts' => [['text' => $text]]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            if (!empty($data['embedding']['values']) && is_array($data['embedding']['values'])) {
                return $data['embedding']['values'];
            }
        }

        return $this->generateLocalTfIdfVector($text);
    }

    protected function generateOpenAiEmbedding(string $text): array
    {
        if (empty($this->apiKey)) {
            $this->logEmbeddingFailure('OpenAI embedding skipped: API key is missing.');
            return [];
        }

        $url = "{$this->endpoint}/embeddings";
        $payload = [
            'model' => $this->model,
            'input' => $text
        ];

        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$this->apiKey}"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            $this->logEmbeddingFailure('OpenAI embedding request failed: ' . ($curlError ?: 'empty response'));
            return [];
        }

        $data = json_decode($response, true);
        if (!empty($data['data'][0]['embedding']) && is_array($data['data'][0]['embedding'])) {
            return $data['data'][0]['embedding'];
        }

        $error = $data['error']['message'] ?? 'response did not contain an embedding';
        $this->logEmbeddingFailure('OpenAI embedding request failed: ' . $error);

        return [];
    }

    /**
     * Record provider failures without breaking the indexing request.
     */
    protected function logEmbeddingFailure(string $message): void
    {
        if (!class_exists('Grav\Common\Grav')) {
            return;
        }

        try {
            $grav = \Grav\Common\Grav::instance();
            (new \Grav\Plugin\AiChatbot\Logger($grav))->logError($message, 'EMBEDDING');
        } catch (\Throwable $t) {
            // Logging must never abort indexing.
        }
    }

    /**
     * In-memory local term-frequency vectorizer fallback (zero API token cost).
     *
     * @param string $text
     * @return array 64-dimension normalized term frequency vector
     */
    public function generateLocalTfIdfVector(string $text): array
    {
        $words = str_word_count(strtolower($text), 1);
        $dim = 64;
        $vector = array_fill(0, $dim, 0.0);

        foreach ($words as $word) {
            $hash = crc32($word);
            $index = abs($hash) % $dim;
            $vector[$index] += 1.0;
        }

        // L2 Normalize
        $sumSq = 0.0;
        foreach ($vector as $v) {
            $sumSq += $v * $v;
        }

        $norm = sqrt($sumSq);
        if ($norm > 0) {
            for ($i = 0; $i < $dim; $i++) {
                $vector[$i] /= $norm;
            }
        }

        return $vector;
    }
}
