<?php

namespace Elcreator\aDataForSeo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One unit of work (an audit, a research run ...), advanced slice by slice by
 * a queue worker. The queue only carries it: status, progress, the heartbeat
 * and cancellation live here, so the page reads one table whichever queue runs it.
 */
class Job extends Model
{
    public const DRAFT = 'draft';
    public const AWAITING_APPROVAL = 'awaiting_approval';
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const WAITING = 'waiting';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const ACTIVE = [self::QUEUED, self::RUNNING, self::WAITING];
    public const FINISHED = [self::DONE, self::FAILED, self::CANCELLED];

    public const KINDS = ['coverage', 'audit', 'research', 'difficulty', 'track', 'competitors', 'custom'];

    protected $table = 'adfs_jobs';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'user_id' => 'integer',
        'progress' => 'integer',
        'estimate' => 'float',
        'estimate_high' => 'float',
        'spent' => 'float',
        'approved' => 'boolean',
        'approved_by' => 'integer',
        'slices' => 'integer',
        'task_id' => 'integer',
        'options' => 'array',
        'result' => 'array',
        'wake_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $job) {
            $job->uuid = $job->uuid ?: (string) Str::uuid();
        });
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function steps()
    {
        return $this->hasMany(Step::class, 'job_id')->orderBy('seq')->orderBy('id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }

    public function option(string $key, $default = null)
    {
        return data_get(is_array($this->options) ? $this->options : [], $key, $default);
    }
}
