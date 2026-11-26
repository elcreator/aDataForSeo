<?php

namespace Elcreator\aDataForSeo\Engine\Steps;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Engine\Outcome;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;

/**
 * `task_get/<variant>/<id>` (free) for every ready id; each answer is stored
 * for the job. A task that is ready only on paper (40601 handed, 40602 in
 * queue) goes back to `retry` and is asked again after a back-off. A fetched
 * id is never fetched again, so a refund it reports is booked once.
 */
final class Fetch extends StepRunner
{
    private const NOT_YET = [40601, 40602];

    public function run(Step $step, Runtime $rt): Outcome
    {
        $post = $rt->step((string) $step->param('of'));

        if ($post === null) {
            return Outcome::failed('The step to fetch for is missing.');
        }

        $ids = (array) $post->dfs_task_ids;
        $responses = self::storedResponses($step);
        $notYet = false;
        $errors = (array) (($step->output ?? [])['errors'] ?? []);

        foreach ($ids as $id => $task) {
            $state = (string) ($task['state'] ?? '');

            if (!in_array($state, ['ready', 'retry'], true)) {
                continue;
            }

            if ($rt->outOfTime()) {
                $post->forceFill(['dfs_task_ids' => $ids])->save();
                self::saveOutput($step, ['responses' => $responses, 'errors' => $errors]);

                return Outcome::partial();
            }

            $response = $rt->client()->call(rtrim($step->endpoint, '/') . '/' . $id, null, $rt->contextFor($step));
            $first = $response->task();
            $code = (int) ($first['status_code'] ?? 0);

            if ($response->ok()) {
                $responses[(string) $id] = $rt->store($step, $response, (string) $id);
                $ids[$id]['state'] = 'fetched';
                self::takeRefund($rt, $post, $step, $ids[$id], $first, $response->cached);
            } elseif (in_array($code, self::NOT_YET, true)) {
                $ids[$id]['state'] = 'retry';
                $notYet = true;
            } else {
                $ids[$id]['state'] = 'failed';
                $errors[(string) $id] = ['code' => $code, 'message' => mb_substr((string) ($first['status_message'] ?? ''), 0, 200)];
            }

            $post->forceFill(['dfs_task_ids' => $ids])->save();
        }

        $step->polls++;
        self::saveOutput($step, ['responses' => $responses, 'errors' => $errors]);

        $open = array_filter($ids, static fn ($t) => in_array($t['state'] ?? '', ['posted', 'ready', 'retry'], true));

        if ($open !== [] || $notYet) {
            return Outcome::waitUntil(Carbon::now()->addSeconds(Wait::backoff($step->polls)));
        }

        if ($responses === [] && $ids !== []) {
            return Outcome::failed((string) (reset($errors)['message'] ?? 'No task could be fetched.'));
        }

        return Outcome::done();
    }

    /** A lower cost in `task_get` than at post is DataForSEO's refund; it nets on the posting step. */
    private static function takeRefund(Runtime $rt, Step $post, Step $step, array &$task, array $answer, bool $cached): void
    {
        $posted = (float) ($task['cost'] ?? 0);
        $final = $answer['cost'] ?? null;

        if ($cached || !is_numeric($final) || $posted <= 0 || (float) $final >= $posted) {
            return;
        }

        $rt->client()->ledger()->refund($rt->contextFor($post), rtrim($step->endpoint, '/'), $posted - (float) $final);
        $task['cost'] = (float) $final;
    }
}
