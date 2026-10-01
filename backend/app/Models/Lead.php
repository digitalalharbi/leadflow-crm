<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'assigned_to', 'project_id',
        'first_name', 'last_name', 'full_name',
        'phone', 'phone_normalized', 'email', 'city',
        'platform', 'source',
        'campaign_name', 'campaign_id', 'adset_name', 'adset_id',
        'ad_name', 'ad_id', 'form_name', 'form_id',
        'status', 'temperature', 'score',
        'budget_min', 'budget_max',
        'purchase_purpose', 'payment_method', 'financing_status',
        'preferred_property_type', 'preferred_location', 'purchase_timeline',
        'next_follow_up_at', 'last_contact_at', 'assigned_at',
        'won_at', 'lost_at', 'lost_reason_id', 'lost_note',
        'provider', 'provider_lead_id', 'provider_payload',
        'created_by', 're_engaged_count',
    ];

    /** Raw provider payloads can contain PII and are never exposed by the API resource. */
    protected $hidden = ['provider_payload'];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'temperature' => LeadTemperature::class,
            'score' => 'integer',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'next_follow_up_at' => 'datetime',
            'last_contact_at' => 'datetime',
            'assigned_at' => 'datetime',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
            'provider_payload' => 'array',
            're_engaged_count' => 'integer',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lostReason(): BelongsTo
    {
        return $this->belongsTo(LostReason::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest();
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function sourceEvents(): HasMany
    {
        return $this->hasMany(LeadSourceEvent::class)->latest('received_at');
    }

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /** Sales reps only ever see leads assigned to them. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->seesAllLeads()) {
            return $query;
        }

        return $query->where('leads.assigned_to', $user->id);
    }

    public function scopeOverdueFollowUp(Builder $query): Builder
    {
        return $query->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<', now())
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value]);
    }

    public function isOpen(): bool
    {
        return ! $this->status->isClosed();
    }
}
