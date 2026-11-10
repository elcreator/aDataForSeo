<?php

namespace Elcreator\aDataForSeo\Engine\Steps;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\ApiException;
use Elcreator\aDataForSeo\Api\Endpoints;
use Elcreator\aDataForSeo\Api\Response;
use Elcreator\aDataForSeo\Engine\Outcome;
use Elcreator\aDataForSeo\Engine\Pingback;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;

/**
 * `task_post`: hands tasks to DataForSEO's queue (cheaper than live) and
 * stores the returned ids before anything else.
 *
 * Every task carries `tag` = `<job uuid>:<step id>`. A worker that died
 * between paying and recording the ids leaves the step in `posting`; the next
 * attempt looks for its tag in `tasks_ready` instead of paying again, and only
 * re-posts when nothing turned up within ten minutes.
 */
final class Post extends StepRunner
{
    private const CHUNK = 100;
    private const RECOVERY_MINUTES = 10;

    public function run(Step $step, Runtime $rt): Outcome
    {
        if ((array) $step->dfs_task_ids !== []) {
            return Outcome::done();
        }

        $tag = $rt->job->uuid . ':' . $step->getKey();

        if ($step->status === Step::POSTING) {
            $found = $this->recover($step, $rt, $tag);

            if ($found !== []) {
                $step->forceFill(['dfs_task_ids' => $found, 'tag' => $tag])->save();

                return Outcome::done();
            }

            $since = ($step->output ?? [])['posting_at'] ?? null;

            if ($since !== null && Carbon::parse($since)->greaterThan(Carbon::now()->subMinutes(self::RECOVERY_MINUTES))) {
                return Outcome::waitUntil(Carbon::now()->addSeconds(60));
            }
        }

        $tasks = array_values(array_filter((array) $step->param('tasks', []), 'is_array'));

        if ($tasks === []) {
            return Outcome::skipped();
        }

        $pingback = Pingback::url((string) $rt->job->uuid);
        $priority = max(1, min(2, (int) $rt->job->option('priority', 1)));

        foreach ($tasks as $i => $task) {
            $task['tag'] = $tag;

            if ($pingback !== null) {
                $task['pingback_url'] = $pingback;
            }

            if ($priority === 2 && Endpoints::family($step->endpoint) !== 'on_page') {
                $task['priority'] = 2;
            }

            $tasks[$i] = $task;
        }

        // Marked before paying: from here on a crash means "maybe paid".
        $step->forceFill(['status' => Step::POSTING, 'tag' => $tag, 'attempts' => $step->attempts + 1])->save();
        self::saveOutput($step, ['posting_at' => Carbon::now()->toIso8601String()]);

        $ids = [];
        $errors = [];

        foreach (array_chunk($tasks, self::CHUNK, true) as $chunk) {
            try {
                $response = $rt->client()->call($step->endpoint, array_values($chunk), $rt->contextFor($step));
            } catch (ApiException $e) {
                // A budget refusal is decided before any request: nothing was paid.
                if ($e->refusal !== null && $ids === []) {
                    $step->forceFill(['status' => Step::PENDING])->save();
                }

                throw $e;
            }

            $indexes = array_keys($chunk);

            foreach ($response->tasks() as $position => $task) {
                $index = $indexes[$position] ?? $position;

                if (Response::taskOk($task) && isset($task['id'])) {
                    $ids[(string) $task['id']] = ['state' => 'posted', 'index' => $index];
                } else {
                    $errors[] = ['index' => $index, 'code' => (int) ($task['status_code'] ?? 0), 'message' => mb_substr((string) ($task['status_message'] ?? ''), 0, 200)];
                }
            }

            $step->forceFill(['dfs_task_ids' => $ids])->save();
        }

        self::saveOutput($step, ['errors' => $errors, 'posted' => count($ids)]);

        if ($ids === []) {
            return Outcome::failed($errors[0]['message'] ?? 'DataForSEO accepted none of the tasks.');
        }

        return Outcome::done();
    }

    /** @return array<string, array{state: string, index: int}> ids already posted under this tag */
    private function recover(Step $step, Runtime $rt, string $tag): array
    {
        $response = $rt->client()->call(Endpoints::base($step->endpoint) . '/tasks_ready', null, $rt->contextFor($step));
        $found = [];
        $i = 0;

        foreach ($response->items() as $item) {
            if ((string) ($item['tag'] ?? '') === $tag && isset($item['id'])) {
                $found[(string) $item['id']] = ['state' => 'ready', 'index' => $i++];
            }
        }

        return $found;
    }
}
