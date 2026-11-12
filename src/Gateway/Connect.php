<?php

namespace Elcreator\aDataForSeo\Gateway;

use Elcreator\aDataForSeo\Api\ApiException;
use Elcreator\aDataForSeo\Api\CurlTransport;
use Elcreator\aDataForSeo\Api\Transport;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\Lang;

/**
 * "Connect a site" on the gateway: an API key without anybody copying one.
 *
 * OAuth2 authorisation code with PKCE (S256 only), as aIMage does it. The manager's browser
 * goes to `{gateway}/connect?app&site&redirect_uri&state&code_challenge`, the signed-in
 * owner presses Allow (with a spending cap), and the browser comes back with `?code&state`.
 * The code is traded for a key here, server to server, with the verifier only this side
 * knows, so a code intercepted on the way back is useless. The key never passes through
 * the browser.
 *
 * The gateway insists that `redirect_uri` is on the same host as `site`, that `site` is
 * https (http only on a loopback host), and that a code lives five minutes and is used once.
 */
final class Connect
{
    private readonly Transport $transport;

    public function __construct(?Transport $transport = null, private readonly ?string $siteUrl = null)
    {
        $this->transport = $transport ?? new CurlTransport();
    }

    public static function app(): string
    {
        $app = strtolower(trim((string) Config::get('gateway.connect.app', 'adataforseo')));

        return preg_match('/^[a-z0-9][a-z0-9-]{1,31}$/', $app) ? $app : 'adataforseo';
    }

    public static function pendingTtl(): int
    {
        return max(60, (int) Config::get('gateway.connect.pending_ttl', 900));
    }

    /** @return array{verifier: string, challenge: string, state: string} */
    public static function pkce(): array
    {
        $verifier = self::base64url(random_bytes(48));

        return ['verifier' => $verifier, 'challenge' => self::challenge($verifier), 'state' => self::base64url(random_bytes(24))];
    }

    /** RFC 7636 S256. */
    public static function challenge(string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    public static function authorizeUrl(string $site, string $redirectUri, string $state, string $challenge, ?string $gatewayUrl = null): string
    {
        return rtrim($gatewayUrl ?? Config::gatewayUrl(), '/') . '/connect?' . http_build_query([
            'app' => self::app(),
            'site' => $site,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** This site's origin as the gateway wants it: scheme and host, no path. */
    public static function siteOrigin(): string
    {
        $url = (string) evo()->getConfig('site_url', '');
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return rtrim($url, '/');
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Trade the code for a key.
     *
     * @return array{access_token: string, token_type?: string, key?: array}
     * @throws ApiException `invalid_grant` for an expired, used or mismatched code
     */
    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        [$status, $raw] = $this->transport->send(
            'POST',
            rtrim($this->siteUrl ?? Config::gatewayUrl(), '/') . '/connect/token',
            (string) json_encode(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirectUri]),
            ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'User-Agent' => 'aDataForSeo/1.0 (Evolution CMS)'],
            (int) Config::get('gateway.timeout', 60),
            (int) Config::get('gateway.connect_timeout', 10)
        );

        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : [];

        if ($status >= 400 || !is_string($body['access_token'] ?? null) || $body['access_token'] === '') {
            // The gateway's own sentence about the code ("expired", "already used") is plain text; never the key.
            $detail = mb_substr(is_string($body['error_description'] ?? null) ? $body['error_description'] : 'HTTP ' . $status, 0, 200);

            throw new ApiException(Lang::get('aDataForSeo::global.error_gateway_connect', ['detail' => $detail]), is_string($body['error'] ?? null) ? $body['error'] : 'connect_failed', false, $status);
        }

        return $body;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
