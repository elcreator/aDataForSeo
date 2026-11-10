<?php

namespace Elcreator\aDataForSeo\Api;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Models\Call;
use Elcreator\aDataForSeo\Support\Config;

/**
 * Stored answers, so the same question is never paid for twice.
 *
 * The key is sha1(env + endpoint + normalised payload): keys sorted, keyword
 * lists lower-cased and sorted, `tag` and callback URLs removed — two requests
 * that differ only in those ask the same thing. Files are gzip JSON under
 * `core/storage/adataforseo/responses`; the ledger row is the index.
 */
final class Cache
{
    private const DROP = ['tag', 'pingback_url', 'postback_url', 'postback_data'];

    public static function key(string $env, string $endpoint, ?array $tasks): string
    {
        return sha1($env . '|' . Endpoints::normalise($endpoint) . '|' . json_encode(self::normalise($tasks)));
    }

    public static function normalise(mixed $value, ?string $key = null): mixed
    {
        if (is_string($value)) {
            return in_array($key, ['keyword', 'target', 'url'], true) ? mb_strtolower(trim($value)) : trim($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $list = array_map(static fn ($v) => self::normalise($v, $key === 'keywords' || $key === 'targets' ? 'keyword' : null), $value);

            if (in_array($key, ['keywords', 'targets'], true)) {
                $list = array_values(array_unique($list, SORT_REGULAR));
                sort($list);
            }

            return $list;
        }

        $out = [];

        foreach ($value as $k => $v) {
            if (in_array((string) $k, self::DROP, true)) {
                continue;
            }

            $out[(string) $k] = self::normalise($v, (string) $k);
        }

        ksort($out);

        return $out;
    }

    public static function ttl(string $endpoint): int
    {
        $ttl = (array) Config::get('api.cache_ttl', []);
        $endpoint = Endpoints::normalise($endpoint);

        foreach ($ttl as $prefix => $seconds) {
            if ($prefix !== 'default' && str_starts_with($endpoint, (string) $prefix)) {
                return (int) $seconds;
            }
        }

        return (int) ($ttl['default'] ?? 86400);
    }

    /** A stored, unexpired answer for this key. */
    public function get(string $key): ?array
    {
        $call = Call::query()
            ->where('cache_key', $key)
            ->whereNotNull('response_path')
            ->where('expires_at', '>', Carbon::now())
            ->orderByDesc('id')
            ->first();

        return $call === null ? null : self::read((string) $call->response_path);
    }

    /** @return array{path: string, expires_at: Carbon} */
    public function put(string $key, string $endpoint, array $data): array
    {
        $relative = 'responses/' . substr($key, 0, 2) . '/' . $key . '.json.gz';

        self::write($relative, $data);

        return ['path' => $relative, 'expires_at' => Carbon::now()->addSeconds(self::ttl($endpoint))];
    }

    public static function write(string $relative, array $data): void
    {
        $file = Config::storagePath($relative);

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }

        @file_put_contents($file, gzencode((string) json_encode($data, JSON_UNESCAPED_UNICODE), 6), LOCK_EX);
    }

    public static function read(string $relative): ?array
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $file = Config::storagePath($relative);

        if (!is_file($file)) {
            return null;
        }

        $raw = @gzdecode((string) file_get_contents($file));
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    public static function delete(string $relative): void
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative !== '' && !str_contains($relative, '..')) {
            @unlink(Config::storagePath($relative));
        }
    }
}
