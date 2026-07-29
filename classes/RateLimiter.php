<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;
use Grav\Common\Cache;

/**
 * Class RateLimiter
 * IP-based rate limiter using Grav Cache to prevent API key abuse and spam.
 *
 * @license GPL-3.0-or-later
 */
class RateLimiter
{
    protected Grav $grav;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
    }

    /**
     * Check if client IP is within rate limits.
     *
     * @param int $maxRequests Max allowed queries per window
     * @param int $windowSeconds Window duration in seconds
     * @return array ['allowed' => bool, 'current' => int, 'max' => int, 'reset_seconds' => int]
     */
    public function checkRateLimit(int $maxRequests = 10, int $windowSeconds = 60): array
    {
        $ip = $this->getClientIp();
        $cacheKey = 'ai_chatbot_rate_' . md5($ip);
        
        /** @var Cache $cache */
        $cache = $this->grav['cache'];
        
        $data = $cache->fetch($cacheKey);
        $currentTime = time();

        if (!$data || !is_array($data) || ($currentTime - $data['start_time']) >= $windowSeconds) {
            $data = [
                'count' => 1,
                'start_time' => $currentTime
            ];
            $cache->save($cacheKey, $data, $windowSeconds);
            return [
                'allowed' => true,
                'current' => 1,
                'max' => $maxRequests,
                'reset_seconds' => $windowSeconds
            ];
        }

        $data['count']++;
        $remainingTime = $windowSeconds - ($currentTime - $data['start_time']);
        $cache->save($cacheKey, $data, $remainingTime);

        $allowed = ($data['count'] <= $maxRequests);

        return [
            'allowed' => $allowed,
            'current' => $data['count'],
            'max' => $maxRequests,
            'reset_seconds' => max(1, $remainingTime)
        ];
    }

    protected function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
