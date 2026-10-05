<?php

namespace App\Services\Leads;

use App\DataTransferObjects\IncomingLead;
use App\DataTransferObjects\IngestionResult;
use App\Domain\Leads\DuplicateMatcher;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Events\LeadCreated;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Models\Project;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The single door every lead walks through — webhook, CSV, Google Sheets, Meta,
 * or a rep typing it by hand. Nothing writes to the leads table directly.
 *
 *   normalize -> validate -> duplicate check -> create/re-engage
 *   -> source event -> assignment -> activity -> notification
 */
class LeadIngestionService
{
    public function __construct(
        private PhoneNormalizer $phones,
        private LeadScoringService $scoring,
        private LeadAssignmentService $assignment,
        private ActivityLogger $activities,
        private TenantContext $context,
        private DuplicateMatcher $duplicates,
    ) {}

    public function ingest(Tenant $tenant, IncomingLead $incoming): IngestionResult
    {
        return $this->context->withTenant($tenant, function () use ($tenant, $incoming) {
            $settings = $tenant->settings;
            $countryCode = $settings?->default_country_code ?? '966';

            $normalizedPhone = $this->phones->normalize($incoming->phone, $countryCode);

            $validation = $this->validate($incoming, $normalizedPhone);
            if ($validation !== null) {
                return IngestionResult::rejected($validation);
            }

            return DB::transaction(function () use ($tenant, $incoming, $normalizedPhone, $settings) {
                // Phone alone missed the same person arriving from a second
                // platform with a different number on the form. See
                // DuplicateMatcher for why the order of signals matters.
                $existing = $this->duplicates->find($incoming, $normalizedPhone);

                if ($existing) {
                    return $this->reEngage($existing, $incoming, $normalizedPhone);
                }

                return $this->create($tenant, $incoming, $normalizedPhone, $settings);
            });
        });
    }

    private function validate(IncomingLead $incoming, ?string $normalizedPhone): ?string
    {
        if (! $incoming->hasIdentity()) {
            return 'A lead needs at least a phone number or an email address.';
        }

        if (filled($incoming->phone) && $normalizedPhone === null) {
            return 'The phone number could not be parsed into a valid format.';
        }

        $validator = Validator::make([
            'full_name' => $incoming->fullName,
            'email' => $incoming->email,
        ], [
            'full_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        return null;
    }

    private function create(Tenant $tenant, IncomingLead $incoming, ?string $phone, $settings): IngestionResult
    {
        $lead = Lead::create([
            'first_name' => $incoming->firstName,
            'last_name' => $incoming->lastName,
            'full_name' => $incoming->fullName ?: ($phone ?? $incoming->email),
            'phone' => $incoming->phone,
            'phone_normalized' => $phone,
            'email' => $incoming->email,
            'city' => $incoming->city,
            'platform' => $incoming->platform,
            'source' => $incoming->source,
            'campaign_name' => $incoming->campaignName,
            'campaign_id' => $incoming->campaignId,
            'adset_name' => $incoming->adsetName,
            'adset_id' => $incoming->adsetId,
            'ad_name' => $incoming->adName,
            'ad_id' => $incoming->adId,
            'form_name' => $incoming->formName,
            'form_id' => $incoming->formId,
            'click_id' => $incoming->clickId,
            'utm_source' => $incoming->utmSource,
            'utm_medium' => $incoming->utmMedium,
            'utm_campaign' => $incoming->utmCampaign,
            'utm_term' => $incoming->utmTerm,
            'utm_content' => $incoming->utmContent,
            'status' => LeadStatus::New,
            // Resolved under the tenant scope: an id belonging to another tenant
            // simply is not there, so it lands as null instead of leaking.
            'project_id' => $this->resolveProjectId($incoming->projectId),
            'budget_min' => $incoming->budgetMin,
            'budget_max' => $incoming->budgetMax,
            'purchase_purpose' => $incoming->purchasePurpose,
            'payment_method' => $incoming->paymentMethod,
            'preferred_property_type' => $incoming->preferredPropertyType,
            'preferred_location' => $incoming->preferredLocation,
            'purchase_timeline' => $incoming->purchaseTimeline,
            'provider' => $incoming->provider->value,
            'provider_lead_id' => $incoming->providerLeadId,
            'provider_payload' => $incoming->redactedPayload() ?: null,
        ]);

        /*
         * Scored after the row exists, by the same engine the qualification
         * screen uses. Scoring the DTO separately meant an inbound lead and the
         * same lead re-saved by a rep could disagree about its own temperature.
         */
        $this->scoring->apply($lead);
        $lead->qualification_status = $this->scoring->qualificationStatus($lead);
        $lead->saveQuietly();

        $this->recordSourceEvent($lead, $incoming, false);
        $this->storeCustomFields($lead, $incoming);
        $this->activities->log($lead, ActivityType::Created, null, null, ['provider' => $incoming->provider->value]);

        // Explicit assignment from the payload wins; otherwise fall back to tenant policy.
        $assignee = $incoming->assignedTo
            ? $tenant->users()->whereKey($incoming->assignedTo)->first()
            : $this->assignment->pickAssignee($tenant);

        if ($assignee) {
            $this->assignment->assign($lead, $assignee);
        }

        $lead->refresh();

        /*
         * Announced after the lead is saved and assigned, never before: a rule
         * that reads `is_assigned` must see the finished record, and a listener
         * that fails must not be able to roll back the lead itself.
         */
        LeadCreated::dispatch($lead, null, [
            'provider' => $incoming->provider->value,
            'platform' => (string) $incoming->platform,
        ]);

        return IngestionResult::created($lead);
    }

    /**
     * A known phone number coming back is a re-engagement, not a new record.
     * We keep the original lead, attach the new attribution as a source event,
     * and surface it to the rep instead of silently dropping it.
     */
    private function reEngage(Lead $lead, IncomingLead $incoming, ?string $phone): IngestionResult
    {
        if ($lead->trashed()) {
            $lead->restore();
        }

        // Backfill only what is missing — never overwrite data a rep has curated.
        $backfill = array_filter([
            'email' => $lead->email ?: $incoming->email,
            'city' => $lead->city ?: $incoming->city,
            'full_name' => $lead->full_name ?: $incoming->fullName,
            'project_id' => $lead->project_id ?: $this->resolveProjectId($incoming->projectId),
        ], fn ($v) => $v !== null);

        $lead->fill($backfill);
        $lead->re_engaged_count = $lead->re_engaged_count + 1;

        // A returning lead is worth attention again: reopen if it was closed-lost.
        if ($lead->status === LeadStatus::Lost) {
            $lead->status = LeadStatus::New;
            $lead->lost_at = null;
            $lead->lost_reason_id = null;
        }

        $lead->save();

        $this->recordSourceEvent($lead, $incoming, true);
        $this->activities->log($lead, ActivityType::ReEngaged, null, null, [
            'provider' => $incoming->provider->value,
            'campaign_name' => $incoming->campaignName,
        ]);

        return IngestionResult::reEngaged($lead->refresh());
    }

    /** Scoped lookup — a foreign or unknown project id resolves to null. */
    private function resolveProjectId(?int $projectId): ?int
    {
        if ($projectId === null) {
            return null;
        }

        return Project::whereKey($projectId)->value('id');
    }

    private function recordSourceEvent(Lead $lead, IncomingLead $incoming, bool $duplicate): void
    {
        LeadSourceEvent::create([
            'lead_id' => $lead->id,
            'provider' => $incoming->provider->value,
            'platform' => $incoming->platform,
            'source' => $incoming->source,
            'campaign_name' => $incoming->campaignName,
            'campaign_id' => $incoming->campaignId,
            'adset_name' => $incoming->adsetName,
            'ad_name' => $incoming->adName,
            'form_name' => $incoming->formName,
            'provider_lead_id' => $incoming->providerLeadId,
            'was_duplicate' => $duplicate,
            'payload' => $incoming->redactedPayload() ?: null,
            'received_at' => now(),
        ]);
    }

    private function storeCustomFields(Lead $lead, IncomingLead $incoming): void
    {
        if ($incoming->custom === []) {
            return;
        }

        $fields = CustomField::where('is_active', true)
            ->whereIn('key', array_keys($incoming->custom))
            ->get()
            ->keyBy('key');

        foreach ($incoming->custom as $key => $value) {
            if (! $fields->has($key) || $value === null || $value === '') {
                continue;
            }

            CustomFieldValue::updateOrCreate(
                ['custom_field_id' => $fields[$key]->id, 'lead_id' => $lead->id],
                ['value' => (string) $value],
            );
        }
    }
}
