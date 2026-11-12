<?php

namespace Elcreator\aDataForSeo\Analysis\Gateway;

use Elcreator\aDataForSeo\Analysis\IndexCoverage;
use Elcreator\aDataForSeo\Analysis\Serp;
use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Support\Lang;
use Elcreator\aDataForSeo\Support\UrlMap;

/**
 * The gateway's index-coverage answer (`urls`: the site's own pages Google shows) turned
 * into the same findings as the DataForSEO analysis. The comparison with the sitemap is
 * made here, against the sitemap this site itself reads (sSeo's, or its documents): the
 * plugin can map every address to a document, the gateway cannot, and a site the gateway
 * cannot reach (a development machine) still gets its sitemap read.
 */
final class CoverageResult extends Analyser
{
    public function run(Runtime $rt, Step $step): array
    {
        $answer = $rt->gatewayResult((string) $step->param('wait', 'cov_wait'));

        if ($answer === null) {
            throw new \RuntimeException(Lang::get('aDataForSeo::global.error_gateway_expired'));
        }

        $indexed = [];

        foreach ((array) ($answer['urls'] ?? []) as $url) {
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

            if (is_string($url) && is_string($host) && Serp::sameSite(strtolower($host), $rt->project->host())) {
                $indexed[UrlMap::normalise($url)] = $url;
            }
        }

        return IndexCoverage::conclude($rt, $indexed, array_filter([
            'source' => 'gateway',
            'note' => isset($answer['note']) ? mb_substr((string) $answer['note'], 0, 300) : null,
            'data_fetched_at' => $answer['fetchedAt'] ?? null,
        ], static fn ($v) => $v !== null));
    }
}
