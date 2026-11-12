<?php

namespace Elcreator\aDataForSeo\Gateway;

/**
 * The public stop between the gateway's consent page and the manager callback.
 *
 * The manager refuses a request whose Referer is another site (`validate_referer`), and the
 * browser coming back from the gateway names the gateway as its Referer, so a callback in the
 * manager is blocked ("the browser did not confirm it came from this site") before the plugin
 * sees it. This page is on the front end, where nothing checks, and does nothing but forward
 * `code` and `state` to the manager callback with `no-referrer`, so the manager sees a plain
 * same-site navigation. It reads no session and no key and spends nothing: the callback still
 * proves the state and trades the code.
 *
 * The target is a path on this host, never a URL from the query, so the page cannot send anyone
 * elsewhere.
 */
final class ConnectRelay
{
    public const PATH = '/adataforseo/gateway-return';

    /** What the gateway may send back; anything else is dropped. */
    private const FORWARDED = ['code', 'state', 'error', 'error_description'];

    /** The `redirect_uri` handed to the gateway: this site, the relay, and where it forwards to. */
    public static function redirectUri(string $siteOrigin, string $managerCallbackUrl): string
    {
        $path = (string) parse_url($managerCallbackUrl, PHP_URL_PATH);

        return rtrim($siteOrigin, '/') . self::PATH . '?' . http_build_query(['m' => $path], '', '&', PHP_QUERY_RFC3986);
    }

    /** True when the request path is the relay's. */
    public static function matches(string $requestUri): bool
    {
        return str_ends_with(rtrim((string) parse_url($requestUri, PHP_URL_PATH), '/'), self::PATH);
    }

    /** @param array<string, mixed> $query */
    public static function target(array $query): ?string
    {
        $path = $query['m'] ?? null;

        if (!is_string($path) || !preg_match('#^/[A-Za-z0-9._~/-]{1,200}/gateway/connect/callback$#', $path) || str_contains($path, '..') || str_contains($path, '//')) {
            return null;
        }

        $forward = [];

        foreach (self::FORWARDED as $name) {
            if (is_string($query[$name] ?? null)) {
                $forward[$name] = mb_substr($query[$name], 0, 300);
            }
        }

        return $path . ($forward === [] ? '' : '?' . http_build_query($forward, '', '&', PHP_QUERY_RFC3986));
    }

    /** A page that forwards at once and says nothing else; the header and the meta tag both ask for no Referer. */
    public static function page(string $target): string
    {
        $safe = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer">'
            . '<meta http-equiv="refresh" content="0;url=' . $safe . '"><title>aDataForSeo</title></head>'
            . '<body><p><a href="' . $safe . '" referrerpolicy="no-referrer" rel="noreferrer">&rarr;</a></p>'
            . '<script>location.replace(' . json_encode($target, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . ');</script></body></html>';
    }
}
