<?php

namespace Elcreator\aDataForSeo\Engine;

use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\Crypt;

/**
 * The public wake-up URL DataForSEO calls when a task is ready.
 *
 * `token` = HMAC-SHA256(job uuid, site secret): unguessable, and it only ever
 * wakes a job — results are always fetched by the worker, never read from the
 * request.
 */
final class Pingback
{
    public const PATH = 'adataforseo/ping/';

    public static function token(string $jobUuid): string
    {
        return hash_hmac('sha256', $jobUuid, Crypt::derive('adataforseo-pingback'));
    }

    public static function url(string $jobUuid): ?string
    {
        if (!Config::pingbackEnabled()) {
            return null;
        }

        $site = rtrim((string) evo()->getConfig('site_url', ''), '/');

        if (!preg_match('#^https?://#', $site)) {
            return null;
        }

        // `$id` and `$tag` are DataForSEO's placeholders, filled on its side.
        return $site . '/' . self::PATH . self::token($jobUuid) . '?id=$id&tag=$tag';
    }

    /** The token from a request path, or null when the path is not ours. */
    public static function tokenFromPath(string $path): ?string
    {
        $path = trim(parse_url($path, PHP_URL_PATH) ?: '', '/');

        if (!preg_match('#(?:^|/)adataforseo/ping/([a-f0-9]{64})$#', $path, $m)) {
            return null;
        }

        return $m[1];
    }
}
