<?php

namespace Elcreator\aDataForSeo\Analysis\Gateway;

use Elcreator\aDataForSeo\Analysis\Audit;
use Elcreator\aDataForSeo\Analysis\IssueBook;
use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Services\Writer;
use Elcreator\aDataForSeo\Support\Lang;
use Elcreator\aDataForSeo\Support\UrlMap;

/**
 * The gateway's audit (`issues [{code, severity, url, details}]`) turned into the same
 * findings, cards and fixes as the DataForSEO crawl.
 *
 * The gateway's codes are its own, stable ones; most map one to one onto this plugin's
 * ({@see self::CODES}). The gateway cannot know this site's documents, so the mapping to a
 * document, the suggested title or description and the redirect target are made here, from
 * the site itself, exactly as {@see Audit} does. A code with no counterpart is not an error:
 * its pages are collected into one generic "other findings" card with a manual instruction.
 */
final class AuditResult extends Analyser
{
    /** Gateway code => this plugin's code. */
    public const CODES = [
        'broken_page' => 'broken_page',
        'server_error' => 'server_error',
        'internal_redirect' => 'internal_redirect',
        'title_missing' => 'empty_title',
        'title_too_long' => 'title_too_long',
        'description_missing' => 'empty_description',
        'h1_missing' => 'no_h1',
        'canonical_broken' => 'bad_canonical',
        'canonical_foreign' => 'bad_canonical',
        'orphan_page' => 'orphan_page',
        'title_duplicate' => 'duplicate_title',
        'description_duplicate' => 'duplicate_description',
        'noindex' => 'non_indexable',
        'robots_blocked' => 'non_indexable',
        'redirect_chain' => 'redirect_chain',
        'redirect_loop' => 'redirect_loop',
        'broken_link' => 'broken_link',
    ];

    public const OTHER = 'audit_other';

    public function run(Runtime $rt, Step $step): array
    {
        $answer = $rt->gatewayResult((string) $step->param('wait', 'audit_wait'));

        if ($answer === null) {
            throw new \RuntimeException(Lang::get('aDataForSeo::global.error_gateway_expired'));
        }

        $project = $rt->project;
        $map = UrlMap::for($project);
        $siteName = Writer::siteName();
        $siteUrl = rtrim((string) evo()->getConfig('site_url', 'https://' . $project->host()), '/') . '/';
        $crawled = (int) ($answer['pagesCrawled'] ?? 0);
        $blocked = !empty($answer['blocked']);
        $issues = [];
        $other = [];

        if ($blocked) {
            $issues[] = ['code' => 'crawl_blocked', 'severity' => 'critical', 'url' => $siteUrl, 'fix' => 'manual', 'details' => ['pages_crawled' => $crawled]];
        }

        foreach ((array) ($answer['issues'] ?? []) as $found) {
            if (!is_array($found) || (string) ($found['url'] ?? '') === '') {
                continue;
            }

            $gatewayCode = (string) ($found['code'] ?? '');
            $code = self::CODES[$gatewayCode] ?? null;
            $url = (string) $found['url'];
            $details = (array) ($found['details'] ?? []);

            if ($code === null) {
                $other[$url][] = $gatewayCode;

                continue;
            }

            $issues[] = $this->issue($code, $url, $details, (string) ($found['severity'] ?? ''), $map, $siteName, $siteUrl);
        }

        foreach ($other as $url => $codes) {
            $resource = $map->resolve((string) $url);
            $issues[] = ['code' => self::OTHER, 'severity' => 'low', 'url' => (string) $url, 'document_id' => $resource['id'] ?? null, 'fix' => 'manual', 'details' => ['gateway_codes' => array_values(array_unique($codes))]];
        }

        $counts = IssueBook::sync((int) $project->getKey(), (int) $rt->job->getKey(), 'audit', $issues);
        $result = ['pages_crawled' => $crawled, 'blocked' => $blocked, 'findings' => count($issues), 'by_severity' => IssueBook::counts((int) $project->getKey(), 'audit'), 'source' => 'gateway'];

        return ['output' => $result + $counts, 'result' => ['audit' => $result]];
    }

    /** One finding mapped to a document, with the fix this plugin can make for it. */
    private function issue(string $code, string $url, array $details, string $given, UrlMap $map, string $siteName, string $siteUrl): array
    {
        // The gateway's own severity is the better judge of what it found; the table covers an answer without one.
        $severity = isset(IssueBook::SEVERITIES[$given]) ? $given : ['broken_page' => 'high', 'server_error' => 'high', 'empty_title' => 'high', 'redirect_loop' => 'high', 'empty_description' => 'medium', 'title_too_long' => 'low', 'no_h1' => 'low', 'orphan_page' => 'low', 'internal_redirect' => 'low'][$code] ?? 'medium';


        if ($code === 'broken_link') {
            $to = (string) ($details['to'] ?? '');
            $resource = $map->resolve($url);

            return ['code' => $code, 'severity' => $severity, 'url' => $url . ' → ' . $to, 'document_id' => $resource['id'] ?? null, 'fix' => 'manual', 'details' => ['from' => $url, 'to' => $to, 'status' => $details['status'] ?? null]];
        }

        $resource = $map->resolve($url);
        $doc = $resource['id'] ?? null;
        $lang = $resource['lang'] ?? 'base';
        $base = ['url' => $url, 'document_id' => $doc];
        $document = $doc !== null ? Writer::document($doc) : null;

        return $base + match ($code) {
            'broken_page' => ['code' => $code, 'severity' => $severity, 'fix' => 'redirect', 'details' => ['status' => $details['status'] ?? null, 'suggest' => ['new_url' => Audit::nearestParent($url, $map, $siteUrl)]]],
            'empty_title', 'title_too_long', 'duplicate_title' => Audit::seo($code, $severity, $doc, $lang, ['meta_title' => $document ? Writer::title($document, $siteName) : ''], array_intersect_key($details, ['length' => 1, 'pages' => 1])),
            'empty_description', 'duplicate_description' => Audit::seo($code, $severity, $doc, $lang, ['meta_description' => $document ? Writer::description($document) : ''], array_intersect_key($details, ['pages' => 1])),
            'bad_canonical' => Audit::seo($code, $severity, $doc, $lang, ['canonical_url' => $url]),
            // Only a "noindex" the page itself carries can be lifted through its SEO fields; a robots.txt rule is manual.
            'non_indexable' => str_contains(strtolower((string) ($details['reason'] ?? '')), 'meta') && $doc !== null
                ? Audit::seo($code, $severity, $doc, $lang, ['robots' => 'index,follow'], ['reason' => (string) $details['reason']])
                : ['code' => $code, 'severity' => $severity, 'fix' => 'manual', 'details' => ['reason' => (string) ($details['reason'] ?? '')]],
            default => ['code' => $code, 'severity' => $severity, 'fix' => 'manual', 'details' => array_intersect_key($details, ['status' => 1, 'to' => 1, 'hops' => 1])],
        };
    }
}
