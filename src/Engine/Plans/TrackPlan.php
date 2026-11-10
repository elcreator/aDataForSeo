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
 * Rank tracking: positions of each target's primary keyword (and up to
 * `tracking.secondary` more) from the standard queue, `depth` 20 by default
 * (professionals up to 100, priced accordingly). Advanced results, so SERP
 * features such as AI Overviews come along for free.
 */
final class TrackPlan extends Plan
{
    public function build(Job $job, Project $project, ?Client $client, Context $context): array
    {
        $depth = max(10, min(100, (int) ($job->option('depth') ?? Config::get('tracking.depth', 20))));
        $secondary = max(0, (int) Config::get('tracking.secondary', 2));
        $tasks = [];

        $targets = Target::query()->where('project_id', $project->getKey())->whereNotNull('primary_keyword_id')
            ->orderByDesc('score')->limit(200)->get();

        foreach ($targets as $target) {
            $market = $project->market((string) $target->country);

            if ($market === null) {
                continue;
            }

            $keywords = Keyword::query()->where('target_id', $target->getKey())->where('counted', true)
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $target->primary_keyword_id])
                ->orderByDesc('volume')->limit(1 + $secondary)->pluck('keyword')->all();

            foreach ($keywords as $keyword) {
                $tasks[] = self::serpTask((string) $keyword, (int) $market['location_code'], (string) $target->lang, $depth);
            }
        }

        foreach ((array) $job->option('keywords', []) as $extra) {
            $market = self::primaryMarket($project);
            $tasks[] = self::serpTask((string) $extra, (int) $market['location_code'], (string) $market['languages'][0], $depth);
        }

        if ($tasks === []) {
            throw WorkbenchException::invalid('error_no_targets');
        }

        return array_merge(self::serp('rank', $tasks, self::SERP_GET_ADVANCED), [
            ['name' => 'tracking', 'kind' => 'analyse', 'payload' => ['analyser' => 'tracking']],
        ]);
    }
}
