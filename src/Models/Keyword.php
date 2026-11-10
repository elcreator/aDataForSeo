<?php

namespace Elcreator\aDataForSeo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One search query in one market and language, its demand, and where it
 * landed: which target page, which cluster, whether it is the one that counts.
 */
class Keyword extends Model
{
    protected $table = 'adfs_keywords';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'volume' => 'integer',
        'monthly' => 'array',
        'kd_labs' => 'integer',
        'cpc' => 'float',
        'competition' => 'float',
        'brand' => 'boolean',
        'origin' => 'boolean',
        'target_id' => 'integer',
        'cluster_id' => 'integer',
        'counted' => 'boolean',
        'shared' => 'boolean',
        'fetched_at' => 'datetime',
    ];
}
