<?php

namespace Elcreator\aDataForSeo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where the site stood for one query on one day; `position` null means "not
 * within the depth checked", never position zero.
 */
class Ranking extends Model
{
    protected $table = 'adfs_rankings';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'target_id' => 'integer',
        'keyword_id' => 'integer',
        'job_id' => 'integer',
        'position' => 'integer',
        'serp_features' => 'array',
    ];
}
