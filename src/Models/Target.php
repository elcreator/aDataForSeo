<?php

namespace Elcreator\aDataForSeo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One page the site should have: (country, geo, deal, type, language), the
 * document that serves it if any, its real demand and its priority.
 */
class Target extends Model
{
    public const EXISTS = 'exists';
    public const NOT_INDEXED = 'not_indexed';
    public const MISSING = 'missing';
    public const NEEDS_LOCALE = 'needs_locale';

    protected $table = 'adfs_targets';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'document_id' => 'integer',
        'document_manual' => 'boolean',
        'primary_keyword_id' => 'integer',
        'demand_real' => 'integer',
        'demand_ambiguous' => 'integer',
        'demand_origin' => 'integer',
        'demand_raw' => 'integer',
        'kd_serp' => 'float',
        'native_share' => 'float',
        'score' => 'float',
        'serp_snapshot' => 'array',
    ];

    public function primaryKeyword()
    {
        return $this->belongsTo(Keyword::class, 'primary_keyword_id');
    }

    /** Zero is an answer; null is "not measured". */
    public function measured(): bool
    {
        return $this->demand_real !== null || $this->demand_ambiguous !== null;
    }
}
