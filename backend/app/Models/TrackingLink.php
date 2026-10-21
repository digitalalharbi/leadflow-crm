<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\ProjectScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A link that knows what it is for: `/t/{token}`.
 *
 * The token is public and says nothing; everything the link means lives here — the project, the
 * property, the campaign, the ad, the rep, the partner, the channel — so a billboard, a broker's
 * WhatsApp message and a Meta ad can all send people to the same page and still be told apart.
 */
class TrackingLink extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new ProjectScope);
    }

    use BelongsToTenant, HasUuid, SoftDeletes;

    public const CHANNELS = ['meta', 'tiktok', 'snapchat', 'google', 'x', 'linkedin', 'whatsapp', 'landing_page', 'portal', 'email', 'sms', 'influencer', 'broker', 'qr', 'other'];

    public const KINDS = ['link', 'qr'];

    protected $fillable = [
        'token', 'name', 'kind', 'channel', 'project_id', 'property_id', 'campaign_id', 'ad_id', 'creative_id', 'user_id', 'partner_id',
        'landing_page', 'branch', 'destination_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'is_active', 'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'expires_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function creative(): BelongsTo
    {
        return $this->belongsTo(Creative::class);
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TrackingEvent::class);
    }

    /** Whether the link still sends anyone anywhere. */
    public function isLive(): bool
    {
        // `trashed()` as well: the lookup that finds a link by its public token lifts every global scope
        // (it has no tenant yet), and that lifts the soft-delete one too.
        return ! $this->trashed() && $this->is_active && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** The URL that goes in the ad, the QR code, the broker's message. */
    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/t/'.$this->token;
    }
}
