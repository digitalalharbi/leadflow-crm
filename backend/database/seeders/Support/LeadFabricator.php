<?php

namespace Database\Seeders\Support;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadNote;
use App\Models\LeadSourceEvent;
use App\Models\Tenant;
use App\Models\User;
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

    public function __construct(
        private Tenant $tenant,
        private Collection $assignees,
        private Collection $projects,
        private Collection $lostReasons,
        int $phoneSeed = 1000,
    ) {
        $this->phoneCounter = $phoneSeed;
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
        $campaign = DemoContent::CAMPAIGNS[array_rand(DemoContent::CAMPAIGNS)];
        $project = $this->projects->random();

        // A few fresh leads stay unassigned so the "awaiting assignment" card and
        // the manager's distribution workflow both have something to show.
        $assignee = ($status === LeadStatus::New->value && $index % 4 === 0)
            ? null
            : $this->assignees->random();

        $createdAt = now()->subDays(random_int(0, $daysBack))->subHours(random_int(0, 23));
        $phone = '+96655'.str_pad((string) (++$this->phoneCounter), 7, '0', STR_PAD_LEFT);
        $score = $this->scoreFor($status);
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
            'temperature' => LeadTemperature::fromScore($score)->value,
            'score' => $score,
            'budget_min' => $budgetMin,
            'budget_max' => (int) ($budgetMin * 1.4),
            'purchase_purpose' => random_int(0, 1) ? 'residence' : 'investment',
            'payment_method' => random_int(0, 2) === 0 ? 'cash' : 'financing',
            'preferred_property_type' => DemoContent::PROPERTY_TYPES[array_rand(DemoContent::PROPERTY_TYPES)],
            'preferred_location' => $project->location,
            'purchase_timeline' => DemoContent::TIMELINES[array_rand(DemoContent::TIMELINES)],
            'provider' => 'generic_webhook',
            'provider_lead_id' => 'demo-'.Str::random(10),
            'assigned_at' => $assignee ? $createdAt->copy()->addMinutes(random_int(1, 120)) : null,
            'last_contact_at' => $status === LeadStatus::New->value ? null : $createdAt->copy()->addDays(random_int(1, 3)),
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

        $this->buildTimeline($lead, $assignee, $status, $createdAt);
        $this->scheduleFollowUp($lead, $assignee, $status, $createdAt, $index);

        return $lead;
    }

    /** Score bands that make each stage's temperature mix believable. */
    private function scoreFor(string $status): int
    {
        return match ($status) {
            LeadStatus::Won->value => random_int(78, 100),
            LeadStatus::Appointment->value => random_int(70, 95),
            LeadStatus::Qualified->value => random_int(55, 85),
            LeadStatus::Contacted->value => random_int(35, 70),
            LeadStatus::Lost->value => random_int(10, 55),
            default => random_int(20, 68),
        };
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
