<?php

namespace Elcreator\aDataForSeo\Workers;

use Elcreator\aDataForSeo\Engine\Slice;
use Elcreator\aDataForSeo\Engine\SliceResult;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Queue\STaskQueue;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\EventLog;
use Elcreator\aDataForSeo\Support\Lang;
use Elcreator\aDataForSeo\Support\Manager;
use Elcreator\aDataForSeo\Support\Principal;
use RuntimeException;
use Seiger\sTask\Models\sTaskModel;
use Seiger\sTask\Workers\BaseWorker;

// Keep the package loadable on installations without the optional sTask.
if (!class_exists(BaseWorker::class)) {
    return;
}

/**
 * Runs one slice of one aDataForSeo job as one sTask task.
 *
 * sTask's run endpoint lets any manager create a task of any active worker,
 * so createTask() checks the job's permission itself and refuses without it
 * (as the core's DatabaseBackup worker does).
 */
class JobWorker extends BaseWorker
{
    public function identifier(): string
    {
        return STaskQueue::IDENTIFIER;
    }

    public function scope(): string
    {
        return 'aDataForSeo';
    }

    public function icon(): string
    {
        return '<i class="' . Manager::icon('search', 'search') . '"></i>';
    }

    public function title(): string
    {
        return Lang::get('aDataForSeo::global.queue_worker_title');
    }

    public function description(): string
    {
        return Lang::get('aDataForSeo::global.queue_worker_description');
    }

    /** Jobs are started from the aDataForSeo page; nothing to configure here. */
    public function renderWidget(): string
    {
        return '';
    }

    public function createTask(string $action, ?array $options = null): sTaskModel
    {
        $uuid = trim((string) ($options['job_uuid'] ?? ''));
        $job = $uuid === '' ? null : Job::query()->where('uuid', $uuid)->first();

        if ($job === null) {
            throw new RuntimeException(Lang::get('aDataForSeo::global.error_stask_direct'));
        }

        // The job's own manager must still be allowed to spend; a click on sTask's page changes nothing.
        if (!Principal::forUser((int) $job->user_id)->maySpend()) {
            EventLog::refusal('sTask task for job ' . $uuid, 'forbidden', ['user' => $job->user_id]);

            throw new RuntimeException(Lang::get('aDataForSeo::global.error_forbidden'));
        }

        $task = parent::createTask($action, ['job_uuid' => $uuid, 'slice' => (int) ($options['slice'] ?? 0)]);
        $task->update(['started_by' => (int) $job->user_id]);

        return $task;
    }

    public function taskMake(sTaskModel $task, array $opt = []): void
    {
        $uuid = trim((string) ($opt['job_uuid'] ?? ((array) $task->meta)['job_uuid'] ?? ''));
        $job = $uuid === '' ? null : Job::query()->where('uuid', $uuid)->first();

        if ($job === null) {
            $this->markFinished($task, null, 'The job this task refers to no longer exists.');

            return;
        }

        $task->update(['status' => sTaskModel::TASK_STATUS_RUNNING]);

        $result = (new Slice())->run($job, (float) max(5, (int) Config::limit('slice_seconds', 45)), function (string $phase, int $progress, string $message) use ($task) {
            $this->pushProgress($task, ['status' => 'running', 'progress' => $progress, 'message' => $message]);
        });

        $job->refresh();
        $queue = new STaskQueue();

        if ($result->status === SliceResult::MORE) {
            $queue->dispatchAt($job, null);
        } elseif ($result->status === SliceResult::WAITING && $job->wake_at !== null) {
            $queue->dispatchAt($job, $job->wake_at);
        }

        if ($result->status === SliceResult::FAILED && $job->status === Job::FAILED) {
            throw new RuntimeException((string) ($job->error ?: 'The job failed.'));
        }

        $this->markFinished($task, null, $result->message ?: (string) $job->status);
    }
}
