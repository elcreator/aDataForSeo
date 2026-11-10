<?php

namespace Elcreator\aDataForSeo\Queue;

use Elcreator\aDataForSeo\Models\Job;

/**
 * Something that can carry a job slice to a worker.
 *
 * A queue is only a carrier. Progress, the heartbeat, `wake_at` and
 * cancellation live in `adfs_jobs`, so the page reads one table whichever
 * queue is active and a cancel works the same on both.
 */
interface Queue
{
    /** Short stable name, stored in `adfs_jobs.queue`: system | stask. */
    public function name(): string;

    public function available(): bool;

    /** @return array{ok: bool, message: string} */
    public function health(): array;

    /**
     * Hand the job's next slice to a worker and record where it went. Safe to
     * call twice for the same job (a slice already waiting is reused).
     */
    public function dispatch(Job $job): void;
}
