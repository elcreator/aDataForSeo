<?php

namespace Elcreator\aDataForSeo\Engine\Steps;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\Endpoints;
use Elcreator\aDataForSeo\Engine\Outcome;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Support\Config;

/**
 * `tasks_ready` (free): marks the posted ids DataForSEO has finished and backs
 * off between checks (`limits.poll_seconds`). Never sleeps: a step still
 * waiting returns the moment of its next check, and the slice ends.
 *
 * `probe_after` polls without news marks the rest as worth a direct
 * `task_get` (also free): the ready list is account-wide and capped, so a
 * busy account can push our ids off it.
 */
final class Wait extends StepRunner
{
    public function run(Step $step, Runtime $rt): Outcome
    {
        $post = $rt->step((string) $step->param('of'));

        if ($post === null) {
            return Outcome::failed('The step to wait for is missing.');
        }

        $ids = (array) $post->dfs_task_ids;
        $pending = array_keys(array_filter($ids, static fn ($t) => ($t['state'] ?? '') === 'posted'));

        if ($pending === []) {
            return Outcome::done();
        }

        $response = $rt->client()->call(Endpoints::base($post->endpoint) . '/tasks_ready', null, $rt->contextFor($step));

        foreach ($response->items() as $item) {
            $id = (string) ($item['id'] ?? '');

            if ($id !== '' && isset($ids[$id]) && ($ids[$id]['state'] ?? '') === 'posted') {
                $ids[$id]['state'] = 'ready';
            }
        }

        $step->polls++;
        $probeAfter = $step->param('probe_after');

        if ($probeAfter !== null && $step->polls >= (int) $probeAfter) {
            foreach ($ids as $id => $task) {
                if (($task['state'] ?? '') === 'posted') {
                    $ids[$id]['state'] = 'ready';
                }
            }
        }

        $post->forceFill(['dfs_task_ids' => $ids])->save();
        $step->save();

        $still = array_filter($ids, static fn ($t) => ($t['state'] ?? '') === 'posted');

        if ($still === []) {
            return Outcome::done();
        }

        $rt->report('waiting', (int) $rt->job->progress, count($ids) - count($still) . '/' . count($ids) . ' ready');

        return Outcome::waitUntil(Carbon::now()->addSeconds(self::backoff($step->polls)));
    }

    public static function backoff(int $polls): int
    {
        $steps = Config::pollSeconds();

        return $steps[min(max(0, $polls - 1), count($steps) - 1)];
    }
}
