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
    protected array $config;

    public function __construct(Grav $grav, array $config = [])
    {
        $this->grav = $grav;
        $this->config = $config;
    }

    /**
     * Check if client IP is within rate limits.
     *
     * @param mixed $maxRequests Max allowed queries per window
     * @param mixed $windowSeconds Window duration in seconds
     * @return array ['allowed' => bool, 'current' => int, 'max' => int, 'reset_seconds' => int]
     */
    public function checkRateLimit($maxRequests = 10, $windowSeconds = 60): array
    {
        if (is_string($maxRequests) && !is_numeric($maxRequests)) {
            $maxRequests = (int)($this->config['rate_limit_max_requests'] ?? 10);
        } else {
            $maxRequests = (int)$maxRequests;
        }
        if ($maxRequests <= 0) {
            $maxRequests = 10;
        }

        $windowSeconds = (int)$windowSeconds;
        if ($windowSeconds <= 0) {
            $windowSeconds = 60;
        }

        $ip = $this->getClientIp();
        $cacheKey = 'ai_chatbot_rate_' . md5($ip);
        
        /** @var Cache $cache */
        $cache = $this->grav['cache'] ?? null;
        
        if (!$cache) {
            return [
                'allowed' => true,
                'current' => 1,
                'max' => $maxRequests,
                'reset_seconds' => $windowSeconds
            ];
        }

        $data = $cache->fetch($cacheKey);
        $currentTime = time();

        if (!$data || !is_array($data) || ($currentTime - ($data['start_time'] ?? 0)) >= $windowSeconds) {
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
        $cache->save($cacheKey, $data, max(1, $remainingTime));

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
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        // Only trust proxy headers when the direct peer is a trusted private/docker network.
        // Prevents trivial IP spoofing via X-Forwarded-For from the public internet.
        if ($this->isTrustedProxy($remoteAddr)) {
            if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                $candidate = trim(explode(',', $_SERVER['HTTP_CLIENT_IP'])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $candidate = trim($ips[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
        return $remoteAddr;
    }

    protected function isTrustedProxy(string $ip): bool
    {
        // Loopback + RFC1918 + docker bridge ranges
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
        if (str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.') || str_starts_with($ip, '172.')) {
            return true;
        }
        return false;
    }
}
