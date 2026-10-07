<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Notifications\AutomationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The whole claim, in one test: a rep opens "اعمل الآن", does one thing, and
 * the system does the rest.
 *
 * Every piece below is covered somewhere on its own. None of that proves the
 * path holds end to end, which is the only thing a customer experiences — and
 * the failures found while writing these were all in the joins rather than the
 * parts: an outcome that reached automation as nothing, a rule that ran twice,
 * a stage change no report could see, a lead worked daily that read as
 * untouched.
 *
 * So this asserts consequences, not calls. It goes through HTTP as the rep
 * does, and it does not reach inside anything it is testing.
 */
class RepWorkPathAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Acceptance Co');

        $this->rep = User::create([
            'tenant_id' => $this->t['tenant']->id,
            'name' => 'مندوب المبيعات', 'email' => 'rep@acceptance.test',
            'password' => bcrypt('secret'), 'role' => 'sales', 'is_active' => true,
        ]);
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'سعود الغامدي',
            'phone_normalized' => '+96650010'.random_int(1000, 9999),
            'status' => 'new',
            'assigned_to' => $this->rep->id,
            'platform' => 'meta',
            'campaign_name' => 'حملة النرجس',
            'score' => 60,
        ], $attributes)));
    }

    /**
     * The headline path. One POST, and every consequence the product promises.
     */
    public function test_one_recorded_outcome_moves_everything_it_should(): void
    {
        Notification::fake();

        $rule = $this->asTenant($this->t['tenant'], fn () => AutomationRule::create([
            'name' => 'العميل المهتم يُنبَّه عليه',
            'trigger' => 'lead.outcome_recorded',
            'conditions' => [['field' => 'last_outcome', 'operator' => 'equals', 'value' => 'interested']],
            'actions' => [['type' => 'notify', 'audience' => 'managers', 'message' => 'عميل مهتم — تابعه اليوم.']],
            'is_enabled' => true,
        ]));

        $lead = $this->lead();
        $this->lead(['full_name' => 'عميل آخر']); // so there is a next one to hand over

        $response = $this->actingAs($this->rep)
            ->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'interested'])
            ->assertOk();

        $fresh = $lead->fresh();

        // The stage moved, and every timestamp that proves it moved with it.
        $this->assertSame(LeadStatus::Qualified, $fresh->status);
        $this->assertNotNull($fresh->first_contact_at, 'contact must be stamped');
        $this->assertNotNull($fresh->qualified_at, 'the funnel and velocity charts read this');
        $this->assertTrue($fresh->last_contact_at->isToday(), 'the lead was worked today');

        // What the rep did is on the record, in their name.
        $activity = LeadActivity::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->where('type', ActivityType::QualificationUpdated->value)
            ->first();
        $this->assertNotNull($activity, 'the outcome must be logged as an activity');
        $this->assertSame($this->rep->id, $activity->user_id);

        // The next step is booked rather than remembered.
        $followUp = FollowUp::withoutGlobalScopes()->where('lead_id', $lead->id)->first();
        $this->assertNotNull($followUp, 'an interested lead gets a follow-up');
        $this->assertSame('pending', $followUp->status);
        $this->assertNotNull($fresh->next_follow_up_at);

        // The rule ran, once, and the alert says which rule and what it was told
        // to say.
        $this->assertSame(1, AutomationRun::withoutGlobalScopes()
            ->where('automation_rule_id', $rule->id)->where('outcome', 'applied')->count());

        Notification::assertSentTo(
            $this->t['admin'],
            AutomationNotification::class,
            function (AutomationNotification $n) {
                $payload = $n->toDatabase($this->t['admin']);

                return $payload['rule'] === 'العميل المهتم يُنبَّه عليه'
                    && $payload['body'] === 'عميل مهتم — تابعه اليوم.';
            },
        );

        // And the rep is handed the next lead in the same response, never the
        // one they just worked.
        $next = $response->json('data.next.lead');
        $this->assertNotNull($next, 'the rep must not have to ask for the next lead');
        $this->assertNotSame($lead->uuid, $next['id']);

        // The rep is told what happened, in words.
        $this->assertNotEmpty($response->json('data.recorded'));
    }

    /**
     * The most common outcome on a call list, and the one that used to reach
     * the rules as nothing at all.
     */
    public function test_an_outcome_that_moves_no_stage_still_does_everything_else(): void
    {
        $rule = $this->asTenant($this->t['tenant'], fn () => AutomationRule::create([
            'name' => 'بعد عدم الرد',
            'trigger' => 'lead.outcome_recorded',
            'conditions' => [['field' => 'last_outcome', 'operator' => 'equals', 'value' => 'no_answer']],
            'actions' => [['type' => 'add_note', 'body' => 'حاولنا الاتصال دون رد.']],
            'is_enabled' => true,
        ]));

        $lead = $this->lead(['status' => 'contacted']);
        $lead->forceFill(['last_contact_at' => now()->subDays(5)])->saveQuietly();

        $this->actingAs($this->rep)
            ->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'no_answer'])
            ->assertOk()
            ->assertJsonPath('data.stage_changed', false);

        $this->assertTrue($lead->fresh()->last_contact_at->isToday(), 'an attempt is still work');

        $this->assertSame(1, AutomationRun::withoutGlobalScopes()
            ->where('automation_rule_id', $rule->id)->where('outcome', 'applied')->count());

        $this->assertNotNull(
            FollowUp::withoutGlobalScopes()->where('lead_id', $lead->id)->first(),
            'a no-answer is reattempted rather than forgotten',
        );
    }

    /** A sale closed from the queue is a sale every report can see. */
    public function test_a_sale_recorded_from_the_queue_is_visible_to_the_reports(): void
    {
        $lead = $this->lead(['status' => 'negotiation']);

        $this->actingAs($this->rep)
            ->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'won'])
            ->assertOk();

        $fresh = $lead->fresh();

        $this->assertSame(LeadStatus::Won, $fresh->status);
        $this->assertNotNull($fresh->won_at, 'every sales report counts won_at');
        $this->assertNotNull($fresh->qualified_at, 'the velocity chart measures to_win from this');
        $this->assertNull($fresh->lost_at);
    }

    /** Closing a lead stops the chasing; nobody should be reminded to call it. */
    public function test_closing_a_lead_stops_its_pending_work(): void
    {
        $lead = $this->lead(['status' => 'contacted']);

        $this->actingAs($this->rep)
            ->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'no_answer'])
            ->assertOk();

        $this->assertSame(1, FollowUp::withoutGlobalScopes()
            ->where('lead_id', $lead->id)->where('status', 'pending')->count());

        $this->actingAs($this->rep)
            ->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'not_interested'])
            ->assertOk();

        $this->assertSame(0, FollowUp::withoutGlobalScopes()
            ->where('lead_id', $lead->id)->where('status', 'pending')->count());
    }

    /** A rep never works, or even sees, another rep's lead. */
    public function test_a_rep_cannot_record_against_a_lead_that_is_not_theirs(): void
    {
        $other = User::create([
            'tenant_id' => $this->t['tenant']->id,
            'name' => 'مندوب آخر', 'email' => 'rep2@acceptance.test',
            'password' => bcrypt('secret'), 'role' => 'sales', 'is_active' => true,
        ]);

        $lead = $this->lead(['assigned_to' => $other->id]);

        $this->actingAs($this->rep)
            ->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'interested'])
            ->assertForbidden();
    }
}
