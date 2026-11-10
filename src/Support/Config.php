<?php

namespace Elcreator\aDataForSeo\Support;

/**
 * Typed access to `cms.settings.aDataForSeo`.
 */
final class Config
{
    public static function get(string $key, $default = null)
    {
        return config('cms.settings.aDataForSeo.' . $key, $default);
    }

    public static function isEnabled(): bool
    {
        return (bool) static::get('enable', true);
    }

    public static function limit(string $key, $default = 0)
    {
        return static::get('limits.' . $key, $default);
    }

    public static function budget(string $key, $default = 0.0): float
    {
        return (float) static::get('budget.' . $key, $default);
    }

    public static function queueDriver(): string
    {
        $driver = strtolower(trim((string) static::get('queue.driver', 'auto')));

        return in_array($driver, ['auto', 'system', 'stask'], true) ? $driver : 'auto';
    }

    public static function pingbackEnabled(): bool
    {
        return (bool) static::get('queue.pingback', false);
    }

    public static function env(): string
    {
        $env = strtolower(trim((string) static::get('api.env', 'prod')));

        return in_array($env, ['prod', 'sandbox', 'demo'], true) ? $env : 'prod';
    }

    /** @return int[] seconds between `tasks_ready` checks; the last one repeats */
    public static function pollSeconds(): array
    {
        $values = array_values(array_filter(array_map('intval', (array) static::limit('poll_seconds', [60, 120, 300])), static fn ($v) => $v > 0));

        return $values ?: [60, 120, 300];
    }

    /** Where cached answers and the price table live. */
    public static function storagePath(string $file = ''): string
    {
        $base = defined('EVO_CORE_PATH') ? rtrim(str_replace('\\', '/', EVO_CORE_PATH), '/') . '/storage' : sys_get_temp_dir();

        return $base . '/adataforseo' . ($file !== '' ? '/' . ltrim($file, '/') : '');
    }

    /** The vocabulary of the configured vertical (`config/lexicon/<vertical>.php`). */
    public static function lexicon(?string $vertical = null): array
    {
        static $cache = [];
        $vertical = preg_replace('/[^a-z0-9_]/', '', strtolower($vertical ?? (string) static::get('research.vertical', 'real_estate'))) ?: 'real_estate';

        if (!isset($cache[$vertical])) {
            $file = dirname(__DIR__, 2) . '/config/lexicon/' . $vertical . '.php';
            $cache[$vertical] = is_file($file) ? (array) require $file : ['languages' => []];
        }

        return $cache[$vertical];
    }
}
