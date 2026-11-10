<?php

namespace Elcreator\aDataForSeo\Engine\Plans;

use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Keyword;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Target;
use Elcreator\aDataForSeo\Services\WorkbenchException;

/**
 * Who wins this site's queries, and which of their pages it lacks: Labs
 * `serp_competitors` over the primary keywords, then `relevant_pages` of the
 * top three, turned into "pages they have that you don't" cards.
 */
final class CompetitorsPlan extends Plan
{
    public const SERP_COMPETITORS = 'dataforseo_labs/google/serp_competitors/live';
    public const RELEVANT_PAGES = 'dataforseo_labs/google/relevant_pages/live';

    public function build(Job $job, Project $project, ?Client $client, Context $context): array
    {
        $market = self::primaryMarket($project);
        $lang = (string) $market['languages'][0];

        $keywords = Keyword::query()
            ->whereIn('id', Target::query()->where('project_id', $project->getKey())->whereNotNull('primary_keyword_id')->select('primary_keyword_id'))
            ->where('lang', $lang)
            ->orderByDesc('volume')->limit(200)->pluck('keyword')->all();

        if ($keywords === []) {
            throw WorkbenchException::invalid('error_no_targets');
        }

        return [
            ['name' => 'serp_competitors', 'kind' => 'live', 'endpoint' => self::SERP_COMPETITORS, 'payload' => [
                'tasks' => [['keywords' => $keywords, 'location_code' => (int) $market['location_code'], 'language_code' => $lang, 'limit' => 20]],
                'expect_items' => 20,
            ]],
            ['name' => 'pick', 'kind' => 'analyse', 'payload' => ['analyser' => 'competitors_pick']],
            ['name' => 'relevant_pages', 'kind' => 'live', 'endpoint' => self::RELEVANT_PAGES, 'payload' => [
                'deferred' => true, 'estimate_tasks' => 3, 'expect_items' => 50, 'optional' => true,
            ]],
            ['name' => 'competitors', 'kind' => 'analyse', 'payload' => ['analyser' => 'competitors']],
        ];
    }
}
