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
    protected string $requestedProvider;
    protected string $model;
    protected string $apiKey;
    protected string $endpoint;

    public function __construct(array $config)
    {
        $this->config = $config;
        // Dedicated embedding key wins; blank reuses the main chat key so a
        // single-vendor setup keeps working with no extra configuration.
        $this->apiKey = trim((string)($config['rag_embedding_api_key'] ?? ''));
        if ($this->apiKey === '') {
            $this->apiKey = trim((string)($config['api_key'] ?? ''));
        }
        $this->model = trim((string)($config['rag_embedding_model'] ?? ''));

        $requested = strtolower(trim((string)($config['rag_embedding_provider'] ?? 'tfidf_local')));
        $allowed = ['ollama', 'gemini', 'openai', 'custom', 'tfidf_local'];
        $this->requestedProvider = in_array($requested, $allowed, true) ? $requested : 'tfidf_local';
        $this->provider = $this->requestedProvider;

        $customEndpoint = rtrim(trim((string)($config['rag_embedding_endpoint'] ?? '')), '/');
        if ($customEndpoint === '') {
            $customEndpoint = rtrim(trim((string)($config['custom_endpoint'] ?? '')), '/');
        }
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
            $this->model = $this->model ?: 'gemini-embedding-2';
        } elseif ($this->provider === 'openai' || $this->provider === 'custom') {
            if ($customEndpoint !== '') {
                $this->endpoint = $customEndpoint;
            } else {
                $this->endpoint = 'https://api.openai.com/v1';
            }
            if ($this->model === '' || $this->model === 'tfidf_local') {
                $this->model = ($this->provider === 'custom') ? '' : 'text-embedding-3-small';
            } elseif ($this->provider === 'openai' && $this->model === '') {
                $this->model = 'text-embedding-3-small';
            }
            if ($this->provider === 'custom' && $customEndpoint === '') {
                $this->provider = 'tfidf_local';
                $this->model = 'tfidf_local';
                $this->endpoint = '';
            }
        }

        if ($this->provider === 'tfidf_local') {
            $this->model = 'tfidf_local';
            $this->endpoint = '';
        }
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getRequestedProvider(): string
    {
        return $this->requestedProvider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
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
            case 'custom':
                return $this->generateOpenAiEmbedding($text);
            case 'tfidf_local':
            default:
                return $this->generateLocalTfIdfVector($text);
        }
    }

    protected function generateOllamaEmbedding(string $text): array
    {
        $base = rtrim($this->endpoint, '/');
        $isOpenAiPath = (strpos($this->endpoint, '/v1') !== false);

        // Candidate URLs: try the newer /api/embed first, then legacy /api/embeddings,
        // then the OpenAI-compatible /v1/embeddings when the endpoint looks like /v1.
        $urls = [];
        if ($isOpenAiPath) {
            $urls[] = $base . '/embeddings';          // OpenAI-compatible
        }
        $root = preg_replace('#/v1$#', '', $base);
        $urls[] = $root . '/api/embed';               // Ollama >= 0.4
        $urls[] = $root . '/api/embeddings';           // Ollama legacy

        $payload = [
            'model' => $this->model,
            'input' => $text,
            'prompt' => $text
        ];

        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response && $httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($response, true);
                if (!empty($data['embedding']) && is_array($data['embedding'])) {
                    return $data['embedding'];
                }
                if (!empty($data['embeddings'][0]) && is_array($data['embeddings'][0])) {
                    return $data['embeddings'][0];
                }
                if (!empty($data['data'][0]['embedding']) && is_array($data['data'][0]['embedding'])) {
                    return $data['data'][0]['embedding'];
                }
            }
        }

        // Fallback to local TF-IDF if all attempts fail
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
        $isDefaultOpenAi = (strpos($this->endpoint, 'api.openai.com') !== false);
        if ($isDefaultOpenAi && empty($this->apiKey)) {
            $this->logEmbeddingFailure('OpenAI embedding skipped: API key is missing.');
            return [];
        }

        $url = "{$this->endpoint}/embeddings";
        $payload = [
            'model' => $this->model,
            'input' => $text
        ];

        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = "Authorization: Bearer {$this->apiKey}";
        }

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
