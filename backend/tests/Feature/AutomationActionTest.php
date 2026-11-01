<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Enums\Role;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Notifications\AutomationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AutomationActionTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Actions Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+9665003'.self::uniqueDigits(5),
            'status' => 'new',
            'assigned_to' => $this->t['admin']->id,
        ], $attributes)));
    }

    private function fire(Lead $lead, array $action): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)->execute($lead, $action));
    }

    /**
     * A rule with a trigger and no conditions — "when a lead is won, stop
     * chasing it" — is the simplest useful rule there is. `required` on an
     * array rejects `[]`, so for a while it was the one rule you could not
     * write. Pinned here because the symptom is a 422 nobody reads carefully.
     */
    public function test_a_rule_with_no_conditions_can_be_created(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', [
                'name' => 'عند البيع، أوقف المتابعة',
                'trigger' => 'lead.won',
                'conditions' => [],
                'actions' => [['type' => 'stop_follow_ups']],
            ])
            ->assertCreated();
    }

    /** But the field still has to be sent, so a forgetful client is corrected. */
    public function test_omitting_conditions_entirely_is_still_refused(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', [
                'name' => 'ناقص',
                'trigger' => 'lead.won',
                'actions' => [['type' => 'stop_follow_ups']],
            ])
            ->assertStatus(422);
    }

    public function test_notify_reaches_the_lead_owner(): void
    {
        Notification::fake();
        $lead = $this->lead();

        $result = $this->fire($lead, ['type' => 'notify', 'audience' => 'owner', 'message' => 'اتصل الآن']);

        $this->assertTrue($result['ok']);
        Notification::assertSentTo($this->t['admin'], AutomationNotification::class);
    }

    /**
     * The failure this guards: a workspace believing it is being alerted when
     * nobody is listening. An unowned lead reports that, rather than
     * succeeding quietly having notified no one.
     */
    public function test_notifying_an_unowned_lead_reports_that_nobody_was_told(): void
    {
        Notification::fake();
        $lead = $this->lead(['assigned_to' => null]);

        $result = $this->fire($lead, ['type' => 'notify', 'audience' => 'owner']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('لا مسؤول', $result['description']);
        Notification::assertNothingSent();
    }

    public function test_notify_managers_reaches_admins_and_managers_only(): void
    {
        Notification::fake();
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $rep = $this->makeUser($this->t['tenant']);

        $this->fire($this->lead(), ['type' => 'notify', 'audience' => 'managers']);

        Notification::assertSentTo($manager, AutomationNotification::class);
        Notification::assertSentTo($this->t['admin'], AutomationNotification::class);
        Notification::assertNotSentTo($rep, AutomationNotification::class);
    }

    /** Another workspace's manager must never be told about this lead. */
    public function test_notify_managers_does_not_cross_tenants(): void
    {
        Notification::fake();
        $other = $this->provisionTenant('Elsewhere Co');

        $this->fire($this->lead(), ['type' => 'notify', 'audience' => 'managers']);

        Notification::assertNotSentTo($other['admin'], AutomationNotification::class);
    }

    public function test_stop_follow_ups_closes_the_open_ones(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], fn () => FollowUp::create([
            'lead_id' => $lead->id,
            'assigned_to' => $this->t['admin']->id,
            'created_by' => $this->t['admin']->id,
            'due_at' => now()->addDay(),
            'status' => FollowUp::PENDING,
        ]));

        $result = $this->fire($lead, ['type' => 'stop_follow_ups']);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, FollowUp::where('lead_id', $lead->id)->where('status', FollowUp::PENDING)->count());
    }

    public function test_stopping_nothing_is_not_an_error(): void
    {
        $result = $this->fire($this->lead(), ['type' => 'stop_follow_ups']);

        $this->assertTrue($result['ok']);
    }

    /**
     * Naming a rep is how a developer actually runs a project launch: every
     * lead from this campaign goes to نورة, who knows that project.
     *
     * The executor has supported it from the start and nothing could reach it —
     * the builder offered one strategy, and the API dropped `strategy` and
     * `user_id` because neither had a validation rule. So the capability
     * existed, was tested nowhere, and could not be used.
     */
    public function test_a_rule_can_assign_to_the_rep_it_names(): void
    {
        $rep = $this->makeUser($this->t['tenant']);
        $lead = $this->lead(['assigned_to' => null]);

        $result = $this->fire($lead, [
            'type' => 'assign',
            'strategy' => 'specific',
            'user_id' => $rep->uuid,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame($rep->id, $lead->fresh()->assigned_to);
    }

    /** A rep from another workspace is not somebody this rule may name. */
    public function test_a_rule_cannot_assign_to_a_rep_in_another_workspace(): void
    {
        $other = $this->provisionTenant('Somebody Else');
        $lead = $this->lead(['assigned_to' => null]);

        $result = $this->fire($lead, [
            'type' => 'assign',
            'strategy' => 'specific',
            'user_id' => $other['admin']->uuid,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertNull($lead->fresh()->assigned_to);
    }

    /** A rule outliving the rep it names must not park leads on a dead account. */
    public function test_a_rule_cannot_assign_to_a_deactivated_rep(): void
    {
        $rep = $this->makeUser($this->t['tenant']);
        $rep->forceFill(['is_active' => false])->save();

        $lead = $this->lead(['assigned_to' => null]);

        $result = $this->fire($lead, [
            'type' => 'assign',
            'strategy' => 'specific',
            'user_id' => $rep->uuid,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertNull($lead->fresh()->assigned_to);
    }

    /** A rep who has since been deleted leaves the lead where it was. */
    public function test_a_rule_naming_somebody_who_no_longer_exists_fails_loudly(): void
    {
        $lead = $this->lead(['assigned_to' => null]);

        $result = $this->fire($lead, [
            'type' => 'assign',
            'strategy' => 'specific',
            'user_id' => '00000000-0000-4000-8000-000000000000',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['description']);
        $this->assertNull($lead->fresh()->assigned_to);
    }
}
