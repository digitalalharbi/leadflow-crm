<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One event on its way to one destination — once. */
class DestinationDelivery extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const AWAITING_CREDENTIALS = 'awaiting_credentials';

    protected $fillable = ['destination_id', 'tracking_event_id', 'event_id', 'event_name', 'status', 'reason', 'attempts', 'last_error', 'request_summary', 'sent_at'];

    protected function casts(): array
    {
        return ['request_summary' => 'array', 'sent_at' => 'datetime'];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(TrackingDestination::class, 'destination_id');
    }
}
