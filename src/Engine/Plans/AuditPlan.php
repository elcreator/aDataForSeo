<?php

namespace Elcreator\aDataForSeo\Engine\Plans;

use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Support\Config;

/**
 * Technical audit: one OnPage crawl (paid per page), then its reports, which
 * are free once the crawl is paid for. JavaScript rendering costs about ten
 * times as much and is used only when a professional turns it on.
 */
final class AuditPlan extends Plan
{
    public function build(Job $job, Project $project, ?Client $client, Context $context): array
    {
        $pages = (int) ($job->option('crawl_pages') ?? $project->setting('crawl_pages') ?? Config::limit('crawl_pages', 200));
        $javascript = (bool) ($job->option('javascript') ?? $project->setting('javascript', false));
        $read = static fn (string $name, string $endpoint, array $task, bool $optional = true) => [
            'name' => $name, 'kind' => 'live', 'endpoint' => $endpoint,
            'payload' => ['ref' => 'crawl', 'tasks' => [$task + ['id' => '$ref']], 'optional' => $optional],
        ];

        return [
            ['name' => 'crawl', 'kind' => 'post', 'endpoint' => 'on_page/task_post', 'payload' => ['tasks' => [[
                'target' => $project->host(),
                'max_crawl_pages' => max(1, min(10000, $pages)),
                'load_resources' => false,
                'enable_javascript' => $javascript,
                'store_raw_html' => false,
                'check_spell' => false,
                'calculate_keyword_density' => false,
            ]]]],
            ['name' => 'crawl_wait', 'kind' => 'wait', 'payload' => ['of' => 'crawl']],
            ['name' => 'summary', 'kind' => 'live', 'endpoint' => 'on_page/summary/{id}', 'payload' => ['ref' => 'crawl', 'get' => true]],
            $read('pages', 'on_page/pages', ['limit' => 1000], false),
            $read('dup_title', 'on_page/duplicate_tags', ['type' => 'duplicate_title', 'limit' => 1000]),
            $read('dup_description', 'on_page/duplicate_tags', ['type' => 'duplicate_description', 'limit' => 1000]),
            $read('non_indexable', 'on_page/non_indexable', ['limit' => 1000]),
            $read('redirect_chains', 'on_page/redirect_chains', ['limit' => 1000]),
            $read('broken_links', 'on_page/links', ['limit' => 1000, 'filters' => [['is_broken', '=', true]]]),
            ['name' => 'audit', 'kind' => 'analyse', 'payload' => ['analyser' => 'audit']],
        ];
    }
}
