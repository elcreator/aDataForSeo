<?php

namespace Elcreator\aDataForSeo\Engine\Plans;

use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Api\Endpoints;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Services\WorkbenchException;

/**
 * The API explorer's queued requests: any `task_post` endpoint with a raw
 * payload, fetched with its `task_get` (variant from the job, `regular` for
 * SERP by default), or any other endpoint as a live call.
 */
final class CustomPlan extends Plan
{
    public function build(Job $job, Project $project, ?Client $client, Context $context): array
    {
        $endpoint = Endpoints::normalise((string) $job->option('endpoint', ''));
        $tasks = $job->option('tasks');

        if (!preg_match('#^[a-z0-9_]+(/[a-z0-9_]+)+$#', $endpoint)) {
            throw WorkbenchException::invalid('error_endpoint');
        }

        if ($tasks !== null && (!is_array($tasks) || count($tasks) > 100)) {
            throw WorkbenchException::invalid('error_payload');
        }

        if (Endpoints::isTaskPost($endpoint)) {
            $base = Endpoints::base($endpoint);
            $variant = (string) $job->option('variant', Endpoints::family($endpoint) === 'serp' ? 'regular' : '');
            $get = $base . '/task_get' . ($variant !== '' ? '/' . preg_replace('/[^a-z_]/', '', $variant) : '');

            return [
                ['name' => 'request', 'kind' => 'post', 'endpoint' => $endpoint, 'payload' => ['tasks' => array_values((array) $tasks)]],
                ['name' => 'request_wait', 'kind' => 'wait', 'payload' => ['of' => 'request', 'probe_after' => 5]],
                ['name' => 'request_fetch', 'kind' => 'fetch', 'endpoint' => $get, 'payload' => ['of' => 'request']],
                ['name' => 'result', 'kind' => 'analyse', 'payload' => ['analyser' => 'custom', 'of' => 'request_fetch']],
            ];
        }

        return [
            ['name' => 'request', 'kind' => 'live', 'endpoint' => $endpoint, 'payload' => $tasks === null ? ['get' => true] : ['tasks' => array_values($tasks)]],
            ['name' => 'result', 'kind' => 'analyse', 'payload' => ['analyser' => 'custom', 'of' => 'request']],
        ];
    }
}
