<?php

namespace Elcreator\aDataForSeo\Engine\Plans;

use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Support\Config;

/**
 * Index coverage through the gateway: the sitemap is read here as ever, one gateway call
 * lists the site's own pages Google shows, and the analysis compares the two. No `$client`:
 * the gateway client belongs to the slice that runs the steps, never to the plan.
 */
final class GatewayCoveragePlan extends Plan
{
    public function build(Job $job, Project $project, $client, Context $context): array
    {
        // `site:` results come in pages of 100; the gateway caps the pages it will fetch at 10.
        $pages = max(1, min(10, (int) Config::limit('coverage_pages', 3)));

        return array_merge(
            [['name' => 'sitemap', 'kind' => 'analyse', 'payload' => ['analyser' => 'sitemap']]],
            self::gateway('cov', 'index-coverage', ['domain' => $project->host(), 'maxPages' => $pages]),
            [['name' => 'coverage', 'kind' => 'analyse', 'payload' => ['analyser' => 'gw_coverage', 'wait' => 'cov_wait']]]
        );
    }
}
