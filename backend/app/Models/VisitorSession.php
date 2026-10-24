<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One visit: the links that touched it, the click ids it arrived with, the consent it gave. */
class VisitorSession extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'visitor_id', 'session_id', 'first_link_id', 'last_link_id', 'first_touch', 'last_touch',
        'device', 'browser', 'os', 'ip_hash', 'consent', 'lead_id', 'events_count', 'started_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['first_touch' => 'array', 'last_touch' => 'array', 'started_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function firstLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class, 'first_link_id')->withTrashed();
    }

    public function lastLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class, 'last_link_id')->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
