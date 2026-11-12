<?php

namespace Elcreator\aDataForSeo\Engine\Steps;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\Cache;
use Elcreator\aDataForSeo\Engine\Outcome;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\Lang;

/**
 * Asks the gateway (`GET /v1/seo/tasks/{id}`, free) whether a submitted analysis has
 * finished, backing off between checks (`gateway.poll_seconds`). Never sleeps: a task that
 * is still running returns the moment of the next check and the slice ends.
 *
 * When it is done the answer is kept for the job (the analytics, never provider records) and
 * what the gateway reports as charged is booked in the ledger in euros, once. A failed task
 * is never charged and fails the step with the gateway's own sentence.
 */
final class GatewayWait extends StepRunner
{
    public function run(Step $step, Runtime $rt): Outcome
    {
        $submit = $rt->step((string) $step->param('of'));
        $taskId = $submit === null ? null : array_key_first((array) $submit->dfs_task_ids);

        if ($taskId === null) {
            return Outcome::failed(Lang::get('aDataForSeo::global.error_gateway_no_task'));
        }

        if (isset(($step->output ?? [])['result_path'])) {
            return Outcome::done();
        }

        $task = $rt->gateway()->task((string) $taskId);
        $state = (string) ($task['state'] ?? 'running');

        if ($state === 'failed') {
            return Outcome::failed(Lang::get('aDataForSeo::global.error_gateway_task_failed', ['detail' => mb_substr((string) ($task['error'] ?? ''), 0, 200)]));
        }

        if ($state !== 'done') {
            $step->polls++;
            $step->save();
            $rt->report('waiting', (int) $rt->job->progress, 'ai.artur.work');

            return Outcome::waitUntil(Carbon::now()->addSeconds(self::backoff($step->polls)));
        }

        if (!empty($task['expired']) || !is_array($task['result'] ?? null)) {
            return Outcome::failed(Lang::get('aDataForSeo::global.error_gateway_expired'));
        }

        $charged = (float) ($task['chargedEur'] ?? 0);
        $rt->gateway()->record($rt->contextFor($step), (string) $step->param('op'), $charged, (string) $taskId, count((array) ($task['result']['items'] ?? [])), $submit->estimate === null ? null : (float) $submit->estimate);

        $path = 'jobs/' . $rt->job->uuid . '/' . $step->name . '-result.json.gz';
        Cache::write($path, (array) $task['result']);
        self::saveOutput($step, ['result_path' => $path, 'charged' => round($charged, 6), 'fetched_at' => $task['result']['fetchedAt'] ?? null]);

        return Outcome::done();
    }

    public static function backoff(int $polls): int
    {
        $steps = Config::gatewayPollSeconds();

        return $steps[min(max(0, $polls - 1), count($steps) - 1)];
    }
}
