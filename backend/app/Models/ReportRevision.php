<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A report's configuration at one point in its life: never the numbers it showed. */
class ReportRevision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['report_share_id', 'revision', 'configuration', 'changed', 'changed_by'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'changed' => 'array', 'revision' => 'integer'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
