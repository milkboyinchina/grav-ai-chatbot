<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;

/**
 * Class SecurityGuardrail
 * Comprehensive security guardrail enforcing Prompt Injection defense, XSS/SQL Injection blocking,
 * Leetspeak normalization, Credential Stuffing cool-off protection, and user/pages/ read-only boundary.
 *
 * @license GPL-3.0-or-later
 */
class SecurityGuardrail
{
    protected Grav $grav;
    protected array $config;
    protected static string $rateLimitFile = '';

    protected static function getRateLimitFile(): string
    {
        if (empty(self::$rateLimitFile)) {
            self::$rateLimitFile = (defined('GRAV_ROOT') ? GRAV_ROOT : '') . '/user/data/ai-chatbot/security_violations.json';
        }
        return self::$rateLimitFile;
    }

    // Expanded default blacklisted patterns matrix
    protected array $blacklistedPatterns = [
        // A. Prompt Injection & Jailbreaks (Multilingual English & Indonesian)
        '/ignore\s+previous\s+instructions/i',
        '/forget\s+all\s+prior\s+instructions/i',
        '/override\s+system\s+prompt/i',
        '/reveal\s+system\s+prompt/i',
        '/show\s+your\s+rules/i',
        '/disregard\s+safety\s+guidelines/i',
        '/dan\s+mode/i',
        '/developer\s+mode/i',
        '/pretend\s+you\s+are/i',
        '/act\s+as\s+an?\s+unrestricted/i',
        '/act\s+as\s+a\s+linux\s+terminal/i',
        '/abaikan\s+instruksi\s+sebelumnya/i',
        '/hapus\s+aturan/i',

        // B. Script Injection (XSS) Vectors
        '/<script/i',
        '/javascript:/i',
        '/onerror=/i',
        '/onload=/i',
        '/eval\(/i',
        '/exec\(/i',
        '/document\.cookie/i',
        '/window\.localStorage/i',
        '/fetch\(/i',
        '/<iframe/i',
        '/<svg\/onload/i',
        '/<img\s+src=x/i',

        // C. SQL Injection Vectors
        '/union\s+select/i',
        '/drop\s+table/i',
        '/insert\s+into/i',
        '/update\s+users/i',
        '/delete\s+from/i',
        '/or\s+1=1/i',
        "/'\\s*or\\s*'1'='1/i",
        '/information_schema/i',
        '/sys\.tables/i',
        '/;\s*--/',

        // D. Credential Stuffing & Sensitive File Probes
        '/admin_password/i',
        '/secret_token/i',
        '/auth_token/i',
        '/session_key/i',
        '/private_key/i',
        '/id_rsa/i',
        '/etc\/passwd/i',
        '/etc\/shadow/i',
        '/docker\.sock/i',
        '/\.env/i',
        '/vps_root/i',
        '/config\.php/i',
        '/system\.yaml/i',
        '/user\/config/i',

        // E. System Command Injection Vectors
        '/cat\s+\/etc\/passwd/i',
        '/rm\s+-rf/i',
        '/chmod\s+777/i',
        '/wget\s+http/i',
        '/curl\s+-O/i',
        '/nc\s+-e/i',
        '/\/bin\/sh/i',
        '/\/bin\/bash/i',
        '/sudo\s+su/i',
        '/powershell\s+-e/i'
    ];

    public function __construct(Grav $grav, array $config = [])
    {
        $this->grav = $grav;
        $this->config = $config;
    }

    /**
     * Inspects input query string against multi-category security guardrails.
     *
     * @param string $input Raw visitor question
     * @return array ['allowed' => bool, 'reason' => string|null, 'normalized' => string]
     */
    public function inspect(string $input): array
    {
        $normalized = $this->normalizeInput($input);

        // 1. Check Leetspeak & Pattern Matrix
        foreach ($this->blacklistedPatterns as $pattern) {
            if (preg_match($pattern, $normalized) || preg_match($pattern, $input)) {
                return [
                    'allowed' => false,
                    'reason' => 'Security Guardrail: Input matched prohibited safety pattern or prompt injection vector.',
                    'normalized' => $normalized
                ];
            }
        }

        // 2. Check Custom Config Banned Words
        $customWords = $this->config['blacklist_words'] ?? '';
        if (!empty($customWords)) {
            $words = array_map('trim', preg_split('/[\r\n,]+/', $customWords));
            foreach ($words as $word) {
                if ($word === '') continue;
                $pattern = '/\b' . preg_quote($word, '/') . '\b/i';
                if (preg_match($pattern, $normalized) || preg_match($pattern, $input)) {
                    return [
                        'allowed' => false,
                        'reason' => 'Security Guardrail: Input contained restricted vocabulary.',
                        'normalized' => $normalized
                    ];
                }
            }
        }

        // 3. Enforce Strict user/pages/ Scope Probing Detection
        if ($this->isSystemPathProbe($normalized)) {
            return [
                'allowed' => false,
                'reason' => 'Security Scope Guardrail: Chatbot access is strictly restricted to user/pages/ content.',
                'normalized' => $normalized
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'normalized' => $normalized
        ];
    }

    /**
     * Leetspeak & Unicode Normalization (e.g. h4ck -> hack, @dmin -> admin, zero-width spaces).
     */
    public function normalizeInput(string $input): string
    {
        // Strip zero-width characters and control chars
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x00-\x1F]/u', '', $input);
        $text = strtolower($text);

        // Leetspeak substitution map
        $replacements = [
            '0' => 'o',
            '1' => 'i',
            '3' => 'e',
            '4' => 'a',
            '5' => 's',
            '7' => 't',
            '8' => 'b',
            '@' => 'a',
            '$' => 's',
            '!' => 'i'
        ];

        return strtr($text, $replacements);
    }

    /**
     * Detects queries attempting to probe system files or configurations outside user/pages/.
     */
    protected function isSystemPathProbe(string $text): bool
    {
        $probeKeywords = [
            'user/config',
            'system/src',
            'vendor/composer',
            'bin/grav',
            'cache/compiled',
            'system.yaml',
            'config/plugins',
            'admin_password',
            'database password',
            'api_key',
            'secret_key'
        ];

        foreach ($probeKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Progressive IP Cool-Off Protection for Credential Stuffing & Repeated Attacks.
     *
     * @param string $ipHash Anonymized visitor IP hash
     * @param int $maxViolations Maximum allowed violations before lockout (default 5)
     * @param int $windowSeconds Monitoring window in seconds (default 60)
     * @param int $lockoutSeconds Lockout duration in seconds (default 900 = 15 minutes)
     * @return bool True if IP is currently locked out
     */
    public static function isIpLockedOut(string $ipHash, int $maxViolations = 5, int $windowSeconds = 60, int $lockoutSeconds = 900): bool
    {
        $records = self::loadViolationRecords();
        $now = time();

        if (isset($records[$ipHash])) {
            $ipData = $records[$ipHash];
            // Check if currently locked out
            if (!empty($ipData['locked_until']) && $ipData['locked_until'] > $now) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a security violation for an IP hash and trigger lockout if threshold exceeded.
     */
    public static function recordViolation(string $ipHash, int $maxViolations = 5, int $windowSeconds = 60, int $lockoutSeconds = 900): void
    {
        $records = self::loadViolationRecords();
        $now = time();

        $ipData = $records[$ipHash] ?? ['timestamps' => [], 'locked_until' => 0];
        
        // Filter out timestamps outside monitoring window
        $ipData['timestamps'] = array_filter($ipData['timestamps'], function ($ts) use ($now, $windowSeconds) {
            return ($now - $ts) <= $windowSeconds;
        });

        $ipData['timestamps'][] = $now;

        if (count($ipData['timestamps']) >= $maxViolations) {
            $ipData['locked_until'] = $now + $lockoutSeconds;
        }

        $records[$ipHash] = $ipData;
        self::saveViolationRecords($records);
    }

    /**
     * Get summary data and recent security audit logs for Admin 2 dashboard card.
     */
    public static function getSecurityAuditData(Grav $grav): array
    {
        $records = self::loadViolationRecords();
        $now = time();
        $activeLockouts = 0;
        $totalViolations = 0;

        foreach ($records as $ipHash => $data) {
            if (!empty($data['locked_until']) && $data['locked_until'] > $now) {
                $activeLockouts++;
            }
            if (!empty($data['timestamps'])) {
                $totalViolations += count($data['timestamps']);
            }
        }

        // Fetch recent security guardrail log entries from Logger
        $logger = new Logger($grav);
        $rawLogs = $logger->getErrorLogs();
        $allLogs = is_string($rawLogs) ? array_filter(explode("\n", $rawLogs)) : (array)$rawLogs;
        $securityLogs = [];

        foreach (array_reverse($allLogs) as $logLine) {
            $msg = is_array($logLine) ? ($logLine['message'] ?? '') : (string)$logLine;

            if (str_contains($msg, 'Security Guardrail') || str_contains($msg, 'Security Scope')) {
                // Extract IP Hash snippet
                preg_match('/\[IP Hash:\s*([a-f0-9]+)\]/i', $msg, $matches);
                $ipHash = !empty($matches[1]) ? $matches[1] : 'Anonymous';

                // Extract timestamp
                preg_match('/^\[(.*?)\]/i', $msg, $timeMatches);
                $ts = !empty($timeMatches[1]) ? $timeMatches[1] : date('Y-m-d H:i:s');

                $securityLogs[] = [
                    'timestamp' => $ts,
                    'ip_hash' => $ipHash,
                    'reason' => preg_replace('/^\[.*?\]\s*\[ERROR\]\s*\[.*?\]\s*Security Guardrail Blocked Query \[IP Hash:\s*[a-f0-9]+\]:\s*/i', '', $msg),
                    'status' => 'Blocked'
                ];

                if (count($securityLogs) >= 15) break;
            }
        }

        return [
            'threat_level' => $activeLockouts > 0 ? 'warning' : 'secure',
            'active_lockouts' => $activeLockouts,
            'total_violations' => max($totalViolations, count($securityLogs)),
            'logs' => $securityLogs
        ];
    }

    /**
     * Release all active IP lockouts.
     */
    public static function releaseLockouts(): void
    {
        self::saveViolationRecords([]);
    }

    protected static function loadViolationRecords(): array
    {
        $file = self::getRateLimitFile();
        if (!file_exists($file)) {
            return [];
        }
        $content = @file_get_contents($file);
        return $content ? (json_decode($content, true) ?: []) : [];
    }

    protected static function saveViolationRecords(array $records): void
    {
        $file = self::getRateLimitFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($file, json_encode($records, JSON_PRETTY_PRINT));
    }
}
