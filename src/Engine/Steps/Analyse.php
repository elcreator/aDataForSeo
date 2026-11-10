<?php

namespace Elcreator\aDataForSeo\Engine\Steps;

use Elcreator\aDataForSeo\Engine\Analyser;
use Elcreator\aDataForSeo\Engine\Outcome;
use Elcreator\aDataForSeo\Engine\Planner;
use Elcreator\aDataForSeo\Engine\Runtime;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Step;

/**
 * Pure PHP over data the job already holds. Idempotent: it rewrites the rows
 * it owns. An analyser may extend the plan (another page of `site:` results,
 * the traffic of the hosts it just found) and fill a deferred step's tasks.
 */
final class Analyse extends StepRunner
{
    public function run(Step $step, Runtime $rt): Outcome
    {
        $result = Analyser::make((string) $step->param('analyser'))->run($rt, $step);

        foreach ((array) ($result['fill'] ?? []) as $name => $tasks) {
            $target = $rt->step((string) $name);

            if ($target !== null && !$target->isFinished()) {
                $payload = (array) $target->payload;
                $payload['tasks'] = $tasks === [] ? null : array_values($tasks);
                $target->forceFill(['payload' => $payload])->save();
            }
        }

        if (!empty($result['extend'])) {
            Planner::insertAfter($rt->job, $step, (array) $result['extend'], $rt->hasClient() ? $rt->client()->estimator() : null);
        }

        self::saveOutput($step, ['summary' => $result['output'] ?? []]);

        if (!empty($result['result'])) {
            $job = Job::query()->find($rt->job->getKey());
            $job->forceFill(['result' => array_replace((array) ($job->result ?? []), (array) $result['result'])])->save();
            $rt->job->setRawAttributes($job->getAttributes(), true);
        }

        return Outcome::done();
    }
}
