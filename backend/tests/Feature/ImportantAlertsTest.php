<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionLog;
use App\Domain\Notifications\AlertGate;
use App\Domain\Notifications\ImportantAlerts;
use App\Enums\Role;
use App\Models\AutomationRule;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Alerts for the few things worth interrupting for — each sent once, to the
 * person who can act, and never repeated.
 */
class ImportantAlertsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alerts Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'خالد الشمري', 'phone' => '0551234567',
            'phone_normalized' => '+9665512'.self::uniqueDigits(5), 'status' => 'new',
        ], $attributes)));
    }

    /** @return Collection<int,DatabaseNotification> */
    private function important(User $user)
    {
        return DatabaseNotification::where('notifiable_id', $user->id)->get()->filter(fn ($n) => ($n->data['type'] ?? null) === 'important');
    }

    public function test_a_lead_asking_for_an_appointment_alerts_its_owner_once(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $lead = $this->lead(['assigned_to' => $rep->id]);

        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['appointment_interest' => true])->save());
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['appointment_interest' => false])->save());
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['appointment_interest' => true])->save());

        $mine = $this->important($rep);
        $this->assertCount(1, $mine, 'told once, however often the flag flips');
        $this->assertSame('appointment_requested', $mine->first()->data['kind']);
        $this->assertSame($lead->uuid, $mine->first()->data['lead_id']);
        $this->assertCount(0, $this->important($this->t['admin']), 'not shown to people who cannot act');
    }

    public function test_an_unassigned_lead_alerts_the_managers_instead(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['appointment_interest' => true])->save());

        $this->assertCount(1, $this->important($this->t['admin']));
    }

    public function test_a_very_valuable_lead_is_flagged_on_arrival_and_an_ordinary_one_is_not(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->lead(['assigned_to' => $rep->id, 'budget_max' => 120_000]);
        $this->assertCount(0, $this->important($rep));

        $big = $this->lead(['assigned_to' => $rep->id, 'budget_max' => 2_500_000]);

        $this->assertSame(['high_value_lead'], $this->important($rep)->pluck('data.kind')->all());
        $this->assertSame($big->uuid, $this->important($rep)->first()->data['lead_id']);
    }

    public function test_an_alert_for_the_managers_never_reaches_another_workspaces_managers(): void
    {
        $rival = $this->provisionTenant('Rival Alerts Co');
        $lead = $this->lead(['full_name' => 'عميل سري للمنافس']);

        // An unassigned lead and a failing rule both go to "the managers": only this workspace's.
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['appointment_interest' => true])->save());
        $this->asTenant($this->t['tenant'], fn () => ImportantAlerts::automationFailed(null, 'قاعدة خاصة', 'فشل'));
        $this->asTenant($this->t['tenant'], fn () => ImportantAlerts::trackingFailure('meta', 'ميتا', 'توقف'));

        $this->assertGreaterThan(0, $this->important($this->t['admin'])->count(), 'its own managers are told');
        $this->assertCount(0, $this->important($rival['admin']), 'another workspace never hears of it');
    }

    public function test_a_failing_rule_is_one_message_a_day_to_the_managers_not_one_per_lead(): void
    {
        $rule = $this->asTenant($this->t['tenant'], fn () => AutomationRule::create([
            'name' => 'رسالة الترحيب', 'trigger' => 'lead.created', 'conditions' => [], 'actions' => [['type' => 'send_whatsapp']],
            'mode' => 'auto', 'is_enabled' => true, 'priority' => 100,
        ]));

        foreach (range(1, 5) as $i) {
            $lead = $this->lead();
            $this->asTenant($this->t['tenant'], fn () => app(ActionLog::class)->record($lead, $rule, 'lead.created', ['type' => 'send_whatsapp'], 'failed', ['description' => 'لم تُرسل الرسالة']));
        }

        $alerts = $this->important($this->t['admin'])->where('data.kind', 'automation_failed');
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('رسالة الترحيب', $alerts->first()->data['body']);
        $this->assertSame('/automation', $alerts->first()->data['link']);
    }

    public function test_a_gate_opens_once_per_key(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            $this->assertTrue(AlertGate::once('k:1'));
            $this->assertFalse(AlertGate::once('k:1'));
            $this->assertTrue(AlertGate::once('k:2'));
        });
    }

    public function test_follow_up_reminders_go_out_once_not_every_hour(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $lead = $this->lead(['assigned_to' => $rep->id, 'status' => 'contacted']);
        $this->asTenant($this->t['tenant'], function () use ($lead, $rep) {
            FollowUp::create(['lead_id' => $lead->id, 'assigned_to' => $rep->id, 'due_at' => now()->subDay(), 'status' => 'pending']);
            FollowUp::create(['lead_id' => $lead->id, 'assigned_to' => $rep->id, 'due_at' => now()->addMinutes(5), 'status' => 'pending']);
        });

        $this->artisan('leadflow:follow-up-reminders')->assertSuccessful();
        $first = DatabaseNotification::where('notifiable_id', $rep->id)->count();
        $this->artisan('leadflow:follow-up-reminders')->assertSuccessful();
        $this->artisan('leadflow:follow-up-reminders')->assertSuccessful();

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, DatabaseNotification::where('notifiable_id', $rep->id)->count(), 'hourly runs add nothing');
    }
}
