<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    /** The lifecycle a company moves through. Suspended and cancelled keep every row; they only close the workspace. */
    public const STATUSES = ['trialing', 'active', 'past_due', 'suspended', 'cancelled'];

    protected $fillable = [
        'name', 'slug', 'plan', 'plan_id', 'subscription_status', 'billing_cycle',
        'extra_seats', 'seat_limit_override', 'limit_overrides', 'addons',
        'billing_provider', 'billing_customer_ref', 'billing_subscription_ref',
        'trial_ends_at', 'subscription_ends_at', 'suspended_at', 'cancelled_at', 'status_reason', 'is_active', 'owner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'is_active' => 'boolean',
            'extra_seats' => 'integer',
            'seat_limit_override' => 'integer',
            'limit_overrides' => 'array',
            'addons' => 'array',
        ];
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id')->withTrashed();
    }

    /** The person the company belongs to: an admin no other admin can demote or remove. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function branding(): HasOne
    {
        return $this->hasOne(TenantBranding::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(TenantSetting::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function isSubscriptionUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($this->subscription_status) {
            'trialing' => $this->trial_ends_at === null || $this->trial_ends_at->isFuture(),
            'active' => $this->subscription_ends_at === null || $this->subscription_ends_at->isFuture(),
            // Payment is late, the work is not: a grace period, with the warning on every screen.
            'past_due' => $this->subscription_ends_at === null
                || $this->subscription_ends_at->copy()->addDays((int) config('billing.past_due_grace_days'))->isFuture(),
            default => false,
        };
    }

    /**
     * What the workspace shows about its own subscription. `state` is the effective one — a trial whose date passed is
     * "trial_expired" though nobody changed its status.
     */
    public function lifecycleState(): string
    {
        if (! $this->is_active) {
            return 'disabled';
        }

        if ($this->subscription_status === 'trialing' && $this->trial_ends_at !== null && ! $this->trial_ends_at->isFuture()) {
            return 'trial_expired';
        }

        if ($this->subscription_status === 'active' && $this->subscription_ends_at !== null && ! $this->subscription_ends_at->isFuture()) {
            return 'expired';
        }

        if ($this->subscription_status === 'past_due' && ! $this->isSubscriptionUsable()) {
            return 'expired';
        }

        return $this->subscription_status;
    }
}
