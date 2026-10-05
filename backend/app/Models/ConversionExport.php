<?php

namespace App\Models;

use App\Domain\Conversions\ConversionEvent;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One sales outcome on its way back to the ad platform that produced the lead.
 */
class ConversionExport extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    /** Not a failure: the workspace simply has not connected that platform yet. */
    public const AWAITING_CREDENTIALS = 'awaiting_credentials';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** Nothing to send: the lead carries no identifier the platform could match. */
    public const UNMATCHABLE = 'unmatchable';

    protected $fillable = [
        'lead_id', 'platform', 'event', 'value', 'currency',
        'occurred_at', 'status', 'attempts', 'last_error', 'sent_at', 'request_summary',
    ];

    protected function casts(): array
    {
        return [
            'event' => ConversionEvent::class,
            'value' => 'decimal:2',
            'occurred_at' => 'datetime',
            'sent_at' => 'datetime',
            'request_summary' => 'array',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /** Ready to try: queued, or waiting on credentials that may now exist. */
    public function scopeSendable($query)
    {
        return $query->whereIn('status', [self::PENDING, self::AWAITING_CREDENTIALS]);
    }
}
