<?php

namespace App\Models;

use App\Domain\Leads\InterestEngine;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing that happened on a link's trail: a click, a form, a visit — or a sale. */
class TrackingEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        // A page view or a brochure open changes how interested the lead is.
        static::saved(fn (self $m) => in_array($m->event_type, ['visit', 'project_view', 'property_view', 'brochure_open'], true) && InterestEngine::touch($m->lead_id, 'tracking'));
    }

    protected $fillable = [
        'tracking_link_id', 'lead_id', 'visitor_id', 'event_type', 'occurred_at', 'project_id', 'property_id',
        'url', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'click_id',
        'device', 'browser', 'os', 'ip_hash', 'meta', 'dedupe_key', 'event_id', 'session_id', 'campaign_id', 'platform', 'consent',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'meta' => 'array'];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class, 'tracking_link_id')->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
