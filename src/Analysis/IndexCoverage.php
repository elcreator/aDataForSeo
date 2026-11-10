<?php

namespace Elcreator\aDataForSeo\Analysis;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Plans\CoveragePlan;
use Elcreator\aDataForSeo\Engine\Plans\Plan;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Integrations\SSeo;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\UrlMap;

/**
 * Pages in the sitemap vs pages Google returns for `site:`.
 *
 * On the reference site this was the most important finding of all: 0 of 65
 * landing pages indexed, the 7 indexed URLs all search pages. Findings: pages
 * not indexed (each mapped to its document), indexed URLs missing from the
 * sitemap (parameters, search pages — noindex candidates), no sitemap,
 * nothing indexed at all.
 */
final class IndexCoverage extends Analyser
{
    public function run(Runtime $rt, Step $step): array
    {
        $project = $rt->project;
        $page = (int) $step->param('page', 1);
        $items = 0;
        $indexed = [];

        foreach ($rt->stepsLike('site') as $fetch) {
            if ($fetch->kind !== 'fetch') {
                continue;
            }

            // Each deeper page repeats the shallower ones; only the latest says whether results continue.
            $latest = $fetch->name === 'site' . $page . '_fetch';

            foreach (Runtime::okTasks(Runtime::responsesOf($fetch)) as $task) {
                foreach (Serp::organic($task) as $result) {
                    $items += $latest ? 1 : 0;

                    if ($result['url'] !== null && Serp::sameSite($result['host'], $project->host())) {
                        $indexed[UrlMap::normalise($result['url'])] = $result['url'];
                    }
                }
            }
        }

        // Results fill the page: there may be more. Ask for a deeper page, within the cap.
        if ($items >= $page * CoveragePlan::DEPTH && $page < (int) Config::limit('coverage_pages', 3)) {
            $market = $project->primaryMarket();
            $next = $page + 1;

            return [
                'output' => ['page' => $page, 'items' => $items, 'continued' => true],
                'extend' => array_merge(
                    Plan::serp('site' . $next, [Plan::serpTask('site:' . $project->host(), (int) $market['location_code'], (string) $market['languages'][0], $next * CoveragePlan::DEPTH)]),
                    [['name' => 'coverage' . $next, 'kind' => 'analyse', 'payload' => ['analyser' => 'coverage', 'page' => $next]]]
                ),
            ];
        }

        $sitemapStep = $rt->step('sitemap');
        $sitemap = [];

        foreach ((array) ($sitemapStep?->output['summary']['urls'] ?? []) as $url) {
            $sitemap[UrlMap::normalise((string) $url)] = (string) $url;
        }

        $source = (string) ($sitemapStep?->output['summary']['source'] ?? 'sitemap');
        $map = UrlMap::for($project);
        $issues = [];
        $siteUrl = rtrim((string) evo()->getConfig('site_url', 'https://' . $project->host()), '/') . '/';

        if ($source !== 'sitemap') {
            $issues[] = ['code' => 'sitemap_missing', 'severity' => 'high', 'url' => $siteUrl . 'sitemap.xml', 'fix' => 'manual', 'details' => ['documents' => count($sitemap)]];
        }

        if ($indexed === []) {
            $issues[] = ['code' => 'nothing_indexed', 'severity' => 'critical', 'url' => $siteUrl, 'fix' => 'manual', 'details' => ['sitemap' => count($sitemap)]];
        }

        $notIndexed = array_diff_key($sitemap, $indexed);
        $extra = array_diff_key($indexed, $sitemap);

        foreach ($notIndexed as $url) {
            $resource = $map->resolve($url);
            $fix = 'manual';
            $details = ['reason' => 'not_in_site_results'];

            if ($resource !== null && SSeo::available()) {
                $robots = (string) (SSeo::read($resource['id'], $resource['type'], $resource['lang'], ['robots'])['robots'] ?? '');

                if (str_contains(strtolower($robots), 'noindex')) {
                    $fix = 'seo_fields';
                    $details = ['reason' => 'noindex', 'suggest' => ['robots' => 'index,follow']];
                }
            }

            $issues[] = ['code' => 'not_indexed', 'severity' => 'high', 'url' => $url, 'document_id' => $resource['id'] ?? null, 'fix' => $fix, 'details' => $details + ['lang' => $resource['lang'] ?? null]];
        }

        foreach ($extra as $url) {
            $hasQuery = str_contains($url, '?');
            $base = $hasQuery ? strtok($url, '?') : $url;
            $resource = $map->resolve((string) $base);
            $issue = ['code' => 'indexed_not_in_sitemap', 'severity' => $hasQuery ? 'medium' : 'low', 'url' => $url, 'document_id' => $resource['id'] ?? null, 'fix' => 'manual', 'details' => ['parameters' => $hasQuery]];

            if ($hasQuery && $resource !== null) {
                $issue['fix'] = 'seo_fields';
                $issue['details']['suggest'] = ['robots' => 'noindex,follow'];
                $issue['details']['lang'] = $resource['lang'];
            }

            $issues[] = $issue;
        }

        $counts = IssueBook::sync((int) $project->getKey(), (int) $rt->job->getKey(), 'coverage', $issues);

        $this->markTargets($rt, $notIndexed);

        $summary = [
            'sitemap' => count($sitemap),
            'sitemap_source' => $source,
            'indexed' => count($indexed),
            'indexed_in_sitemap' => count($sitemap) - count($notIndexed),
            'not_indexed' => count($notIndexed),
            'extra' => count($extra),
            'checked_at' => Carbon::now()->toIso8601String(),
        ];

        return ['output' => $summary + $counts, 'result' => ['coverage' => $summary]];
    }

    /** Targets whose page is in the sitemap but not in Google are `not_indexed`, not `exists`. */
    private function markTargets(Runtime $rt, array $notIndexed): void
    {
        $paths = array_keys($notIndexed);

        Target::query()->where('project_id', $rt->project->getKey())->whereNotNull('url')->get()
            ->each(static function (Target $target) use ($paths) {
                if ($target->document_id === null) {
                    return;
                }

                $status = in_array(UrlMap::normalise((string) $target->url), $paths, true) ? Target::NOT_INDEXED : Target::EXISTS;

                if (in_array($target->status, [Target::EXISTS, Target::NOT_INDEXED], true) && $target->status !== $status) {
                    $target->forceFill(['status' => $status])->save();
                }
            });
    }
}
