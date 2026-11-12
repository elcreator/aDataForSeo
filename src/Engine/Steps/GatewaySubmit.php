<?php

namespace Elcreator\aDataForSeo\Engine\Steps;

use Elcreator\aDataForSeo\Api\ApiException;
use Elcreator\aDataForSeo\Engine\Outcome;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Step;

/**
 * One gateway call that starts an analysis (index coverage, audit, difficulty of up to
 * 100 keywords), and the task id it answers with.
 *
 * Safe to run twice (hard rule 9): the idempotency key is `<job uuid>:<step id>`, written to
 * the step before the request goes out, so a worker that died between the gateway accepting
 * the call and this step recording the task id repeats the call and gets the stored answer,
 * not a second run. The one exception is a refusal: the gateway stores 4xx answers under the
 * key too, so a "balance too low" would otherwise come back for a day after the top-up. A
 * refusal ran nothing and charged nothing, so the next attempt uses a new generation of the
 * key.
 */
final class GatewaySubmit extends StepRunner
{
    public function run(Step $step, Runtime $rt): Outcome
    {
        if ((array) $step->dfs_task_ids !== []) {
            return Outcome::done();
        }

        $generation = (int) (($step->output ?? [])['key_generation'] ?? 0);
        $key = $rt->job->uuid . ':' . $step->getKey() . ($generation > 0 ? ':' . $generation : '');

        $step->forceFill(['tag' => $key, 'attempts' => $step->attempts + 1])->save();

        try {
            $answer = $rt->gateway()->submit((string) $step->param('op'), (array) $step->param('body', []), $key, $rt->contextFor($step));
        } catch (ApiException $e) {
            if ($e->statusCode >= 400 && $e->statusCode < 500 && !in_array($e->statusCode, [408, 409, 429], true)) {
                self::saveOutput($step, ['key_generation' => $generation + 1]);
            }

            throw $e;
        }

        $step->forceFill(['dfs_task_ids' => [$answer['taskId'] => ['state' => 'posted', 'index' => 0]]])->save();

        return Outcome::done();
    }
}
