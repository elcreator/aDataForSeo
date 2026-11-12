<?php

namespace Elcreator\aDataForSeo\Listeners;

use Elcreator\aDataForSeo\Gateway\ConnectRelay;

/**
 * GET /adataforseo/gateway-return?m=…&code=…&state=… — the browser coming back from the gateway's
 * consent page; see {@see ConnectRelay}. Front end, so no session, no database and no key.
 */
final class GatewayReturn
{
    public static function handle($params = []): void
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if (!ConnectRelay::matches($uri)) {
            return;
        }

        $target = ConnectRelay::target($_GET);

        if (!headers_sent()) {
            http_response_code($target === null ? 400 : 200);
            header('Content-Type: text/html; charset=utf-8');
            header('Referrer-Policy: no-referrer');
            header('Cache-Control: no-store');
        }

        echo $target === null ? '<!DOCTYPE html><title>aDataForSeo</title><p>Bad request.</p>' : ConnectRelay::page($target);

        exit;
    }
}
