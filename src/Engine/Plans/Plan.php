<?php

namespace Elcreator\aDataForSeo\Engine\Plans;

use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Services\WorkbenchException;

/**
 * The steps of one kind of job, built without calling anything paid. A plan
 * may ask free endpoints (which languages Labs covers) through `$client`.
 */
abstract class Plan
{
    public const SERP_POST = 'serp/google/organic/task_post';
    public const SERP_GET_REGULAR = 'serp/google/organic/task_get/regular';
    public const SERP_GET_ADVANCED = 'serp/google/organic/task_get/advanced';

    private const KINDS = [
        'coverage' => CoveragePlan::class,
        'audit' => AuditPlan::class,
        'research' => ResearchPlan::class,
        'difficulty' => DifficultyPlan::class,
        'track' => TrackPlan::class,
        'competitors' => CompetitorsPlan::class,
        'custom' => CustomPlan::class,
    ];

    /**
     * @return array<int, array<string, mixed>> step definitions
     * @throws WorkbenchException when the project lacks what this job needs
     */
    abstract public function build(Job $job, Project $project, ?Client $client, Context $context): array;

    public static function for(string $kind): self
    {
        $class = self::KINDS[$kind] ?? null;

        if ($class === null) {
            throw WorkbenchException::invalid('error_kind');
        }

        return new $class();
    }

    /**
     * post → wait → fetch for SERP tasks.
     *
     * @param array<int, array<string, mixed>> $tasks
     * @return array<int, array<string, mixed>>
     */
    public static function serp(string $name, array $tasks, string $variant = self::SERP_GET_REGULAR): array
    {
        return [
            ['name' => $name, 'kind' => 'post', 'endpoint' => self::SERP_POST, 'payload' => ['tasks' => array_values($tasks)]],
            ['name' => $name . '_wait', 'kind' => 'wait', 'payload' => ['of' => $name, 'probe_after' => 5]],
            ['name' => $name . '_fetch', 'kind' => 'fetch', 'endpoint' => $variant, 'payload' => ['of' => $name]],
        ];
    }

    public static function serpTask(string $keyword, int $locationCode, string $language, int $depth = 10): array
    {
        return ['keyword' => mb_substr($keyword, 0, 700), 'location_code' => $locationCode, 'language_code' => $language, 'depth' => $depth];
    }

    /** @throws WorkbenchException */
    protected static function primaryMarket(Project $project): array
    {
        $market = $project->primaryMarket();

        if ($market === null || empty($market['location_code']) || empty($market['languages'])) {
            throw WorkbenchException::invalid('error_markets');
        }

        return $market;
    }
}
