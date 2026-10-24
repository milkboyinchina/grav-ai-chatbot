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

        $ip = self::getClientIp();
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

    /**
     * Resolve the visitor IP. Shared by rate limiting and lockout identity.
     */
    public static function getClientIp(): string
    {
        $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // Only trust proxy headers when the direct peer is a trusted private network.
        // Prevents trivial IP spoofing via X-Forwarded-For from the public internet.
        if (self::isTrustedProxy($remoteAddr)) {
            if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                $candidate = trim(explode(',', (string)$_SERVER['HTTP_CLIENT_IP'])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
                $candidate = trim($ips[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return $remoteAddr;
    }

    /**
     * Trust loopback and RFC1918 peers only.
     */
    public static function isTrustedProxy(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        $long &= 0xFFFFFFFF;

        // 10.0.0.0/8
        if (($long & 0xFF000000) === 0x0A000000) {
            return true;
        }
        // 172.16.0.0/12
        if (($long & 0xFFF00000) === 0xAC100000) {
            return true;
        }
        // 192.168.0.0/16
        if (($long & 0xFFFF0000) === 0xC0A80000) {
            return true;
        }

        return false;
    }
}
