<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\QualificationStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\ExcludesTestLeads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    /**
     * One invariant, enforced where nobody can route around it.
     *
     * A lead whose status says it was contacted but whose `first_contact_at`
     * is null is a lead the product contradicts itself about: the badge reads
     * the status and says "contacted", the work queue reads the timestamp and
     * says "new lead, never touched". A rep who sees both trusts neither.
     *
     * Three controllers each remembered to set the timestamp by hand, and the
     * automation engine — which can move a lead to any stage on a customer's
     * instruction — did not. Rather than add a fourth place to remember, the
     * rule lives on the model: any save that leaves the lead in a status that
     * means contact happened backfills the timestamp.
     *
     * `now()` is an approximation when some other path skipped the timestamp,
     * but it is a defensible one and it is never a contradiction.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new ExcludesTestLeads);

        static::saving(function (self $lead) {
            if ($lead->first_contact_at === null && $lead->status?->impliesContactWasMade()) {
                $lead->first_contact_at = now();
            }

            /*
             * The funnel chart counts `qualified_at` and the velocity chart
             * measures two of its three steps from it. Only the qualification
             * screen ever set it — so a lead qualified from the work queue, by
             * a rule, or by a manager changing the stage was missing from both,
             * and for a workspace that works its leads through "اعمل الآن" the
             * middle of the funnel was permanently near zero.
             */
            if ($lead->qualified_at === null && $lead->status?->impliesQualificationHappened()) {
                $lead->qualified_at = now();
            }

            /*
             * The dashboard counts a reply as `first_response_at is not null`
             * and the sales report measures time-to-first-reply from it, so a
             * lead moved to "replied" by anything other than an inbound message
             * was a reply nothing could see.
             *
             * Never before the contact it answers: a reply stamped earlier than
             * the first contact makes the response time negative, and the
             * report drops those rows as a clock that ran backwards.
             */
            if ($lead->first_response_at === null && $lead->status?->impliesLeadResponded()) {
                $lead->first_response_at = max(now(), $lead->first_contact_at ?? now());
            }

            /*
             * An owner and the moment they got it travel together. The
             * assignment service sets both; everything that writes
             * `assigned_to` directly — an import, a rule, a correction — set
             * only one, leaving a lead that is owned since never, or one that
             * is unowned and still carries the hour it was handed over.
             */
            if ($lead->assigned_to === null) {
                $lead->assigned_at = null;
            } elseif ($lead->assigned_at === null) {
                $lead->assigned_at = now();
            }
        });
    }

    protected $fillable = [
        'assigned_to', 'project_id',
        'first_name', 'last_name', 'full_name',
        'phone', 'phone_normalized', 'email', 'city',
        'platform', 'source',
        'campaign_name', 'campaign_id', 'adset_name', 'adset_id',
        'ad_name', 'ad_id', 'form_name', 'form_id',
        'click_id', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'status', 'is_test', 'temperature', 'score',
        'qualification_status', 'qualified_at', 'appointment_interest',
        'budget_min', 'budget_max',
        'purchase_purpose', 'payment_method', 'financing_status',
        'preferred_property_type', 'preferred_location', 'purchase_timeline',
        'next_follow_up_at', 'last_contact_at', 'assigned_at',
        'first_contact_at', 'first_response_at',
        'whatsapp_opt_in_at', 'whatsapp_opt_out_at',
        'won_at', 'lost_at', 'lost_reason_id', 'lost_note', 'deal_value_minor',
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
            'qualification_status' => QualificationStatus::class,
            'score' => 'integer',
            'appointment_interest' => 'boolean',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'next_follow_up_at' => 'datetime',
            'last_contact_at' => 'datetime',
            'qualified_at' => 'datetime',
            'first_contact_at' => 'datetime',
            'first_response_at' => 'datetime',
            'whatsapp_opt_in_at' => 'datetime',
            'whatsapp_opt_out_at' => 'datetime',
            'assigned_at' => 'datetime',
            'won_at' => 'datetime',
            'deal_value_minor' => 'integer',
            'lost_at' => 'datetime',
            'provider_payload' => 'array',
            're_engaged_count' => 'integer',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** The one screen that shows the lead created to prove the setup works. */
    public function scopeWithTestLeads($query)
    {
        return $query->withoutGlobalScope(ExcludesTestLeads::class);
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

    public function messages(): HasMany
    {
        return $this->hasMany(LeadMessage::class);
    }

    /** Whether this lead may still be messaged. Opt-out is final until re-opt-in. */
    public function acceptsWhatsapp(): bool
    {
        return $this->whatsapp_opt_out_at === null;
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

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class)->orderBy('scheduled_at');
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
