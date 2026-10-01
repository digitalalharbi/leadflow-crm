<?php

namespace Database\Seeders\Support;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\QualificationStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessage;
use App\Models\LeadNote;
use App\Models\LeadSourceEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Leads\LeadScoringService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds leads that look like they were worked by a real team: a funnel with the
 * shape a sales manager would recognise, timelines that match the stage, and
 * follow-ups deliberately spread across overdue / today / upcoming.
 *
 * Caller must already be inside the tenant context.
 */
class LeadFabricator
{
    private int $phoneCounter;

    private LeadScoringService $scoring;

    public function __construct(
        private Tenant $tenant,
        private Collection $assignees,
        private Collection $projects,
        private Collection $lostReasons,
        int $phoneSeed = 1000,
    ) {
        $this->phoneCounter = $phoneSeed;
        $this->scoring = app(LeadScoringService::class);
    }

    /**
     * @param  array<string,int>  $distribution  status => how many
     * @return int number of leads created
     */
    public function fabricate(array $distribution, int $daysBack = 45): int
    {
        $created = 0;

        foreach ($distribution as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->makeLead($status, $i, $daysBack);
                $created++;
            }
        }

        return $created;
    }

    private function makeLead(string $status, int $index, int $daysBack): Lead
    {
        $first = DemoContent::FIRST_NAMES[array_rand(DemoContent::FIRST_NAMES)];
        $last = DemoContent::LAST_NAMES[array_rand(DemoContent::LAST_NAMES)];
        $campaign = $this->campaignFor($status);
        $project = $this->projects->random();

        // A few fresh leads stay unassigned so the "awaiting assignment" card and
        // the manager's distribution workflow both have something to show.
        $assignee = ($status === LeadStatus::New->value && $index % 4 === 0)
            ? null
            : $this->assignees->random();

        $createdAt = now()->subDays(random_int(0, $daysBack))->subHours(random_int(0, 23));
        $phone = '+96655'.str_pad((string) (++$this->phoneCounter), 7, '0', STR_PAD_LEFT);
        $budgetMin = [500_000, 800_000, 1_000_000, 1_500_000, 2_000_000, 2_800_000][random_int(0, 5)];

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'assigned_to' => $assignee?->id,
            'project_id' => $project->id,
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $first.' '.$last,
            'phone' => $phone,
            'phone_normalized' => $phone,
            'email' => random_int(0, 1) ? Str::lower(Str::random(7)).'@example.com' : null,
            'city' => DemoContent::CITIES[array_rand(DemoContent::CITIES)],
            'platform' => $campaign[1],
            'source' => $campaign[4],
            'campaign_name' => $campaign[0],
            'campaign_id' => '23859'.random_int(100000, 999999),
            'adset_name' => $campaign[2],
            'adset_id' => '23860'.random_int(100000, 999999),
            'ad_name' => $campaign[3],
            'form_name' => 'نموذج التسجيل - '.$project->name,
            'status' => $status,
            'budget_min' => $budgetMin,
            'budget_max' => (int) ($budgetMin * 1.4),
            'purchase_purpose' => random_int(0, 1) ? 'residence' : 'investment',
            'payment_method' => random_int(0, 2) === 0 ? 'cash' : 'financing',
            'preferred_property_type' => DemoContent::PROPERTY_TYPES[array_rand(DemoContent::PROPERTY_TYPES)],
            'preferred_location' => $project->location,
            'purchase_timeline' => DemoContent::TIMELINES[array_rand(DemoContent::TIMELINES)],
            // Everything past "new" has been worked, so it has been qualified to
            // some degree. A demo where every lead is unqualified shows a CRM
            // nobody is using.
            'financing_status' => $this->financingFor($status),
            'qualification_status' => $this->qualificationFor($status),
            'qualified_at' => in_array($status, [
                LeadStatus::Qualified->value, LeadStatus::Appointment->value, LeadStatus::Won->value,
            ], true) ? $createdAt->copy()->addDays(random_int(1, 4)) : null,
            'appointment_interest' => match ($status) {
                LeadStatus::Appointment->value, LeadStatus::Won->value => true,
                LeadStatus::Lost->value => random_int(0, 2) === 0,
                LeadStatus::New->value => null,
                default => random_int(0, 1) === 1,
            },
            'provider' => 'generic_webhook',
            'provider_lead_id' => 'demo-'.Str::random(10),
            'assigned_at' => $assignee ? $createdAt->copy()->addMinutes(random_int(1, 120)) : null,
            'last_contact_at' => $status === LeadStatus::New->value ? null : $createdAt->copy()->addDays(random_int(1, 3)),
            /*
             * Response times a sales manager would recognise: most leads
             * answered within the hour, a tail that took far longer. A seed
             * where everyone is answered in four minutes makes the response
             * report look broken.
             */
            /*
             * A lead still sitting in "new" has mostly not been worked — that
             * is what the stage means, and it is the gap the neglected-leads
             * panel exists to show. Only a minority have been called without
             * anyone moving them along.
             *
             * Everything past "new" was contacted, most of it quickly, with a
             * tail that took far longer: a demo where every lead is answered in
             * four minutes makes the response report look broken.
             */
            'first_contact_at' => $status === LeadStatus::New->value
                ? ($index % 4 === 0 ? $createdAt->copy()->addMinutes(random_int(4, 50)) : null)
                : $createdAt->copy()->addMinutes($index % 7 === 0 ? random_int(240, 2880) : random_int(3, 90)),
            'first_response_at' => in_array($status, [LeadStatus::New->value, LeadStatus::Lost->value], true)
                ? null
                : $createdAt->copy()->addMinutes(random_int(60, 600)),
            'whatsapp_opt_in_at' => $status === LeadStatus::New->value ? null : $createdAt->copy()->addHours(2),
            'won_at' => $status === LeadStatus::Won->value ? $createdAt->copy()->addDays(random_int(5, 20)) : null,
            'lost_at' => $status === LeadStatus::Lost->value ? $createdAt->copy()->addDays(random_int(3, 15)) : null,
            'lost_reason_id' => $status === LeadStatus::Lost->value ? $this->lostReasons->random()->id : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        LeadSourceEvent::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'provider' => 'generic_webhook',
            'platform' => $campaign[1],
            'source' => $campaign[4],
            'campaign_name' => $campaign[0],
            'adset_name' => $campaign[2],
            'ad_name' => $campaign[3],
            'received_at' => $createdAt,
        ]);

        /*
         * Score through the same engine the product uses, never a made-up
         * number. The seeder used to write a random score per stage, so the
         * ring in the lead header said 89 while the breakdown beneath it
         * totalled 77 — the first thing a prospect would notice, and a reason
         * to distrust every other number on the screen.
         */
        $lead->load('project', 'lostReason');
        $this->scoring->apply($lead);
        $lead->saveQuietly();

        $this->buildTimeline($lead, $assignee, $status, $createdAt);
        $this->scheduleFollowUp($lead, $assignee, $status, $createdAt, $index);
        $this->buildConversation($lead, $assignee, $status, $createdAt, $index);

        return $lead;
    }

    /**
     * A WhatsApp exchange on roughly half the worked leads.
     *
     * A demo where every lead has a conversation looks staged, and one where
     * none do cannot show the feature at all. Leads still sitting in "new" get
     * nothing, which is the point of the neglected-leads panel.
     */
    private function buildConversation(Lead $lead, ?User $assignee, string $status, $createdAt, int $index): void
    {
        if ($status === LeadStatus::New->value || $index % 2 === 1) {
            return;
        }

        /*
         * Divided, not modulo'd: only even indexes reach here, so `$index %`
         * could never select an odd-numbered script — which happened to be the
         * one where the customer has not replied yet. A demo without that case
         * cannot show why follow-ups exist.
         */
        $script = DemoContent::CONVERSATIONS[intdiv($index, 2) % count(DemoContent::CONVERSATIONS)];
        $at = $createdAt->copy()->addMinutes(random_int(5, 90));

        foreach ($script as $turn => $body) {
            $outbound = $turn % 2 === 0;

            LeadMessage::create([
                'tenant_id' => $this->tenant->id,
                'lead_id' => $lead->id,
                'user_id' => $outbound ? $assignee?->id : null,
                'direction' => $outbound ? MessageDirection::Outbound : MessageDirection::Inbound,
                'channel' => MessageChannel::Whatsapp,
                'provider' => 'mock',
                'provider_message_id' => 'wamid.demo.'.$lead->id.'.'.$turn,
                'message_type' => 'text',
                'body' => $this->fill($body, $lead, $assignee),
                // The last outbound message of an unfinished exchange is still
                // only delivered — which is what "they have not replied yet"
                // looks like on the screen.
                'status' => match (true) {
                    ! $outbound => MessageStatus::Received,
                    $turn === count($script) - 1 => MessageStatus::Delivered,
                    default => MessageStatus::Read,
                },
                'sent_at' => $outbound ? $at->copy() : null,
                'delivered_at' => $outbound ? $at->copy()->addSeconds(4) : null,
                'read_at' => $outbound && $turn < count($script) - 1 ? $at->copy()->addMinutes(2) : null,
                'created_at' => $at->copy(),
                'updated_at' => $at->copy(),
            ]);

            $at = $at->addMinutes(random_int(3, 45));
        }
    }

    /** The placeholders a saved message carries, filled for this lead. */
    private function fill(string $body, Lead $lead, ?User $assignee): string
    {
        return strtr($body, [
            '{{name}}' => $lead->first_name ?? '',
            '{{agent}}' => $assignee?->name ?? 'فريق المبيعات',
            '{{company}}' => $this->tenant->name,
            '{{project}}' => $lead->project?->name ?? '',
        ]);
    }

    private function financingFor(string $status): ?string
    {
        return match ($status) {
            LeadStatus::Won->value => 'approved',
            LeadStatus::Appointment->value => random_int(0, 1) ? 'approved' : 'in_progress',
            LeadStatus::Qualified->value => ['approved', 'in_progress', 'not_started'][random_int(0, 2)],
            LeadStatus::Contacted->value => random_int(0, 1) ? 'in_progress' : 'not_started',
            LeadStatus::Lost->value => random_int(0, 1) ? 'not_started' : 'unknown',
            default => null,
        };
    }

    private function qualificationFor(string $status): string
    {
        return match ($status) {
            LeadStatus::Qualified->value,
            LeadStatus::Appointment->value,
            LeadStatus::Won->value => QualificationStatus::Qualified->value,
            LeadStatus::Contacted->value => random_int(0, 2) === 0
                ? QualificationStatus::Qualified->value
                : QualificationStatus::PartiallyQualified->value,
            LeadStatus::Lost->value => QualificationStatus::PartiallyQualified->value,
            default => QualificationStatus::Unqualified->value,
        };
    }

    /**
     * Pick a campaign whose quality matches how far this lead actually got.
     *
     * Assigning campaigns at random would give every one of them the same
     * conversion rate, and the campaign-quality report — the reason a
     * developer buys this — would show fourteen identical bars. Weighting the
     * choice by the lead's own outcome reproduces what really happens: search
     * traffic converts, broad video campaigns fill the list and go nowhere.
     */
    private function campaignFor(string $status): array
    {
        // How "good" this lead turned out, on the same 0–1 scale as a campaign.
        $outcome = match ($status) {
            LeadStatus::Won->value => 0.95,
            LeadStatus::Appointment->value => 0.8,
            LeadStatus::Qualified->value => 0.65,
            LeadStatus::Contacted->value => 0.35,
            LeadStatus::Lost->value => 0.2,
            default => 0.25,
        };

        // Weight each campaign by how close its quality is to this outcome,
        // then pick from that distribution — so a won deal usually comes from
        // a search campaign and a dead lead usually comes from a video feed,
        // without either becoming a rule.
        $weights = [];
        foreach (DemoContent::CAMPAIGNS as $index => $campaign) {
            $distance = abs($campaign[5] - $outcome);
            $weights[$index] = (int) round((1 - $distance) ** 3 * 100) + 1;
        }

        $roll = random_int(1, array_sum($weights));

        foreach ($weights as $index => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return DemoContent::CAMPAIGNS[$index];
            }
        }

        return DemoContent::CAMPAIGNS[0];
    }

    private function buildTimeline(Lead $lead, ?User $assignee, string $status, $createdAt): void
    {
        $log = function (ActivityType $type, $at, array $meta = [], ?User $user = null) use ($lead) {
            LeadActivity::create([
                'tenant_id' => $this->tenant->id,
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'type' => $type,
                'meta' => $meta ?: null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        };

        $log(ActivityType::Created, $createdAt, ['provider' => 'generic_webhook']);

        if ($assignee) {
            $log(ActivityType::Assigned, $createdAt->copy()->addMinutes(20), ['assigned_to_name' => $assignee->name]);
        }

        if ($status === LeadStatus::New->value) {
            return;
        }

        $log(ActivityType::Called, $createdAt->copy()->addDays(1), [], $assignee);
        $log(ActivityType::StatusChanged, $createdAt->copy()->addDays(1), ['from' => 'new', 'to' => 'contacted'], $assignee);

        if (random_int(0, 1)) {
            $log(ActivityType::WhatsappClicked, $createdAt->copy()->addDays(1)->addHours(2), [], $assignee);
        }

        if ($assignee && random_int(0, 2) > 0) {
            $noteAt = $createdAt->copy()->addDays(2);

            LeadNote::create([
                'tenant_id' => $this->tenant->id,
                'lead_id' => $lead->id,
                'user_id' => $assignee->id,
                'body' => DemoContent::NOTES[array_rand(DemoContent::NOTES)],
                'created_at' => $noteAt,
                'updated_at' => $noteAt,
            ]);

            $log(ActivityType::NoteAdded, $noteAt, [], $assignee);
        }

        if (in_array($status, [LeadStatus::Qualified->value, LeadStatus::Appointment->value, LeadStatus::Won->value], true)) {
            $log(ActivityType::StatusChanged, $createdAt->copy()->addDays(3), ['from' => 'contacted', 'to' => 'qualified'], $assignee);
        }

        if (in_array($status, [LeadStatus::Appointment->value, LeadStatus::Won->value], true)) {
            $log(ActivityType::AppointmentCreated, $createdAt->copy()->addDays(5), [], $assignee);
        }

        if ($status === LeadStatus::Won->value) {
            $log(ActivityType::Won, $lead->won_at, [], $assignee);
        }

        if ($status === LeadStatus::Lost->value) {
            $log(ActivityType::Lost, $lead->lost_at, ['reason' => $lead->lostReason?->label], $assignee);
        }
    }

    /**
     * Follow-ups are spread on purpose: a demo where nothing is overdue hides
     * the feature that sells the product.
     */
    private function scheduleFollowUp(Lead $lead, ?User $assignee, string $status, $createdAt, int $index): void
    {
        if (! $assignee || in_array($status, [LeadStatus::Won->value, LeadStatus::Lost->value], true)) {
            return;
        }

        // Deterministic spread by position rather than a dice roll, so every run
        // populates all three buckets. Weighted so overdue is clearly visible —
        // it is the problem the product solves — without making the demo team
        // look negligent: roughly today > upcoming > overdue.
        $bucket = $index % 12;

        // "Today" means later today, measured from now, so the demo's three
        // figures do not shift depending on the hour the seeder ran.
        $remainingToday = max(2, (int) now()->diffInMinutes(now()->endOfDay()));

        $dueAt = match (true) {
            $bucket < 2 => now()->subDays(random_int(1, 5))->setTime(random_int(9, 17), 0),       // overdue
            $bucket < 7 => now()->addMinutes(random_int(1, $remainingToday)),                     // today
            $bucket < 11 => now()->addDays(random_int(1, 10))->setTime(random_int(9, 17), 0),     // upcoming
            default => null,
        };

        if ($dueAt === null) {
            return;
        }

        FollowUp::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'assigned_to' => $assignee->id,
            'created_by' => $assignee->id,
            'due_at' => $dueAt,
            'note' => DemoContent::FOLLOW_UP_NOTES[array_rand(DemoContent::FOLLOW_UP_NOTES)],
            'status' => FollowUp::PENDING,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $lead->forceFill(['next_follow_up_at' => $dueAt])->saveQuietly();
    }
}
