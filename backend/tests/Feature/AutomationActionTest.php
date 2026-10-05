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
            'phone_normalized' => '+9665003'.random_int(10000, 99999),
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
        $this->assertStringContainsString('no owner', strtolower($result['description']));
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
}
