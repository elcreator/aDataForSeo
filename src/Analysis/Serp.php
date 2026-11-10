<?php

namespace Elcreator\aDataForSeo\Analysis;

use Elcreator\aDataForSeo\Api\Response;

/**
 * Reading organic results.
 *
 * Measured, not assumed: in `organic/live/regular` and `task_get/regular`
 * (November 2026) `url` and `domain` can be `google.com/goto?url=...`
 * wrappers. The real address is then the wrapper's own parameter, or the host
 * at the start of `breadcrumb` ("https://www.sreality.cz › hledani › byty").
 * Results with no host and a follower count in the breadcrumb are social
 * posts and groups.
 */
final class Serp
{
    public const UGC_HOSTS = [
        'facebook.com', 'm.facebook.com', 'instagram.com', 't.me', 'telegram.me', 'telegram.org', 'youtube.com', 'm.youtube.com',
        'reddit.com', 'tiktok.com', 'x.com', 'twitter.com', 'vk.com', 'pinterest.com', 'linkedin.com', 'quora.com', 'ok.ru',
    ];

    private const FOLLOWERS = '/\d[\d\s.,]*\s*[kmкм]?\+?\s*(followers|members|subscribers|sledující|členů|obserwując|członków|подписчик|участник|учасник|підписник)/iu';

    /**
     * @return array<int, array{position: int, url: ?string, host: ?string, title: string, breadcrumb: string, ugc: bool}>
     */
    public static function organic(?array $task): array
    {
        $out = [];

        foreach (Response::itemsOf($task) as $item) {
            if (($item['type'] ?? 'organic') !== 'organic') {
                continue;
            }

            $url = self::realUrl($item);
            $host = $url !== null ? self::hostOf($url) : self::hostFromBreadcrumb((string) ($item['breadcrumb'] ?? ''));
            $breadcrumb = (string) ($item['breadcrumb'] ?? '');

            $out[] = [
                'position' => (int) ($item['rank_group'] ?? $item['rank_absolute'] ?? count($out) + 1),
                'url' => $url,
                'host' => $host,
                'title' => (string) ($item['title'] ?? ''),
                'breadcrumb' => $breadcrumb,
                'ugc' => self::isUgc($host, $breadcrumb),
            ];
        }

        usort($out, static fn ($a, $b) => $a['position'] <=> $b['position']);

        return $out;
    }

    /** Every item type on the page other than plain organic (ai_overview, local_pack ...). */
    public static function features(?array $task): array
    {
        $types = [];

        foreach (Response::itemsOf($task) as $item) {
            $type = (string) ($item['type'] ?? '');

            if ($type !== '' && $type !== 'organic') {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    /** The address behind a result: its own URL, the wrapper's parameter, or rebuilt from the breadcrumb. */
    public static function realUrl(array $item): ?string
    {
        foreach (['url', 'domain'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            $unwrapped = self::unwrap($value);

            if ($unwrapped !== null) {
                return $unwrapped;
            }

            if (!self::isGoogle($value) && preg_match('#^https?://#i', $value)) {
                return $value;
            }
        }

        $breadcrumb = trim((string) ($item['breadcrumb'] ?? ''));
        $host = self::hostFromBreadcrumb($breadcrumb);

        if ($host === null) {
            return null;
        }

        $parts = array_map('trim', preg_split('/\s*[›>]\s*/u', $breadcrumb) ?: []);
        array_shift($parts);
        $path = implode('/', array_filter($parts, static fn ($p) => $p !== '' && $p !== '...' && $p !== '…'));

        return 'https://' . $host . ($path !== '' ? '/' . $path : '/');
    }

    public static function hostOf(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === '' ? null : (string) preg_replace('/^www\./', '', $host);
    }

    public static function hostFromBreadcrumb(string $breadcrumb): ?string
    {
        if (preg_match('#^\s*(?:https?://)?([a-z0-9][a-z0-9.-]*\.[a-z]{2,})(?=$|[\s/›>])#iu', $breadcrumb, $m)) {
            return (string) preg_replace('/^www\./', '', strtolower($m[1]));
        }

        return null;
    }

    public static function isUgc(?string $host, string $breadcrumb = ''): bool
    {
        if ($host === null) {
            return (bool) preg_match(self::FOLLOWERS, $breadcrumb);
        }

        foreach (self::UGC_HOSTS as $ugc) {
            if ($host === $ugc || str_ends_with($host, '.' . $ugc)) {
                return true;
            }
        }

        return false;
    }

    public static function sameSite(?string $host, string $projectHost): bool
    {
        if ($host === null) {
            return false;
        }

        $projectHost = (string) preg_replace('/^www\./', '', strtolower($projectHost));

        return $host === $projectHost || str_ends_with($host, '.' . $projectHost);
    }

    private static function isGoogle(string $value): bool
    {
        return (bool) preg_match('#^(https?://)?([a-z0-9-]+\.)*google\.[a-z.]+(/|$)#i', $value);
    }

    private static function unwrap(string $value): ?string
    {
        if (!self::isGoogle($value)) {
            return null;
        }

        parse_str((string) parse_url(preg_match('#^https?://#i', $value) ? $value : 'https://' . $value, PHP_URL_QUERY), $query);

        foreach (['url', 'q', 'u'] as $key) {
            $candidate = (string) ($query[$key] ?? '');

            if (preg_match('#^https?://#i', $candidate) && !self::isGoogle($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
