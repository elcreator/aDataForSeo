<?php

namespace Elcreator\aDataForSeo\Analysis;

use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Services\Writer;
use Elcreator\aDataForSeo\Support\UrlMap;

/**
 * The OnPage crawl, turned into findings mapped to documents and fixes.
 *
 * A site that answers bots with a challenge returns almost nothing; that is
 * reported as its own finding instead of a reassuring "no issues".
 */
final class Audit extends Analyser
{
    public function run(Runtime $rt, Step $step): array
    {
        $project = $rt->project;
        $map = UrlMap::for($project);
        $siteName = Writer::siteName();
        $issues = [];

        $summary = Runtime::okTasks($rt->responses('summary'))[0]['result'][0] ?? [];
        $crawled = (int) ($summary['crawl_status']['pages_crawled'] ?? 0);
        $pages = [];

        foreach (Runtime::okTasks($rt->responses('pages')) as $task) {
            foreach (\Elcreator\aDataForSeo\Api\Response::itemsOf($task) as $page) {
                $pages[] = $page;
            }
        }

        $crawled = max($crawled, count($pages));
        $blocked = $crawled <= 1;
        $siteUrl = rtrim((string) evo()->getConfig('site_url', 'https://' . $project->host()), '/') . '/';

        if ($blocked) {
            $issues[] = ['code' => 'crawl_blocked', 'severity' => 'critical', 'url' => $siteUrl, 'fix' => 'manual', 'details' => ['pages_crawled' => $crawled]];
        }

        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');

            if ($url === '') {
                continue;
            }

            $resource = $map->resolve($url);
            $doc = $resource['id'] ?? null;
            $lang = $resource['lang'] ?? 'base';
            $checks = (array) ($page['checks'] ?? []);
            $meta = (array) ($page['meta'] ?? []);
            $status = (int) ($page['status_code'] ?? 200);
            $base = ['url' => $url, 'document_id' => $doc];

            if ($status >= 400 && $status < 500) {
                $issues[] = $base + ['code' => 'broken_page', 'severity' => 'high', 'fix' => 'redirect', 'details' => ['status' => $status, 'suggest' => ['new_url' => self::nearestParent($url, $map, $siteUrl)]]];

                continue;
            }

            if ($status >= 500) {
                $issues[] = $base + ['code' => 'server_error', 'severity' => 'high', 'fix' => 'manual', 'details' => ['status' => $status]];

                continue;
            }

            $document = $doc !== null ? Writer::document($doc) : null;
            $title = trim((string) ($meta['title'] ?? ''));
            $description = trim((string) ($meta['description'] ?? ''));

            if (!empty($checks['no_title']) || $title === '') {
                $issues[] = $base + self::seo('empty_title', 'high', $doc, $lang, ['meta_title' => $document ? Writer::title($document, $siteName) : '']);
            } elseif (!empty($checks['title_too_long']) || mb_strlen($title) > 70) {
                $issues[] = $base + self::seo('title_too_long', 'low', $doc, $lang, ['meta_title' => Writer::cut($title, Writer::TITLE_MAX)], ['length' => mb_strlen($title), 'current' => $title]);
            }

            if (!empty($checks['no_description']) || $description === '') {
                $issues[] = $base + self::seo('empty_description', 'medium', $doc, $lang, ['meta_description' => $document ? Writer::description($document) : '']);
            }

            if (!empty($checks['no_h1_tag'])) {
                $issues[] = $base + ['code' => 'no_h1', 'severity' => 'low', 'fix' => 'manual', 'details' => []];
            }

            if (!empty($checks['canonical_to_redirect']) || !empty($checks['canonical_to_broken'])) {
                $issues[] = $base + self::seo('bad_canonical', 'medium', $doc, $lang, ['canonical_url' => $url], ['current' => (string) ($meta['canonical'] ?? '')]);
            }

            if (!empty($checks['is_orphan_page'])) {
                $issues[] = $base + ['code' => 'orphan_page', 'severity' => 'low', 'fix' => 'manual', 'details' => []];
            }

            if (!empty($checks['is_redirect']) && $status >= 300 && $status < 400) {
                $issues[] = $base + ['code' => 'internal_redirect', 'severity' => 'low', 'fix' => 'manual', 'details' => ['status' => $status]];
            }
        }

        foreach (['dup_title' => 'duplicate_title', 'dup_description' => 'duplicate_description'] as $stepName => $code) {
            foreach (Runtime::okTasks($rt->responses($stepName)) as $task) {
                foreach (\Elcreator\aDataForSeo\Api\Response::itemsOf($task) as $group) {
                    $shared = (string) ($group['accumulator'] ?? '');

                    foreach ((array) ($group['pages'] ?? []) as $page) {
                        $url = (string) ($page['url'] ?? '');
                        $resource = $map->resolve($url);
                        $document = $resource !== null ? Writer::document($resource['id']) : null;
                        $suggest = $code === 'duplicate_title'
                            ? ['meta_title' => $document ? Writer::title($document, $siteName) : '']
                            : ['meta_description' => $document ? Writer::description($document) : ''];

                        $issues[] = ['url' => $url, 'document_id' => $resource['id'] ?? null]
                            + self::seo($code, 'medium', $resource['id'] ?? null, $resource['lang'] ?? 'base', $suggest, ['shared' => $shared, 'pages' => count((array) $group['pages'])]);
                    }
                }
            }
        }

        foreach (Runtime::okTasks($rt->responses('non_indexable')) as $task) {
            foreach (\Elcreator\aDataForSeo\Api\Response::itemsOf($task) as $item) {
                $url = (string) ($item['url'] ?? '');
                $reason = (string) ($item['reason'] ?? '');
                $resource = $map->resolve($url);
                $issue = ['url' => $url, 'document_id' => $resource['id'] ?? null, 'code' => 'non_indexable', 'severity' => 'medium', 'fix' => 'manual', 'details' => ['reason' => $reason]];

                if ($reason === 'meta_tag' && $resource !== null) {
                    $issue = ['url' => $url, 'document_id' => $resource['id']] + self::seo('non_indexable', 'medium', $resource['id'], $resource['lang'], ['robots' => 'index,follow'], ['reason' => $reason]);
                }

                $issues[] = $issue;
            }
        }

        foreach (Runtime::okTasks($rt->responses('redirect_chains')) as $task) {
            foreach (\Elcreator\aDataForSeo\Api\Response::itemsOf($task) as $item) {
                $issues[] = [
                    'code' => !empty($item['is_redirect_loop']) ? 'redirect_loop' : 'redirect_chain',
                    'severity' => !empty($item['is_redirect_loop']) ? 'high' : 'medium',
                    'url' => (string) ($item['from_url'] ?? ''),
                    'fix' => 'manual',
                    'details' => ['to' => (string) ($item['to_url'] ?? ''), 'hops' => count((array) ($item['chain'] ?? []))],
                ];
            }
        }

        foreach (Runtime::okTasks($rt->responses('broken_links')) as $task) {
            foreach (\Elcreator\aDataForSeo\Api\Response::itemsOf($task) as $link) {
                $from = (string) ($link['link_from'] ?? '');
                $to = (string) ($link['link_to'] ?? '');
                $resource = $map->resolve($from);
                $issues[] = ['code' => 'broken_link', 'severity' => 'medium', 'url' => $from . ' → ' . $to, 'document_id' => $resource['id'] ?? null, 'fix' => 'manual', 'details' => ['from' => $from, 'to' => $to, 'status' => $link['page_to_status_code'] ?? null]];
            }
        }

        $counts = IssueBook::sync((int) $project->getKey(), (int) $rt->job->getKey(), 'audit', $issues);
        $result = ['pages_crawled' => $crawled, 'blocked' => $blocked, 'findings' => count($issues), 'by_severity' => IssueBook::counts((int) $project->getKey(), 'audit')];

        return ['output' => $result + $counts, 'result' => ['audit' => $result]];
    }

    /** A finding fixable through sSeo fields (or an instruction when the page is not a document). */
    private static function seo(string $code, string $severity, ?int $doc, string $lang, array $suggest, array $details = []): array
    {
        $suggest = array_filter($suggest, static fn ($v) => $v !== '' && $v !== null);

        return [
            'code' => $code,
            'severity' => $severity,
            'fix' => $doc !== null && $suggest !== [] ? 'seo_fields' : 'manual',
            'details' => $details + ['suggest' => $suggest, 'lang' => $lang],
        ];
    }

    /** Where a dead URL should go: the closest parent path that is a live document, else the home page. */
    public static function nearestParent(string $url, UrlMap $map, string $siteUrl): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        while ($path !== '') {
            $path = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
            $candidate = $map->resolve('/' . $path);

            if ($candidate !== null) {
                return $candidate['url'];
            }
        }

        return $siteUrl;
    }
}
