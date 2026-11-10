<?php

namespace Elcreator\aDataForSeo\Queue;

use Carbon\Carbon;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Workers\JobWorker;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Seiger\sTask\Models\sTaskModel;
use Seiger\sTask\Models\sWorker;
use Seiger\sTask\Services\WorkerDiscovery;
use Seiger\sTask\Workers\BaseWorker;
use Throwable;

/**
 * sTask, when it is installed (soft dependency: nothing here is touched
 * unless its classes exist, and the worker class is file-guarded).
 *
 * One sTask task carries one slice. sTask's duplicate guard returns the active
 * task for identical meta, so the meta holds the slice number. Its CLI worker
 * honours `start_at`, so a waiting job's successor is created to start at
 * `wake_at` instead of being woken by the tick.
 */
final class STaskQueue implements Queue
{
    public const IDENTIFIER = 'aDataForSeoJob';

    public function name(): string
    {
        return 'stask';
    }

    public function available(): bool
    {
        try {
            return class_exists(BaseWorker::class)
                && class_exists(JobWorker::class)
                && class_exists(sTaskModel::class)
                && Schema::hasTable('s_tasks')
                && Schema::hasTable('s_workers');
        } catch (Throwable) {
            return false;
        }
    }

    public function health(): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'message' => 'sTask is not installed.'];
        }

        try {
            $worker = sWorker::query()->where('identifier', self::IDENTIFIER)->first();
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'The sTask worker list is unavailable.'];
        }

        return $worker !== null && $worker->active
            ? ['ok' => true, 'message' => 'sTask worker registered.']
            : ['ok' => true, 'message' => 'sTask is installed; the aDataForSeo worker is registered on first use.'];
    }

    public function dispatch(Job $job): void
    {
        $this->dispatchAt($job, null);
    }

    /** Queue the next slice; with `$startAt` it waits in sTask until then. */
    public function dispatchAt(Job $job, ?Carbon $startAt): void
    {
        if (!$this->available()) {
            throw new RuntimeException('sTask is not installed.');
        }

        static::ensureRegistered();

        $task = app(JobWorker::class)->createTask('make', ['job_uuid' => (string) $job->uuid, 'slice' => (int) $job->slices + 1]);

        if ($startAt !== null && $startAt->isFuture()) {
            $task->update(['start_at' => $startAt]);
        }

        $values = ['queue' => $this->name(), 'task_id' => (int) $task->getKey()];
        Job::query()->whereKey($job->getKey())->update($values);
        $job->forceFill($values)->syncOriginal();
    }

    /** Make sure sTask knows the worker and has it switched on (new worker records are inactive). */
    public static function ensureRegistered(): bool
    {
        if (!class_exists(BaseWorker::class) || !class_exists(JobWorker::class)) {
            return false;
        }

        try {
            $worker = sWorker::query()->where('identifier', self::IDENTIFIER)->first();

            if ($worker === null) {
                $worker = app(WorkerDiscovery::class)->registerWorker(JobWorker::class);
            }

            if ($worker !== null && !$worker->active) {
                $worker->active = true;
                $worker->save();
            }

            return $worker !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** sTask has no cancel of its own: the job row is the cancel, and the stale guard keeps old tasks harmless. */
    public static function staleAfter(): int
    {
        return max(60, (int) Config::limit('stale_after', 900));
    }
}
