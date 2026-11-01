<?php

namespace App\Domain\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadSourceEvent;
use App\Models\ScheduledAction;
use App\Models\User;
use App\Services\Leads\LeadScoringService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Two records, one person: everything the duplicate had becomes the kept
 * lead's — messages, calls, notes, follow-ups, appointments, files, answers,
 * the automation trace, every arrival — and the duplicate is retired.
 *
 * Nothing is lost on the way:
 *  - the kept lead's own fields win; blanks are filled from the duplicate;
 *  - the duplicate's campaign attribution is kept as a source event on the
 *    kept lead (so the campaign that brought the second enquiry still counts
 *    it), and adopted outright if the kept lead had none;
 *  - the furthest stage wins, so a sale recorded on the duplicate is not erased
 *    — it is the kept lead's sale now, with its revenue and conversions;
 *  - waiting automated steps of the duplicate are cancelled, not moved.
 */
class LeadMerger
{
    /** Tables whose rows simply change owner. */
    private const MOVED = [
        'appointments', 'automation_action_logs', 'automation_runs', 'calls', 'file_shares', 'follow_ups',
        'import_rows', 'lead_activities', 'lead_engagements', 'lead_messages', 'lead_notes', 'lead_source_events', 'webhook_logs',
    ];

    /** Fields the kept lead takes from the duplicate only when it has none. */
    private const FILL = ['email', 'city', 'project_id', 'budget_min', 'budget_max', 'whatsapp', 'phone'];

    private const ATTRIBUTION = [
        'platform', 'source', 'campaign_name', 'campaign_id', 'campaign_ref_id', 'ad_set_ref_id', 'ad_ref_id',
        'adset_name', 'adset_id', 'ad_name', 'ad_id', 'form_name', 'form_id', 'click_id',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    ];

    public function __construct(private LeadScoringService $scoring) {}

    /** @return array{lead:Lead, moved:array<string,int>} */
    public function merge(Lead $keep, Lead $duplicate, User $by): array
    {
        if ($keep->is($duplicate)) {
            throw new InvalidArgumentException('A lead cannot be merged into itself.');
        }

        if ($keep->tenant_id !== $duplicate->tenant_id) {
            throw new InvalidArgumentException('Leads of different workspaces cannot be merged.');
        }

        return DB::transaction(function () use ($keep, $duplicate, $by) {
            $moved = [];

            // The waiting steps of the duplicate would write to a person we now know is the same one.
            ScheduledAction::withoutGlobalScopes()->where('lead_id', $duplicate->id)->where('status', ScheduledAction::PENDING)
                ->get()->each->resolve(ScheduledAction::CANCELLED, 'دُمج العميل في سجل آخر.');

            foreach (self::MOVED as $table) {
                $moved[$table] = DB::table($table)->where('lead_id', $duplicate->id)->update(['lead_id' => $keep->id]);
            }

            $moved['scheduled_actions'] = DB::table('scheduled_actions')->where('lead_id', $duplicate->id)->update(['lead_id' => $keep->id]);

            // Unique per lead: move only what the kept lead does not already have.
            $moved['lead_answers'] = DB::table('lead_answers')->where('lead_id', $duplicate->id)
                ->whereNotIn('project_question_id', DB::table('lead_answers')->where('lead_id', $keep->id)->select('project_question_id'))
                ->update(['lead_id' => $keep->id]);
            $moved['custom_field_values'] = DB::table('custom_field_values')->where('lead_id', $duplicate->id)
                ->whereNotIn('custom_field_id', DB::table('custom_field_values')->where('lead_id', $keep->id)->select('custom_field_id'))
                ->update(['lead_id' => $keep->id]);
            $moved['conversion_exports'] = DB::table('conversion_exports')->where('lead_id', $duplicate->id)
                ->whereNotExists(fn ($q) => $q->from('conversion_exports as k')->whereColumn('k.platform', 'conversion_exports.platform')
                    ->whereColumn('k.event', 'conversion_exports.event')->where('k.lead_id', $keep->id))
                ->update(['lead_id' => $keep->id]);

            // The second enquiry, as a source of its own — so its campaign is not forgotten.
            (new LeadSourceEvent)->forceFill([
                'tenant_id' => $keep->tenant_id,
                'lead_id' => $keep->id,
                'provider' => $duplicate->provider ?? 'merge',
                'platform' => $duplicate->platform,
                'source' => $duplicate->source,
                'campaign_name' => $duplicate->campaign_name,
                'campaign_id' => $duplicate->campaign_id,
                'adset_name' => $duplicate->adset_name,
                'ad_name' => $duplicate->ad_name,
                'form_name' => $duplicate->form_name,
                'provider_lead_id' => $duplicate->provider_lead_id,
                'was_duplicate' => true,
                'received_at' => $duplicate->created_at,
            ])->save();

            $fresh = Lead::withoutGlobalScopes()->findOrFail($keep->id);
            $dup = Lead::withoutGlobalScopes()->findOrFail($duplicate->id);
            $dup->phone = $duplicate->phone;

            $changes = [];
            foreach (self::FILL as $field) {
                // The duplicate's phone is only a fallback display value: the normalised one is unique and stays.
                if (blank($fresh->{$field}) && filled($dup->{$field}) && $field !== 'phone') {
                    $changes[$field] = $dup->{$field};
                }
            }

            if (blank($fresh->campaign_ref_id) && filled($dup->campaign_ref_id)) {
                foreach (self::ATTRIBUTION as $field) {
                    $changes[$field] = $dup->{$field};
                }
            }

            // Earliest firsts, latest lasts.
            foreach (['first_contact_at', 'first_response_at', 'qualified_at'] as $field) {
                $changes[$field] = collect([$fresh->{$field}, $dup->{$field}])->filter()->min();
            }
            $changes['last_contact_at'] = collect([$fresh->last_contact_at, $dup->last_contact_at])->filter()->max();
            $changes['next_follow_up_at'] = collect([$fresh->next_follow_up_at, $dup->next_follow_up_at])->filter()->min();
            $changes['re_engaged_count'] = (int) $fresh->re_engaged_count + 1 + (int) $dup->re_engaged_count;
            $changes['tags'] = LeadTags::normalise([...LeadTags::of($fresh), ...LeadTags::of($dup)]);

            // The furthest stage wins: a sale on the duplicate is this person's sale.
            if ($this->rank($dup->status) > $this->rank($fresh->status)) {
                $changes['status'] = $dup->status;
                $changes['won_at'] = $dup->won_at;
                $changes['deal_value_minor'] = $dup->deal_value_minor;
                $changes['lost_at'] = $dup->lost_at;
            }

            $fresh->forceFill(array_filter($changes, fn ($value) => $value !== null))->save();

            $dup->forceFill(['merged_into_id' => $keep->id, 'merged_at' => now()])->saveQuietly();
            $dup->delete();

            LeadActivity::create([
                'lead_id' => $keep->id,
                'user_id' => $by->id,
                'type' => ActivityType::Merged,
                'description' => "دُمج سجل «{$duplicate->full_name}» في هذا العميل.",
                'meta' => [
                    'merged' => [
                        'id' => $duplicate->uuid, 'name' => $duplicate->full_name,
                        'phone' => $duplicate->phone_normalized ?? $duplicate->phone, 'email' => $duplicate->email,
                        'platform' => $duplicate->platform, 'campaign' => $duplicate->campaign_name,
                        'created_at' => $duplicate->created_at?->toIso8601String(),
                    ],
                    'moved' => array_filter($moved),
                ],
            ]);

            try {
                $this->scoring->refresh($fresh->refresh(), 'merge');
            } catch (\Throwable) {
                // A score is derived; the merge stands without it.
            }

            return ['lead' => $fresh->refresh(), 'moved' => array_filter($moved)];
        });
    }

    /** Pipeline progress, with dead ends below everything: an open lead beats a closed-out one. */
    private function rank(?LeadStatus $status): int
    {
        return match ($status) {
            LeadStatus::Won => 100,
            LeadStatus::Negotiation => 6,
            LeadStatus::Appointment => 5,
            LeadStatus::Qualified => 4,
            LeadStatus::Replied => 3,
            LeadStatus::Contacted => 2,
            LeadStatus::New => 1,
            default => 0,
        };
    }
}
