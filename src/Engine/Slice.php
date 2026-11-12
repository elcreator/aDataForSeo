<?php

namespace Elcreator\aDataForSeo\Engine;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Api\ApiException;
use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Engine\Steps\StepRunner;
use Elcreator\aDataForSeo\Gateway;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Models\Step;
use Elcreator\aDataForSeo\Services\Projects;
use Elcreator\aDataForSeo\Support\Billing;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\EventLog;
use Elcreator\aDataForSeo\Support\Keys;
use Elcreator\aDataForSeo\Support\Lang;
use Elcreator\aDataForSeo\Support\Principal;
use Throwable;

/**
 * One slice of one job: at most `$budgetSeconds` of work, then return.
 *
 * Re-checks the job's manager (hard rule 6) and the job's status (cancel is
 * cooperative) before doing anything, then advances steps until the time is
 * spent or every remaining step waits for DataForSEO. Never sleeps: a waiting
 * job gets `wake_at` and the slice ends without a successor.
 */
final class Slice
{
    public function run(Job $job, float $budgetSeconds, ?callable $report = null): SliceResult
    {
        $job->refresh();

        if ($job->isFinished()) {
            return new SliceResult($job->status === Job::DONE ? SliceResult::DONE : SliceResult::FAILED, (string) $job->status);
        }

        if (in_array($job->status, [Job::DRAFT, Job::AWAITING_APPROVAL], true)) {
            return new SliceResult(SliceResult::IDLE, (string) $job->status);
        }

        $project = Project::query()->find($job->project_id);

        if ($project === null) {
            return $this->fail($job, Lang::get('aDataForSeo::global.error_project_gone'));
        }

        $principal = Principal::forUser((int) $job->user_id);

        if (!$principal->maySpend()) {
            EventLog::refusal('job ' . $job->uuid, 'job_user_lost_permission', ['user' => $job->user_id]);

            return $this->fail($job, Lang::get('aDataForSeo::global.error_job_user'));
        }

        // The backend follows the job's own currency, not the project's current setting: a job
        // planned against one account finishes against it even if the project is switched meanwhile.
        $gatewayCredentials = Billing::jobIsGateway($job) ? Keys::gatewayFor($principal) : null;
        $credentials = Billing::jobIsGateway($job) ? null : Keys::for($principal);
        $client = $credentials === null ? null : new Client($credentials);
        $gateway = $gatewayCredentials === null ? null : new Gateway\Client($gatewayCredentials);
        $context = Projects::context($project, $job, $credentials, $gatewayCredentials);
        $rt = new Runtime($job, $project, $client, $context, $budgetSeconds, $report === null ? null : \Closure::fromCallable($report), $gateway);

        $job->forceFill([
            'status' => Job::RUNNING,
            'started_at' => $job->started_at ?? Carbon::now(),
            'heartbeat_at' => Carbon::now(),
            'wake_at' => null,
            'slices' => $job->slices + 1,
        ])->save();

        while (true) {
            if ($this->cancelled($job)) {
                return new SliceResult(SliceResult::FAILED, 'cancelled');
            }

            $steps = Step::query()->where('job_id', $job->getKey())->orderBy('seq')->orderBy('id')->get();
            $step = $steps->first(static fn (Step $s) => !$s->isFinished());

            if ($step === null) {
                return $this->finish($job, $steps->count());
            }

            if ($step->status === Step::FAILED) {
                return $this->fail($job, (string) ($step->error ?: 'A step failed.'));
            }

            if ($step->next_check_at !== null && $step->next_check_at->isFuture()) {
                return $this->park($job, $step->next_check_at);
            }

            if (!$rt->hasBackendFor($step->kind)) {
                return $this->fail($job, Lang::get('aDataForSeo::global.error_no_key'));
            }

            $job->forceFill(['phase' => $step->name, 'heartbeat_at' => Carbon::now()])->save();

            try {
                $outcome = StepRunner::for($step->kind)->run($step, $rt);
            } catch (ApiException $e) {
                $outcome = $this->apiFailure($job, $step, $e);

                if ($outcome instanceof SliceResult) {
                    return $outcome;
                }
            } catch (Throwable $e) {
                $outcome = Outcome::failed($e->getMessage());
            }

            $step->refresh();

            switch ($outcome->status) {
                case Outcome::DONE:
                case Outcome::SKIPPED:
                    $step->forceFill(['status' => $outcome->status === Outcome::DONE ? Step::DONE : Step::SKIPPED, 'next_check_at' => null, 'error' => null])->save();
                    break;
                case Outcome::WAITING:
                    $step->forceFill(['status' => $step->status === Step::POSTING ? Step::POSTING : Step::WAITING, 'next_check_at' => $outcome->nextCheckAt])->save();
                    break;
                case Outcome::PARTIAL:
                    break;
                default:
                    $step->forceFill(['status' => Step::FAILED, 'error' => mb_substr((string) $outcome->error, 0, 2000)])->save();

                    return $this->fail($job, (string) $outcome->error);
            }

            $this->progress($job, $rt);

            if ($rt->outOfTime()) {
                $job->forceFill(['status' => Job::QUEUED, 'heartbeat_at' => Carbon::now()])->save();

                return new SliceResult(SliceResult::MORE, 'continuing');
            }
        }
    }

    /** @return Outcome|SliceResult */
    private function apiFailure(Job $job, Step $step, ApiException $e)
    {
        if ($e->refusal === 'approval_needed') {
            $job->forceFill(['status' => Job::AWAITING_APPROVAL, 'approved' => false, 'error' => $e->getMessage()])->save();

            return new SliceResult(SliceResult::IDLE, $e->getMessage());
        }

        if ($e->refusal !== null || !$e->retryable) {
            return Outcome::failed($e->getMessage());
        }

        $attempts = $step->attempts + 1;

        if ($attempts >= (int) Config::limit('max_attempts', 5)) {
            return Outcome::failed($e->getMessage());
        }

        $step->forceFill(['attempts' => $attempts, 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

        return Outcome::waitUntil(Carbon::now()->addSeconds(min(900, 30 * (2 ** $attempts))));
    }

    private function park(Job $job, Carbon $at): SliceResult
    {
        $job->forceFill(['status' => Job::WAITING, 'wake_at' => $at, 'heartbeat_at' => Carbon::now()])->save();

        return new SliceResult(SliceResult::WAITING, 'waiting', $at);
    }

    private function finish(Job $job, int $steps): SliceResult
    {
        $job->forceFill([
            'status' => Job::DONE,
            'progress' => 100,
            'phase' => 'done',
            'finished_at' => Carbon::now(),
            'wake_at' => null,
            'error' => null,
            'spent' => (new \Elcreator\aDataForSeo\Api\Ledger())->spentJob((int) $job->getKey()),
        ])->save();

        return new SliceResult(SliceResult::DONE, $steps . ' step(s) done');
    }

    private function fail(Job $job, string $message): SliceResult
    {
        $job->forceFill([
            'status' => Job::FAILED,
            'error' => mb_substr($message, 0, 2000),
            'finished_at' => Carbon::now(),
            'wake_at' => null,
        ])->save();

        return new SliceResult(SliceResult::FAILED, $message);
    }

    private function cancelled(Job $job): bool
    {
        $status = Job::query()->whereKey($job->getKey())->value('status');

        if ($status === Job::CANCELLED) {
            $job->status = Job::CANCELLED;

            return true;
        }

        return false;
    }

    private function progress(Job $job, Runtime $rt): void
    {
        $total = Step::query()->where('job_id', $job->getKey())->count();
        $done = Step::query()->where('job_id', $job->getKey())->whereIn('status', [Step::DONE, Step::SKIPPED])->count();
        $progress = $total > 0 ? (int) min(99, floor($done * 100 / $total)) : 0;

        $job->forceFill(['progress' => $progress, 'heartbeat_at' => Carbon::now()])->save();
        $rt->report((string) $job->phase, $progress, $done . '/' . $total);
    }
}
