<?php

namespace Tests\Feature;

use App\Domain\Automation\AutomationEngine;
use App\Domain\Leads\OutcomeRecorder;
use App\Enums\ActivityType;
use App\Events\LeadCreated;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The lead's own screen is supposed to hold the whole account of what happened
 * to it, and two of the commonest things that happen were missing from it.
 *
 * `follow_up_created` was logged only by the follow-ups screen — which is not
 * where follow-ups come from. They come from the rep's outcome in the work
 * queue and from automation rules, so the story went straight from "no answer"
 * to a reminder that appeared from nowhere.
 *
 * And every automated change carried no user, so a stage moved by a rule read
 * exactly like a stage moved by a colleague. The first question anybody asks
 * about an automatic change is which rule did it, and the answer was only in
 * the automation log, matched to this one by eye on a timestamp.
 */
class AutomatedActionsAreVisibleTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Trace Co');
        $this->withoutJourney($this->t['tenant']);
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+96650012'.random_int(1000, 9999),
            'status' => 'contacted',
            'assigned_to' => $this->t['admin']->id,
            'score' => 80,
        ], $attributes)));
    }

    private function rule(array $actions, string $name = 'قاعدة المتابعة'): AutomationRule
    {
        return $this->asTenant($this->t['tenant'], fn () => AutomationRule::create([
            'name' => $name,
            'trigger' => 'lead.created',
            'conditions' => [],
            'actions' => $actions,
            'is_enabled' => true,
        ]));
    }

    private function fire(Lead $lead): void
    {
        $this->asTenant(
            $this->t['tenant'],
            fn () => app(AutomationEngine::class)->handle(new LeadCreated($lead)),
        );
    }

    private function activities(Lead $lead, ActivityType $type): Collection
    {
        return LeadActivity::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->where('type', $type->value)
            ->get();
    }

    public function test_a_follow_up_booked_by_a_rule_appears_in_the_story(): void
    {
        $this->rule([['type' => 'schedule_follow_up', 'in_hours' => 3, 'note' => 'عاود الاتصال']]);
        $lead = $this->lead();

        $this->fire($lead);

        $activity = $this->activities($lead, ActivityType::FollowUpCreated)->first();

        $this->assertNotNull($activity, 'the reminder must not appear from nowhere');
        $this->assertSame('عاود الاتصال', $activity->description);
        $this->assertNotNull($activity->meta['due_at'] ?? null);
    }

    public function test_a_follow_up_booked_from_the_work_queue_appears_in_the_story(): void
    {
        $lead = $this->lead();

        $this->asTenant(
            $this->t['tenant'],
            fn () => app(OutcomeRecorder::class)->record($lead, 'no_answer', $this->t['admin']),
        );

        $activity = $this->activities($lead, ActivityType::FollowUpCreated)->first();

        $this->assertNotNull($activity);
        $this->assertSame('work_queue', $activity->meta['via'] ?? null);
        // A rep's own action is theirs, and the story should say whose.
        $this->assertSame($this->t['admin']->id, $activity->user_id);
    }

    public function test_an_automated_change_says_which_rule_made_it(): void
    {
        $this->rule([['type' => 'change_stage', 'status' => 'qualified']], 'ترقية العملاء المهتمين');
        $lead = $this->lead();

        $this->fire($lead);

        $activity = $this->activities($lead, ActivityType::StatusChanged)->first();

        $this->assertNotNull($activity);
        $this->assertSame('automation', $activity->meta['by'] ?? null);
        $this->assertSame('ترقية العملاء المهتمين', $activity->meta['rule_name'] ?? null);
    }

    public function test_every_activity_a_rule_writes_is_marked_as_automatic(): void
    {
        $this->rule([
            ['type' => 'add_note', 'body' => 'ملاحظة آلية'],
            ['type' => 'add_tag', 'tag' => 'مهتم'],
            ['type' => 'schedule_follow_up', 'in_hours' => 2],
        ], 'قاعدة متعددة');

        $lead = $this->lead();
        $this->fire($lead);

        $written = LeadActivity::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->whereNull('user_id')
            ->get();

        $this->assertGreaterThanOrEqual(3, $written->count());

        foreach ($written as $activity) {
            $this->assertSame('automation', $activity->meta['by'] ?? null,
                "a {$activity->type->value} written by a rule must say so");
            $this->assertSame('قاعدة متعددة', $activity->meta['rule_name'] ?? null);
        }
    }

    /** A rule that stops the chase is the reason the reminders went quiet. */
    public function test_stopping_the_chase_is_recorded(): void
    {
        $lead = $this->lead();

        $this->asTenant(
            $this->t['tenant'],
            fn () => app(OutcomeRecorder::class)->record($lead, 'no_answer', $this->t['admin']),
        );

        $this->rule([['type' => 'stop_follow_ups']], 'إيقاف المطاردة');
        $this->fire($lead->fresh());

        $note = $this->activities($lead, ActivityType::NoteAdded)
            ->firstWhere(fn ($a) => str_contains((string) $a->description, 'أُوقفت'));

        $this->assertNotNull($note);
        $this->assertSame('إيقاف المطاردة', $note->meta['rule_name'] ?? null);
    }

    /** A person's own action is still theirs, not the system's. */
    public function test_a_rep_is_never_credited_to_automation(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$lead->uuid}/status", ['status' => 'qualified'])
            ->assertOk();

        $activity = $this->activities($lead, ActivityType::StatusChanged)->first();

        $this->assertSame($this->t['admin']->id, $activity->user_id);
        $this->assertNotSame('automation', $activity->meta['by'] ?? null);
    }
}
