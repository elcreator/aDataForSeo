<?php

namespace Elcreator\aDataForSeo\Engine\Plans;

use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;

/**
 * Index coverage: the sitemap against what Google returns for `site:`.
 * The cheapest health check there is, and the beginner's first card.
 * The coverage analyser adds another page of results while they continue,
 * up to `limits.coverage_pages`.
 */
final class CoveragePlan extends Plan
{
    public const DEPTH = 100;

    public function build(Job $job, Project $project, ?Client $client, Context $context): array
    {
        $market = self::primaryMarket($project);

        return array_merge(
            [['name' => 'sitemap', 'kind' => 'analyse', 'payload' => ['analyser' => 'sitemap']]],
            self::serp('site1', [self::serpTask('site:' . $project->host(), (int) $market['location_code'], (string) $market['languages'][0], self::DEPTH)]),
            [['name' => 'coverage', 'kind' => 'analyse', 'payload' => ['analyser' => 'coverage', 'page' => 1]]]
        );
    }
}
