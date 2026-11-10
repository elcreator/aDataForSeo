<?php

namespace Elcreator\aDataForSeo\Engine;

use Elcreator\aDataForSeo\Api\Cache;
use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\Context;
use Elcreator\aDataForSeo\Api\Response;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Step;
use RuntimeException;

/**
 * Everything a step needs while it runs: the job, its project, the client
 * billed to the job's manager, the money context and the clock of the slice.
 *
 * Responses a step fetched are kept per job (`jobs/<uuid>/...json.gz`), apart
 * from the cache, so an analyser reads exactly what its job paid for even
 * after the cache has expired.
 */
final class Runtime
{
    private float $deadline;

    public function __construct(
        public readonly Job $job,
        public readonly Project $project,
        private readonly ?Client $client,
        public readonly Context $context,
        float $budgetSeconds = 45.0,
        private readonly ?\Closure $report = null
    ) {
        $this->deadline = microtime(true) + max(0.0, $budgetSeconds);
    }

    public function client(): Client
    {
        if ($this->client === null) {
            throw new RuntimeException('This job has no DataForSEO key to call with.');
        }

        return $this->client;
    }

    public function hasClient(): bool
    {
        return $this->client !== null;
    }

    public function contextFor(Step $step): Context
    {
        return $this->context->withStep((int) $step->getKey());
    }

    public function timeLeft(): float
    {
        return $this->deadline - microtime(true);
    }

    public function outOfTime(): bool
    {
        return $this->timeLeft() <= 0;
    }

    public function report(string $phase, int $progress, string $message): void
    {
        if ($this->report !== null) {
            ($this->report)($phase, $progress, $message);
        }
    }

    public function step(string $name): ?Step
    {
        return Step::query()->where('job_id', $this->job->getKey())->where('name', $name)->first();
    }

    /** @return Step[] steps whose name starts with the prefix, in plan order */
    public function stepsLike(string $prefix): array
    {
        return Step::query()->where('job_id', $this->job->getKey())->where('name', 'like', $prefix . '%')->orderBy('seq')->get()->all();
    }

    public function store(Step $step, Response $response, string $suffix): string
    {
        $relative = 'jobs/' . $this->job->uuid . '/' . $step->name . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $suffix) . '.json.gz';
        Cache::write($relative, $response->raw());

        return $relative;
    }

    /** @return Response[] what a fetch or live step stored, in order */
    public function responses(string $stepName): array
    {
        $step = $this->step($stepName);

        return $step === null ? [] : self::responsesOf($step);
    }

    /** @return Response[] */
    public static function responsesOf(Step $step): array
    {
        $out = [];

        foreach ((array) (($step->output ?? [])['responses'] ?? []) as $path) {
            $data = Cache::read((string) $path);

            if ($data !== null) {
                $out[] = new Response($data);
            }
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> every successful task across the responses */
    public static function okTasks(array $responses): array
    {
        $tasks = [];

        foreach ($responses as $response) {
            foreach ($response->tasks() as $task) {
                if (Response::taskOk($task)) {
                    $tasks[] = $task;
                }
            }
        }

        return $tasks;
    }
}
