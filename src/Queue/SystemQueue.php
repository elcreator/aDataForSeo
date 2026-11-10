<?php

namespace Elcreator\aDataForSeo\Queue;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Console\JobHandler;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Support\Config;
use EvolutionCMS\Models\SystemCliTask;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The CMS's own task queue (`system:task-worker`).
 *
 * One row in `system_cli_tasks` carries one slice of a job, so a job appears
 * on the worker-health page beside a site update and inherits leases and the
 * cancellation flag. The table has no "not before" column: a slice that only
 * waits for DataForSEO queues no successor (a long-running worker would poll
 * in a hot loop); `adfs:tick` dispatches the job again at `wake_at`.
 * The payload carries ids only, never credentials.
 */
final class SystemQueue implements Queue
{
    public function name(): string
    {
        return 'system';
    }

    public function available(): bool
    {
        try {
            return class_exists(SystemCliTask::class) && Schema::hasTable('system_cli_tasks');
        } catch (Throwable) {
            return false;
        }
    }

    public function health(): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'message' => 'The CMS task queue is not available.'];
        }

        try {
            $payload = (new \EvolutionCMS\Services\SystemTasks\WorkerHealthService())->getStatusPayload();
            $status = (string) ($payload['status'] ?? 'unknown');
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'The worker status is unknown.'];
        }

        return ['ok' => in_array($status, ['healthy', 'degraded'], true), 'message' => 'Task worker: ' . $status . '.'];
    }

    public function dispatch(Job $job): void
    {
        $task = static::enqueue($job);
        $values = ['queue' => $this->name(), 'task_id' => $task === null ? null : (int) $task->getKey()];

        Job::query()->whereKey($job->getKey())->update($values);
        $job->forceFill($values)->syncOriginal();
    }

    /**
     * Queue the next slice, unless one is already waiting. `$exceptTaskId`
     * is the slice asking for its successor: still `running`, it must not
     * count as one.
     */
    public static function enqueue(Job $job, ?int $exceptTaskId = null): ?SystemCliTask
    {
        if ($job->isFinished()) {
            return null;
        }

        $existing = static::pendingTaskFor($job, $exceptTaskId);

        if ($existing !== null) {
            return $existing;
        }

        $now = Carbon::now();

        return SystemCliTask::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => JobHandler::TYPE,
            'target' => (string) $job->uuid,
            'requested_version' => '',
            'status' => 'queued',
            'step' => 'queued',
            'progress' => (int) $job->progress,
            'message' => 'Queued',
            'payload_json' => [
                'job_id' => (int) $job->getKey(),
                'job_uuid' => (string) $job->uuid,
                'display_title' => 'aDataForSeo: ' . $job->kind,
                'task_type' => JobHandler::TYPE,
            ],
            'result_json' => [],
            'created_by' => (int) $job->user_id,
            'locked_by' => '',
            'attempt_count' => 0,
            'worker_host' => '',
            'worker_pid' => null,
            'error_code' => '',
            'catalog_snapshot_hash' => '',
            'requested_by_snapshot' => ['user_id' => (int) $job->user_id],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** An unfinished task for this job; a running one that stopped touching its row belongs to a dead worker. */
    public static function pendingTaskFor(Job $job, ?int $exceptTaskId = null): ?SystemCliTask
    {
        $alive = Carbon::now()->subSeconds(max(60, (int) Config::limit('stale_after', 900)));

        return SystemCliTask::query()
            ->where('type', JobHandler::TYPE)
            ->where('target', (string) $job->uuid)
            ->whereIn('status', ['queued', 'picked', 'running'])
            ->when($exceptTaskId !== null, static fn ($q) => $q->where('id', '!=', $exceptTaskId))
            ->where(static fn ($q) => $q->where('status', 'queued')->orWhere('updated_at', '>=', $alive))
            ->orderByDesc('id')
            ->first();
    }

    /** Ask the CMS to stop a job's pending slices (its cancel flag). */
    public static function cancel(Job $job): void
    {
        try {
            SystemCliTask::query()->where('type', JobHandler::TYPE)->where('target', (string) $job->uuid)
                ->whereIn('status', ['queued', 'picked', 'running'])
                ->update(['cancellation_requested_at' => Carbon::now()]);
        } catch (Throwable) {
            // No CMS queue table: the job's own status is the cancel.
        }
    }
}
