<?php

namespace Elcreator\aDataForSeo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The ledger, one row per API answer (cached answers too, at cost 0), and the
 * index of stored responses (`response_path`, gzip under core/storage).
 */
class Call extends Model
{
    protected $table = 'adfs_calls';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'job_id' => 'integer',
        'step_id' => 'integer',
        'user_id' => 'integer',
        'cost' => 'float',
        'estimate' => 'float',
        'cached' => 'boolean',
        'status_code' => 'integer',
        'tasks' => 'integer',
        'items' => 'integer',
        'depth' => 'integer',
        'fetched_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
