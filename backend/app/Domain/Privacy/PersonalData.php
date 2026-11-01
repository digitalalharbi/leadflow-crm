<?php

namespace App\Domain\Privacy;

use App\Models\Lead;
use App\Models\ScheduledAction;
use App\Models\Suppression;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the company holds about one person, handed over or taken away.
 *
 * The export is everything a person could recognise as theirs: what they told the company, what was said to them,
 * the appointments and the history — not the raw provider payloads, which are the company's plumbing.
 *
 * Erasure removes the person and keeps the arithmetic. Their name, number, address, words, answers and notes are
 * gone from every table that held them; the row stays, soft-deleted and marked erased, so last month's lead count,
 * the campaign's cost per lead and the funnel do not change after the fact. By default the number and address go on
 * the do-not-contact list, because "delete my data" also means "do not message me again" — the one thing kept.
 *
 * Not reached: export files already made (deleted within 24 hours by leadflow:prune-exports) and backups, which
 * age out on their own schedule.
 */
class PersonalData
{
    public const ERASED_NAME = 'محذوف بطلب صاحبه';

    /** Columns on the lead that identify or describe the person. */
    private const LEAD_COLUMNS = [
        'first_name', 'last_name', 'phone', 'phone_normalized', 'email', 'city', 'provider_payload', 'lost_note',
        'click_id', 'click_ids', 'referrer', 'landing_url', 'visitor_id', 'nationality', 'facts_note', 'device', 'browser',
        'preferred_location', 'preferred_district',
    ];

    public function __construct(private SuppressionList $suppressions, private AuditLogger $audit) {}

    /**
     * The lead and every duplicate merged into it, following chains of merges.
     *
     * @return Collection<int, Lead>
     */
    private function withMergedDuplicates(Lead $lead): Collection
    {
        $rows = collect([$lead]);
        $frontier = [$lead->id];

        // Bounded: a merge chain longer than this is not a person's history.
        for ($depth = 0; $frontier !== [] && $depth < 20; $depth++) {
            $found = Lead::withTrashed()->where('tenant_id', $lead->tenant_id)->whereIn('merged_into_id', $frontier)
                ->whereNotIn('id', $rows->pluck('id'))->get();
            $rows = $rows->concat($found);
            $frontier = $found->pluck('id')->all();
        }

        return $rows->values();
    }

    /** @return array<string,mixed> */
    public function export(Lead $lead): array
    {
        $lead->loadMissing(['project:id,uuid,name', 'assignee:id,name']);
        $by = fn (string $table) => DB::table($table)->where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id);

        return [
            'exported_at' => now()->toIso8601String(),
            'person' => [
                'id' => $lead->uuid,
                'name' => $lead->full_name,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'phone' => $lead->phone_normalized ?? $lead->phone,
                'email' => $lead->email,
                'city' => $lead->city,
                'nationality' => $lead->nationality,
                'created_at' => $lead->created_at?->toIso8601String(),
                'source' => array_filter(['platform' => $lead->platform, 'source' => $lead->source, 'campaign' => $lead->campaign_name, 'form' => $lead->form_name]),
                'project' => $lead->project?->name,
                'handled_by' => $lead->assignee?->name,
                'status' => $lead->status?->value,
                'preferences' => array_filter([
                    'budget_min' => $lead->budget_min, 'budget_max' => $lead->budget_max,
                    'purpose' => $lead->purchase_purpose, 'payment_method' => $lead->payment_method,
                    'property_type' => $lead->preferred_property_type, 'location' => $lead->preferred_location,
                    'district' => $lead->preferred_district, 'timeline' => $lead->purchase_timeline,
                    'bedrooms' => $lead->bedrooms, 'area_sqm' => $lead->area_sqm, 'preferred_channel' => $lead->preferred_channel,
                ], fn ($v) => $v !== null && $v !== ''),
                'consent' => array_filter([
                    'whatsapp_opt_in_at' => $lead->whatsapp_opt_in_at, 'whatsapp_opt_out_at' => $lead->whatsapp_opt_out_at,
                    'email_opt_out_at' => $lead->email_opt_out_at, 'tracking_consent' => $lead->tracking_consent,
                ], fn ($v) => $v !== null),
            ],
            'answers' => DB::table('lead_answers')->join('project_questions', 'project_questions.id', '=', 'lead_answers.project_question_id')
                ->where('lead_answers.tenant_id', $lead->tenant_id)->where('lead_answers.lead_id', $lead->id)
                ->get(['project_questions.label as question', 'lead_answers.value as answer'])->map(fn ($r) => (array) $r)->all(),
            'custom_fields' => DB::table('custom_field_values')->join('custom_fields', 'custom_fields.id', '=', 'custom_field_values.custom_field_id')
                ->where('custom_field_values.tenant_id', $lead->tenant_id)->where('custom_field_values.lead_id', $lead->id)
                ->get(['custom_fields.label as field', 'custom_field_values.value'])->map(fn ($r) => (array) $r)->all(),
            'messages' => $by('lead_messages')->orderBy('created_at')
                ->get(['direction', 'channel', 'subject', 'body', 'status', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            'calls' => $by('calls')->orderBy('created_at')->get(['direction', 'status', 'duration_seconds', 'started_at'])->map(fn ($r) => (array) $r)->all(),
            'appointments' => $by('appointments')->whereNull('deleted_at')->orderBy('scheduled_at')
                ->get(['scheduled_at', 'duration_minutes', 'status', 'location'])->map(fn ($r) => (array) $r)->all(),
            'reservations' => $by('reservations')->orderBy('created_at')->get(['status', 'amount_minor', 'reserved_at', 'expires_at'])->map(fn ($r) => (array) $r)->all(),
            'history' => $by('lead_activities')->orderBy('created_at')->get(['type', 'description', 'created_at'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** @return array{suppressed: array<int,string>} */
    public function erase(Lead $lead, User $by, bool $suppress = true): array
    {
        $tenant = $lead->tenant_id;
        $suppressed = [];

        /*
         * The person is every row that was ever them: the lead, and every duplicate merged into it (and into those).
         * A merge soft-deletes the duplicate with its name, number and payload intact, so erasing only the kept
         * lead left the identity in the table.
         */
        $rows = $this->withMergedDuplicates($lead);

        DB::transaction(function () use ($lead, $rows, $by, $suppress, $tenant, &$suppressed) {
            if ($suppress) {
                foreach ($rows as $row) {
                    foreach (['phone' => $row->phone_normalized ?: $row->phone, 'email' => $row->email] as $channel => $value) {
                        if (filled($value) && $this->suppressions->add($tenant, $channel, (string) $value, Suppression::REASONS[0], $by->id, 'erasure') !== null && ! in_array($channel, $suppressed, true)) {
                            $suppressed[] = $channel;
                        }
                    }
                }
            }

            $ids = $rows->pluck('id')->all();
            $uuids = $rows->pluck('uuid')->all();
            $emails = $rows->pluck('email')->filter()->unique()->values()->all();
            $scope = fn (string $table) => DB::table($table)->where('tenant_id', $tenant)->whereIn('lead_id', $ids);

            $scope('lead_messages')->update(['body' => null, 'subject' => null, 'provider_payload' => null, 'context' => null, 'send_spec' => null]);
            $scope('lead_notes')->update(['body' => '—']);
            $scope('lead_activities')->update(['description' => null, 'meta' => null]);
            $scope('lead_answers')->delete();
            $scope('custom_field_values')->delete();
            $scope('lead_facts')->delete();
            $scope('lead_engagements')->update(['meta' => null]);
            $scope('lead_source_events')->update(['payload' => null]);
            $scope('webhook_logs')->update(['payload' => null, 'ip' => null, 'error' => null]);
            $scope('import_rows')->update(['data' => null]);
            $scope('appointments')->update(['notes' => null, 'outcome_note' => null, 'location' => null]);
            $scope('follow_ups')->update(['note' => null, 'outcome_note' => null]);
            $scope('tasks')->update(['notes' => null]);
            $scope('reservations')->update(['notes' => null, 'cancel_reason' => null]);
            $scope('file_shares')->delete();
            $scope('visitor_sessions')->update(['lead_id' => null, 'ip_hash' => null]);
            $scope('tracking_events')->update(['lead_id' => null, 'ip_hash' => null]);
            // What automation wrote about them: the action's description and its before/after can quote them.
            $scope('automation_action_logs')->update(['description' => null, 'before' => null, 'after' => null]);
            $scope('automation_runs')->update(['reason' => null, 'applied_actions' => null]);
            // A message still waiting to go to an erased person is not sent; what it would have said is gone too.
            $scope('scheduled_actions')->where('status', ScheduledAction::PENDING)->update(['status' => ScheduledAction::CANCELLED, 'resolved_at' => now()]);
            $scope('scheduled_actions')->update(['action' => json_encode(['type' => 'erased'])]);
            $scope('conversion_exports')->update(['request_summary' => null]);

            // The alerts in people's bells that named them.
            if ($uuids !== []) {
                DB::table('notifications')->where('tenant_id', $tenant)->whereIn(DB::raw("data->>'lead_id'"), $uuids)
                    ->update(['data' => DB::raw("jsonb_build_object('kind', data->'kind', 'severity', data->'severity', 'title', '—', 'body', '—')")]);
            }

            if ($emails !== []) {
                DB::table('email_deliveries')->where('tenant_id', $tenant)->whereIn('to_email', $emails)
                    ->update(['to_email' => 'erased@invalid', 'to_name' => null, 'html' => null, 'text' => null]);
            }

            foreach ($rows as $row) {
                $row->forceFill(array_fill_keys(self::LEAD_COLUMNS, null) + [
                    'full_name' => self::ERASED_NAME,
                    'tags' => [],
                    'interest_signals' => null,
                    'erased_at' => now(),
                ])->saveQuietly();
            }

            $lead->deleteQuietly();
        });

        // Says that it happened and who did it — never who it was.
        $this->audit->log('lead.erased', $by, $lead, ['suppressed' => $suppressed]);

        return ['suppressed' => $suppressed];
    }
}
