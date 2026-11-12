<?php

namespace Elcreator\aDataForSeo\Engine\Plans;

use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\WorkbenchException;
use Elcreator\aDataForSeo\Support\Config;

/**
 * Difficulty from the SERP itself (Labs `keyword_difficulty` is unusable for
 * priority): the top 10 of each target's primary keyword from the standard
 * queue, then the traffic of the hosts found there (Labs bulk traffic, up to
 * 1000 per call), then the score. Only targets above
 * `research.serp_check_min_demand` are checked.
 */
final class DifficultyPlan extends Plan
{
    public const TRAFFIC = 'dataforseo_labs/google/bulk_traffic_estimation/live';

    public function build(Job $job, Project $project, ?Client $client, Context $context): array
    {
        $targets = self::targets($project, (bool) $job->option('recheck', false));

        if ($targets === []) {
            throw WorkbenchException::invalid('error_no_targets');
        }

        $tasks = [];
        $markets = [];

        foreach ($targets as $target) {
            $market = $project->market((string) $target->country);
            $keyword = Keyword::query()->find($target->primary_keyword_id);

            if ($market === null || $keyword === null) {
                continue;
            }

            $tasks[] = self::serpTask((string) $keyword->keyword, (int) $market['location_code'], (string) $target->lang, 10);
            $markets[(int) $market['location_code'] . ':' . (string) $market['languages'][0]] = true;
        }

        if ($tasks === []) {
            throw WorkbenchException::invalid('error_no_targets');
        }

        return array_merge(self::serp('serp', $tasks), [
            ['name' => 'hosts', 'kind' => 'analyse', 'payload' => ['analyser' => 'difficulty_hosts']],
            // Filled by `hosts`: one task per market, its hosts as targets.
            ['name' => 'traffic', 'kind' => 'live', 'endpoint' => self::TRAFFIC, 'payload' => [
                'deferred' => true, 'estimate_tasks' => count($markets), 'expect_items' => min(1000, count($tasks) * 10), 'optional' => true,
            ]],
            ['name' => 'difficulty', 'kind' => 'analyse', 'payload' => ['analyser' => 'difficulty']],
            ['name' => 'priority', 'kind' => 'analyse', 'payload' => ['analyser' => 'priority']],
        ]);
    }

    /** @return Target[] */
    public static function targets(Project $project, bool $recheck, bool $demandFilter = true): array
    {
        return Target::query()
            ->where('project_id', $project->getKey())
            ->whereNotNull('primary_keyword_id')
            ->when($demandFilter, static fn ($q) => $q->where('demand_real', '>=', (int) Config::get('research.serp_check_min_demand', 50)))
            ->when(!$recheck, static fn ($q) => $q->whereNull('kd_serp'))
            ->orderByDesc('demand_real')
            ->orderBy('id')
            ->limit(max(1, (int) Config::limit('serp_checks', 60)))
            ->get()->all();
    }
}
