<?php

namespace Elcreator\aDataForSeo\Engine\Steps;

use Elcreator\aDataForSeo\Engine\Outcome;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;

/**
 * Live-only endpoints (Labs) and the free post-crawl OnPage reads: one task
 * per request, each answer stored before the next is asked, so a re-run
 * resumes at the first task without an answer.
 *
 * `ref` names a post step whose first task id replaces `{id}` in the endpoint
 * and payload. A `deferred` step's tasks are written by an earlier analyser;
 * when it wrote none, the step is skipped.
 */
final class Live extends StepRunner
{
    public function run(Step $step, Runtime $rt): Outcome
    {
        $ref = $step->param('ref') !== null ? self::firstId($rt->step((string) $step->param('ref'))) : null;

        if ($step->param('ref') !== null && $ref === null) {
            return Outcome::failed('The task this step reads was never created.');
        }

        $endpoint = (string) self::resolveRef($step->endpoint, $ref);
        $tasks = $step->param('tasks');

        if ($tasks === null && !$step->param('get', false)) {
            return $step->param('deferred', false) ? Outcome::skipped() : Outcome::failed('The step has nothing to ask.');
        }

        $responses = self::storedResponses($step);
        $errors = (array) (($step->output ?? [])['errors'] ?? []);
        $list = $step->param('get', false) ? [null] : array_values(array_filter((array) $tasks, 'is_array'));

        if ($list === []) {
            return Outcome::skipped();
        }

        foreach ($list as $i => $task) {
            if (isset($responses[(string) $i]) || isset($errors[(string) $i])) {
                continue;
            }

            if ($rt->outOfTime() && $responses !== []) {
                self::saveOutput($step, ['responses' => $responses, 'errors' => $errors]);

                return Outcome::partial();
            }

            $response = $rt->client()->call($endpoint, $task === null ? null : [self::resolveRef($task, $ref)], $rt->contextFor($step), [
                'expect_items' => $step->param('expect_items'),
            ]);

            if ($response->ok()) {
                $responses[(string) $i] = $rt->store($step, $response, (string) $i);
            } else {
                $first = $response->task();
                $errors[(string) $i] = ['code' => (int) ($first['status_code'] ?? 0), 'message' => mb_substr((string) ($first['status_message'] ?? ''), 0, 200)];
            }

            self::saveOutput($step, ['responses' => $responses, 'errors' => $errors]);
        }

        if ($responses === [] && !$step->param('optional', false)) {
            return Outcome::failed((string) (reset($errors)['message'] ?? 'No answer.'));
        }

        return Outcome::done();
    }
}
