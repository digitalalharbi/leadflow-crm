<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pass over a sheet.
 *
 * Counts, not rows. "Why did nothing arrive on Tuesday" needs numbers and the
 * reasons rows were refused; it does not need a second copy of somebody's
 * customer list living in a log table.
 */
class SheetSyncRun extends Model
{
    use BelongsToTenant;

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** Enough to fix the sheet, not enough to become a copy of it. */
    public const MAX_RECORDED_FAILURES = 25;

    protected $fillable = [
        'sheet_source_id', 'trigger', 'status', 'rows_seen', 'imported',
        'duplicates', 'failed', 'cursor_before', 'cursor_after',
        'failures', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'failures' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SheetSource::class, 'sheet_source_id');
    }
}
