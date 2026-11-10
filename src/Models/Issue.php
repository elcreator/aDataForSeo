<?php

namespace Elcreator\aDataForSeo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A finding (not indexed, duplicate title, 404 ...) mapped to a document and
 * to the code of the fix that resolves it.
 */
class Issue extends Model
{
    public const OPEN = 'open';
    public const APPLIED = 'applied';
    public const IGNORED = 'ignored';
    public const RESOLVED = 'resolved';

    protected $table = 'adfs_issues';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'job_id' => 'integer',
        'document_id' => 'integer',
        'details' => 'array',
    ];

    public function detail(string $key, $default = null)
    {
        return data_get(is_array($this->details) ? $this->details : [], $key, $default);
    }
}
