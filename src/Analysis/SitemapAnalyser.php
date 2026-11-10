<?php

namespace Elcreator\aDataForSeo\Analysis;

use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Integrations\SSeo;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Support\UrlMap;

/**
 * The pages the site wants indexed: its sitemap (sSeo's streaming reader when
 * installed, else /sitemap.xml), or, without one, its published documents.
 */
final class SitemapAnalyser extends Analyser
{
    public const LIMIT = 5000;

    public function run(Runtime $rt, Step $step): array
    {
        $site = rtrim((string) evo()->getConfig('site_url', 'https://' . $rt->project->host() . '/'), '/');
        $urls = SSeo::sitemapUrls($site, self::LIMIT);
        $source = 'sitemap';

        if ($urls === []) {
            $urls = array_map(static fn ($e) => $e['url'], UrlMap::for($rt->project)->urls());
            $source = 'documents';
        }

        $urls = array_slice(array_values(array_unique(array_filter($urls, static fn ($u) => is_string($u) && $u !== ''))), 0, self::LIMIT);

        return ['output' => ['source' => $source, 'urls' => $urls, 'count' => count($urls)]];
    }
}
