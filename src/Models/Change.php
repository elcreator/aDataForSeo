<?php

namespace Elcreator\aDataForSeo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One value the plugin wrote on the site and what was there before, so it can
 * be undone while the field still holds what we wrote.
 */
class Change extends Model
{
    protected $table = 'adfs_changes';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'issue_id' => 'integer',
        'target_id' => 'integer',
        'user_id' => 'integer',
        'resource_id' => 'integer',
        'applied_at' => 'datetime',
        'undone_at' => 'datetime',
    ];
}
