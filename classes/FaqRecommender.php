<?php
namespace Grav\Plugin\AiChatbot;

/**
 * Class FaqRecommender
 * Analyzes interaction log history to discover frequent visitor questions served by AI API,
 * providing candidate FAQ recommendations for site administrators.
 *
 * @license GPL-3.0-or-later
 */
class FaqRecommender
{
    /**
     * Generate candidate FAQ recommendations from log records.
     *
     * @param array $logs Interaction log array
     * @param int $minOccurrences Minimum query count threshold (default 2)
     * @return array Array of ['normalized_question' => string, 'sample_question' => string, 'suggested_answer' => string, 'count' => int]
     */
    public static function getRecommendations(array $logs, int $minOccurrences = 2): array
    {
        $aiLogs = array_filter($logs, function ($item) {
            return ($item['source'] ?? '') === 'ai_api' && !empty($item['question']);
        });

        $clusters = [];
        foreach ($aiLogs as $log) {
            $q = $log['question'];
            $norm = self::normalizeQuestion($q);

            if (empty($norm) || strlen($norm) < 6) {
                continue;
            }

            // Find matching existing cluster key
            $foundKey = null;
            foreach (array_keys($clusters) as $existingKey) {
                similar_text($norm, $existingKey, $percent);
                if ($percent >= 75) {
                    $foundKey = $existingKey;
                    break;
                }
            }

            if ($foundKey !== null) {
                $clusters[$foundKey]['count']++;
            } else {
                $clusters[$norm] = [
                    'normalized_question' => $norm,
                    'sample_question' => $q,
                    'suggested_answer' => $log['answer'] ?? '',
                    'count' => 1
                ];
            }
        }

        // Filter clusters meeting threshold and sort descending by frequency
        $recommendations = array_filter($clusters, function ($item) use ($minOccurrences) {
            return $item['count'] >= $minOccurrences;
        });

        usort($recommendations, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return array_slice($recommendations, 0, 10);
    }

    protected static function normalizeQuestion(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^\w\s]/u', '', $text);
        return preg_replace('/\s+/', ' ', $text);
    }
}
