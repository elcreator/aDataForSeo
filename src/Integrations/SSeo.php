<?php

namespace Elcreator\aDataForSeo\Integrations;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Where fixes land: sSeo (seiger/sseo), a soft dependency.
 *
 * Writes go **only** through `sSeo::updateSeoFields()` with the changed fields
 * only, so sSeo resolves sLang languages and the sMultisite domain key itself.
 * `before` values are read from `s_seo`. Without sSeo, fixes of these kinds are
 * shown as instructions and nothing is written.
 */
final class SSeo
{
    /** Where a manager without sSeo is sent to get it. */
    public const HOMEPAGE = 'https://github.com/Seiger/sSeo';

    public const FACADE = 'Seiger\sSeo\Facades\sSeo';
    public const MODEL = 'Seiger\sSeo\Models\sSeoModel';
    public const REDIRECT = 'Seiger\sSeo\Models\sRedirect';

    /** Fields a fix may write. */
    public const FIELDS = ['meta_title', 'meta_description', 'meta_keywords', 'robots', 'canonical_url', 'exclude_from_sitemap'];

    /** A stand-in store for tests (read/write/redirect/deleteRedirect/available). */
    private static ?object $fake = null;

    public static function fake(?object $fake): void
    {
        self::$fake = $fake;
    }

    public static function available(): bool
    {
        if (self::$fake !== null) {
            return (bool) self::$fake->available();
        }

        try {
            return class_exists(self::FACADE) && class_exists(self::MODEL) && Schema::hasTable('s_seo');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> current values of the given fields ('' when unset) */
    public static function read(int $resourceId, string $type, string $lang, array $fields = self::FIELDS): array
    {
        if (self::$fake !== null) {
            return self::$fake->read($resourceId, $type, $lang, $fields);
        }

        $row = null;

        try {
            $row = call_user_func([self::MODEL, 'query'])
                ->where('resource_id', $resourceId)
                ->where('resource_type', $type)
                ->whereIn('lang', array_unique([$lang, 'base']))
                ->orderByRaw("CASE WHEN lang = 'base' THEN 1 ELSE 0 END")
                ->first();
        } catch (Throwable) {
            $row = null;
        }

        $values = [];

        foreach ($fields as $field) {
            $values[$field] = $row === null ? '' : (string) ($row->{$field} ?? '');
        }

        return $values;
    }

    /** @param array<string, scalar> $fields only the changed ones */
    public static function write(int $resourceId, string $type, string $lang, array $fields): void
    {
        if (self::$fake !== null) {
            self::$fake->write($resourceId, $type, $lang, $fields);

            return;
        }

        call_user_func([self::FACADE, 'updateSeoFields'], [
            'resource_id' => $resourceId,
            'resource_type' => $type,
            $lang => $fields,
        ]);
    }

    public static function redirect(string $oldUrl, string $newUrl, int $type = 301, string $siteKey = 'default'): int
    {
        if (self::$fake !== null) {
            return (int) self::$fake->redirect($oldUrl, $newUrl, $type, $siteKey);
        }

        $model = call_user_func([self::REDIRECT, 'query'])->create([
            'site_key' => $siteKey,
            'old_url' => $oldUrl,
            'new_url' => $newUrl,
            'type' => $type,
        ]);

        return (int) $model->getKey();
    }

    /** Remove a redirect we created, only while it still points where we pointed it. */
    public static function deleteRedirect(int $id, string $oldUrl, string $newUrl): bool
    {
        if (self::$fake !== null) {
            return (bool) self::$fake->deleteRedirect($id, $oldUrl, $newUrl);
        }

        return (bool) call_user_func([self::REDIRECT, 'query'])
            ->whereKey($id)->where('old_url', $oldUrl)->where('new_url', $newUrl)->delete();
    }

    public static function redirectExists(string $oldUrl, string $siteKey = 'default'): bool
    {
        if (self::$fake !== null) {
            return method_exists(self::$fake, 'redirectExists') && self::$fake->redirectExists($oldUrl, $siteKey);
        }

        try {
            return call_user_func([self::REDIRECT, 'query'])->where('old_url', $oldUrl)->where('site_key', $siteKey)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public static function regenerateSitemap(int $documentId = 0): void
    {
        if (self::$fake !== null || !self::available()) {
            return;
        }

        try {
            call_user_func([self::FACADE, 'generateSitemap'], $documentId);
        } catch (Throwable) {
            // The sitemap is a nicety; a failure here must not undo a saved fix.
        }
    }

    /** @return string[] URLs from the site's sitemap.xml, read with sSeo's streaming reader when installed */
    public static function sitemapUrls(string $siteUrl, int $limit = 50000): array
    {
        $base = defined('EVO_BASE_PATH') ? rtrim((string) EVO_BASE_PATH, '/') : '';
        $local = $base !== '' && is_file($base . '/sitemap.xml') ? $base . '/sitemap.xml' : null;
        $source = $local ?? rtrim($siteUrl, '/') . '/sitemap.xml';
        $urls = [];
        $host = (string) parse_url($source, PHP_URL_HOST);

        // A host that does not resolve (a dev domain, a container's own name) has no sitemap to fetch.
        if ($local === null && ($host === '' || gethostbyname($host) === $host)) {
            return [];
        }

        try {
            if (class_exists('Seiger\sSeo\Support\Sitemaper')) {
                foreach (call_user_func(['Seiger\sSeo\Support\Sitemaper', 'eachUrl'], $source) as $url) {
                    $urls[] = (string) (is_array($url) ? ($url['loc'] ?? '') : $url);

                    if (count($urls) >= $limit) {
                        break;
                    }
                }

                return array_values(array_filter($urls));
            }

            $xml = $local !== null ? (string) @file_get_contents($local) : (string) @file_get_contents($source, false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]));

            if ($xml !== '' && preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m)) {
                $urls = array_slice(array_map(static fn ($u) => html_entity_decode($u, ENT_QUOTES | ENT_XML1), $m[1]), 0, $limit);
            }
        } catch (Throwable) {
            return [];
        }

        return $urls;
    }
}
