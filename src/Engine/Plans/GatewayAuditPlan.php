<?php

namespace Elcreator\aDataForSeo\Engine\Plans;

use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Support\Config;

/**
 * The technical audit through the gateway: one crawl, one call, issues with stable codes.
 * JavaScript rendering is about ten times the price per page and, as ever, only on request.
 */
final class GatewayAuditPlan extends Plan
{
    public function build(Job $job, Project $project, $client, Context $context): array
    {
        $pages = (int) ($job->option('crawl_pages') ?? $project->setting('crawl_pages') ?? Config::limit('crawl_pages', 200));
        $body = ['site' => $project->host(), 'maxPages' => max(1, min(5000, $pages))];

        if ((bool) ($job->option('javascript') ?? $project->setting('javascript', false))) {
            $body['javascript'] = true;
        }

        return array_merge(
            self::gateway('audit_run', 'audit', $body),
            [['name' => 'audit', 'kind' => 'analyse', 'payload' => ['analyser' => 'gw_audit', 'wait' => 'audit_run_wait']]]
        );
    }
}
