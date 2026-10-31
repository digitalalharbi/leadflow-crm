<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a company wants to be told about its own events. The secret is encrypted at rest and never returned.
 */
class WebhookSubscription extends Model
{
    use BelongsToTenant, HasUuid;

    /** What can be subscribed to, and the internal event each comes from (see OutboundWebhooks). */
    public const EVENTS = [
        'lead.created', 'lead.updated', 'lead.qualified', 'lead.interested', 'lead.assigned',
        'visit.created', 'reservation.created', 'sale.created', 'lead.lost',
    ];

    /** After this many failures in a row the subscription pauses itself, so a dead endpoint is not hammered. */
    public const PAUSE_AFTER = 15;

    protected $fillable = ['name', 'url', 'secret', 'events', 'is_active', 'created_by', 'consecutive_failures', 'last_success_at', 'last_failure_at', 'last_error', 'paused_at', 'paused_reason'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    public function listensTo(string $event): bool
    {
        return $this->is_active && $this->paused_at === null && in_array($event, $this->events ?? [], true);
    }
}
