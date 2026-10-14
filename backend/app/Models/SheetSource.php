<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Google Sheet that leads arrive in.
 *
 * Inbound only. Nothing is written back to the sheet, and nothing in مهتم is
 * read from it a second time — the cursor is what makes the sheet a stream of
 * arrivals rather than a table we keep re-importing.
 */
class SheetSource extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    /** The fields a sheet may be mapped onto. Anything else is ignored. */
    public const MAPPABLE = [
        'full_name', 'phone', 'email', 'city', 'project',
        'campaign_name', 'platform', 'source', 'notes',
    ];

    protected $fillable = [
        'integration_id', 'spreadsheet_id', 'spreadsheet_name', 'sheet_name',
        'header_row', 'mapping', 'defaults', 'cursor_row', 'is_active',
        'last_synced_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'defaults' => 'array',
            'header_row' => 'integer',
            'cursor_row' => 'integer',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Integration::class, 'integration_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SheetSyncRun::class)->latest('started_at');
    }

    /**
     * The first row a sync should read.
     *
     * One past the cursor, and never above the header: a source configured with
     * a header on row 3 must not try to import rows 1 and 2, which are usually
     * a title and a blank line.
     */
    public function nextRow(): int
    {
        return max($this->cursor_row, $this->header_row) + 1;
    }

    /** Whether a mapping names enough to make a lead at all. */
    public function canMakeLeads(): bool
    {
        $mapping = $this->mapping ?? [];

        return filled($mapping['phone'] ?? null) || filled($mapping['email'] ?? null);
    }

    public function health(): array
    {
        return [
            'state' => match (true) {
                ! $this->is_active => 'paused',
                $this->last_error !== null => 'error',
                $this->last_synced_at === null => 'never_synced',
                default => 'connected',
            },
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'cursor_row' => $this->cursor_row,
        ];
    }
}
