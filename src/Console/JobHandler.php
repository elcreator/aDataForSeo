<?php

namespace Elcreator\aDataForSeo\Console;

use Elcreator\aDataForSeo\Engine\Slice;
use Elcreator\aDataForSeo\Engine\SliceResult;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Queue\SystemQueue;
use Elcreator\aDataForSeo\Services\Jobs;
use Elcreator\aDataForSeo\Support\Config;
use EvolutionCMS\Interfaces\SystemTaskHandlerInterface;
use EvolutionCMS\Models\SystemCliTask;

/**
 * One slice of one job, executed by the CMS's own task worker.
 *
 * Works for `limits.slice_seconds`, then queues a successor only when there
 * is more to do right now. A job that waits for DataForSEO ends its slice
 * with `wake_at` set and no successor; `adfs:tick` or the pingback brings it
 * back. Never writes the task's `status`: the worker owns that.
 */
class JobHandler implements SystemTaskHandlerInterface
{
    public const TYPE = 'adataforseo.job';

    public function execute(SystemCliTask $task, ?callable $report = null)
    {
        $job = $this->resolve($task);

        if ($job === null) {
            return ['message' => 'The job this task refers to no longer exists.', 'result' => ['skipped' => true]];
        }

        if ($this->cancelRequested($task) && !$job->isFinished()) {
            (new Jobs())->markCancelled($job);

            return $this->conclude($job->refresh(), 'Cancelled.');
        }

        $result = (new Slice())->run($job, (float) max(5, (int) Config::limit('slice_seconds', 45)), $report);
        $job->refresh();

        if ($result->status === SliceResult::MORE) {
            SystemQueue::enqueue($job, (int) $task->getKey());
        }

        return $this->conclude($job, $result->message ?: (string) $job->status);
    }

    private function conclude(Job $job, string $message): array
    {
        return [
            'message' => mb_substr($message, 0, 255),
            'result' => [
                'job_uuid' => (string) $job->uuid,
                'status' => (string) $job->status,
                'progress' => (int) $job->progress,
                'spent' => (float) $job->spent,
                'wake_at' => $job->wake_at?->toIso8601String(),
            ],
        ];
    }

    private function resolve(SystemCliTask $task): ?Job
    {
        $payload = is_array($task->payload_json) ? $task->payload_json : [];
        $id = (int) ($payload['job_id'] ?? 0);

        if ($id > 0 && ($job = Job::query()->find($id)) !== null) {
            return $job;
        }

        $uuid = trim((string) $task->target);

        return $uuid === '' ? null : Job::query()->where('uuid', $uuid)->first();
    }

    private function cancelRequested(SystemCliTask $task): bool
    {
        return SystemCliTask::query()->whereKey($task->getKey())->whereNotNull('cancellation_requested_at')->exists();
    }
}
