<?php

namespace Elcreator\aDataForSeo\Engine;

use Elcreator\aDataForSeo\Api\Estimate;
use Elcreator\aDataForSeo\Api\Estimator;
use Elcreator\aDataForSeo\Gateway;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Step;

/**
 * Turns step definitions into rows and prices them.
 *
 * A definition is `['name', 'kind', 'endpoint', 'payload' => [...]]`. Post and
 * live steps are priced from their tasks; a deferred live step (tasks written
 * later by an analyser) from `estimate_tasks` × `expect_items`; a gateway submit
 * from the gateway's own quote of `payload.op` + `payload.body`; wait, fetch
 * and analyse steps are free.
 */
final class Planner
{
    public static function estimate(array $definition, Estimator|Gateway\Estimator|null $estimator, int $priority = 1): Estimate
    {
        $kind = (string) ($definition['kind'] ?? '');
        $payload = (array) ($definition['payload'] ?? []);

        if ($kind === 'gw_submit') {
            return $estimator instanceof Gateway\Estimator ? $estimator->quote((string) $payload['op'], (array) $payload['body']) : Estimate::unknown('no_key');
        }

        if (!in_array($kind, ['post', 'live'], true)) {
            return Estimate::free();
        }

        if (!$estimator instanceof Estimator) {
            return Estimate::unknown('no_key');
        }

        $endpoint = (string) ($definition['endpoint'] ?? '');
        $tasks = $payload['tasks'] ?? null;

        if ($tasks === null && !empty($payload['deferred'])) {
            $count = max(0, (int) ($payload['estimate_tasks'] ?? 1));

            if ($count === 0) {
                return Estimate::free();
            }

            $one = $estimator->estimate($endpoint, [['placeholder' => true]], $priority, isset($payload['expect_items']) ? (int) $payload['expect_items'] : null);

            return $one->amount === null ? $one : Estimate::range($one->amount * $count, (float) ($one->low ?? $one->amount) * $count, (float) ($one->high ?? $one->amount) * $count, $one->basis);
        }

        if ($tasks === null) {
            return $estimator->estimate($endpoint, null, $priority);
        }

        $tasks = array_values(array_filter((array) $tasks, 'is_array'));

        // Live steps send one task per request; post steps batch them.
        if ($kind === 'live') {
            return Estimate::sum(array_map(
                static fn ($task) => $estimator->estimate($endpoint, [$task], $priority, isset($payload['expect_items']) ? (int) $payload['expect_items'] : null),
                $tasks
            ));
        }

        return $estimator->estimate($endpoint, $tasks, $priority);
    }

    /** @param array<int, array<string, mixed>> $definitions */
    public static function persist(Job $job, array $definitions, Estimator|Gateway\Estimator|null $estimator, int $startSeq = 1): Estimate
    {
        $estimates = [];
        $seq = $startSeq;
        $priority = (int) $job->option('priority', 1);

        foreach ($definitions as $definition) {
            $estimate = self::estimate($definition, $estimator, $priority);
            $estimates[] = $estimate;

            Step::query()->create([
                'job_id' => $job->getKey(),
                'seq' => $seq++,
                'name' => (string) $definition['name'],
                'kind' => (string) $definition['kind'],
                'endpoint' => (string) ($definition['endpoint'] ?? ''),
                'payload' => (array) ($definition['payload'] ?? []),
                'status' => Step::PENDING,
                'estimate' => $estimate->amount,
            ]);
        }

        return Estimate::sum($estimates);
    }

    /** Insert steps right after `$after`, shifting the rest of the plan. */
    public static function insertAfter(Job $job, Step $after, array $definitions, Estimator|Gateway\Estimator|null $estimator): void
    {
        $definitions = array_values(array_filter($definitions, 'is_array'));

        if ($definitions === []) {
            return;
        }

        $taken = Step::query()->where('job_id', $job->getKey())->pluck('name')->all();

        foreach ($definitions as $i => $definition) {
            $name = (string) $definition['name'];

            while (in_array($name, $taken, true)) {
                $name .= '_';
            }

            $definitions[$i]['name'] = $name;
            $taken[] = $name;
        }

        Step::query()->where('job_id', $job->getKey())->where('seq', '>', $after->seq)->increment('seq', count($definitions));
        self::persist($job, $definitions, $estimator, $after->seq + 1);
    }
}
