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
 *
 * The summary of a finished crawl is where an OnPage refund shows: the post
 * charged every page of `max_crawl_pages`, DataForSEO gives back the pages it
 * did not crawl (measured 2026-11-26: 1 of 100 pages cost $0.00015 of $0.015).
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

                if (str_starts_with($step->endpoint, 'on_page/summary') && !$response->cached && $ref !== null) {
                    self::takeCrawlRefund($rt, $rt->step((string) $step->param('ref')), $ref, (array) ($response->task()['result'][0] ?? []));
                }
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

    /**
     * Book the refund for pages not crawled once: the posted cost scales with the pages, so
     * the final one is posted x crawled / max, at least one page. The task keeps the final cost.
     */
    private static function takeCrawlRefund(Runtime $rt, ?Step $post, string $id, array $summary): void
    {
        $ids = (array) ($post?->dfs_task_ids ?? []);
        $posted = (float) ($ids[$id]['cost'] ?? 0);
        $max = (int) ($summary['crawl_status']['max_crawl_pages'] ?? 0);
        $crawled = $summary['crawl_status']['pages_crawled'] ?? null;

        if ($post === null || $posted <= 0 || ($summary['crawl_progress'] ?? '') !== 'finished' || $max <= 0 || !is_numeric($crawled) || (int) $crawled >= $max) {
            return;
        }

        $final = $posted * max(1, (int) $crawled) / $max;
        $rt->client()->refund($rt->contextFor($post), (string) $post->endpoint, $posted - $final);
        $ids[$id]['cost'] = round($final, 6);
        $post->forceFill(['dfs_task_ids' => $ids])->save();
    }
}
