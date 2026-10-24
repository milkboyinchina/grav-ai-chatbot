<?php
declare(strict_types=1);

namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;

/**
 * Resolves the plugin runtime data directory (user/data/ai-chatbot).
 *
 * @license GPL-3.0-or-later
 */
class DataPath
{
    /**
     * Absolute path to the plugin data directory.
     */
    public static function directory(): string
    {
        $grav = class_exists(Grav::class) ? Grav::instance() : null;
        if ($grav !== null && isset($grav['locator'])) {
            $base = $grav['locator']->findResource('user://data', true);
            if (is_string($base) && $base !== '') {
                return rtrim($base, '/') . '/ai-chatbot';
            }
        }

        if (defined('GRAV_ROOT')) {
            return rtrim((string)GRAV_ROOT, '/') . '/user/data/ai-chatbot';
        }

        return rtrim(dirname(__DIR__, 3), '/') . '/data/ai-chatbot';
    }

    /**
     * Absolute path to a file inside the plugin data directory.
     */
    public static function file(string $name): string
    {
        return self::directory() . '/' . ltrim($name, '/');
    }

    /**
     * Create the plugin data directory when missing.
     */
    public static function ensureDirectory(): string
    {
        $directory = self::directory();
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }
}
