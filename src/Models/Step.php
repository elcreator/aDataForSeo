<?php

namespace Elcreator\aDataForSeo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One step of a job's plan: post, wait, fetch, live or analyse.
 *
 * `dfs_task_ids` maps a DataForSEO task id to `{state: posted|ready|fetched,
 * index, call_id}`; it is written before the job advances, so a worker that
 * dies after `task_post` never pays for the same tasks twice.
 */
class Step extends Model
{
    public const PENDING = 'pending';
    public const POSTING = 'posting';
    public const WAITING = 'waiting';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    protected $table = 'adfs_steps';

    protected $guarded = [];

    protected $casts = [
        'job_id' => 'integer',
        'seq' => 'integer',
        'payload' => 'array',
        'dfs_task_ids' => 'array',
        'output' => 'array',
        'attempts' => 'integer',
        'polls' => 'integer',
        'estimate' => 'float',
        'cost' => 'float',
        'next_check_at' => 'datetime',
    ];

    public function job()
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::DONE, self::SKIPPED], true);
    }

    public function param(string $key, $default = null)
    {
        return data_get(is_array($this->payload) ? $this->payload : [], $key, $default);
    }
}
