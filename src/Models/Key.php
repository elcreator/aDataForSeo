<?php

namespace Elcreator\aDataForSeo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A DataForSEO login, for the whole site (`scope` site) or one manager
 * (`scope` user). The password is only ever stored encrypted and never leaves
 * the server.
 */
class Key extends Model
{
    protected $table = 'adfs_keys';

    protected $guarded = [];

    protected $hidden = ['password_enc'];

    protected $casts = [
        'user_id' => 'integer',
        'balance' => 'float',
        'subscriptions' => 'array',
        'verified_at' => 'datetime',
    ];
}
