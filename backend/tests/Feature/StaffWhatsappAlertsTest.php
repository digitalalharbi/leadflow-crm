<?php

namespace Tests\Feature;

use App\DataTransferObjects\SentMessage;
use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\StaffWhatsapp;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Jobs\SendStaffWhatsappAlert;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\StaffAlertLog;
use App\Models\User;
use App\Notifications\Channels\StaffWhatsappChannel;
use App\Notifications\ImportantNotification;
use App\Services\Whatsapp\MockWhatsappProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WhatsApp alerts to staff: optional, the person's own choice, critical categories only — held back
 * (and the reason written down) in quiet hours, past the rate limit, for a repeat, without a phone or
 * a connected number; every change to the choice on the audit trail.
 */
class StaffWhatsappAlertsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private User $rep;

    /** @var object{sent:list<array{to:string,body:string}>} */
    private object $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Staff Co');
        $this->t['tenant']->settings->forceFill(['timezone' => 'Asia/Riyadh'])->save();
        $this->rep = $this->makeUser($this->t['tenant'], Role::Sales, ['phone' => '+966551234567']);
        config(['leadflow_alerts.whatsapp.per_hour' => 6, 'leadflow_alerts.whatsapp.per_day' => 20]);

        $this->provider = new class extends MockWhatsappProvider
        {
            /** @var list<array{to:string,body:string}> */
            public array $sent = [];

            public function sendText($integration, string $toPhone, string $body): SentMessage
            {
                $this->sent[] = ['to' => $toPhone, 'body' => $body];

                return parent::sendText($integration, $toPhone, $body);
            }
        };
        $this->app->instance(MockWhatsappProvider::class, $this->provider);
        $this->connect();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function connect(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::create(['provider' => IntegrationProvider::Whatsapp, 'name' => 'WhatsApp', 'status' => Integration::CONNECTED, 'config' => ['phone_number_id' => '100200300'], 'credentials' => [], 'is_active' => true]));
    }

    private function optIn(?User $user = null, array $extra = []): void
    {
        $this->actingAs($user ?? $this->rep)->patchJson('/api/v1/auth/notification-preferences', ['whatsapp' => ['enabled' => true] + $extra])->assertOk();
    }

    private function hot(string $leadId = 'lead-1', string $kind = 'hot_lead'): void
    {
        $this->rep->fresh()->notify(new ImportantNotification($kind, 'عميل ساخن', "سلمان — {$leadId}", $leadId, null, 'critical'));
    }

    private function log()
    {
        return StaffAlertLog::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->orderBy('id')->get();
    }

    // ------------------------------------------------------------ the choice

    public function test_it_is_off_until_the_person_switches_it_on_and_the_six_are_the_critical_ones(): void
    {
        $prefs = $this->actingAs($this->rep)->getJson('/api/v1/auth/notification-preferences')->assertOk()->json('data.whatsapp');

        $this->assertFalse($prefs['enabled']);
        $this->assertSame(AlertKinds::STAFF_WHATSAPP, $prefs['categories']);
        $this->assertEqualsCanonicalizing(['hot_lead', 'visit_requested', 'reservation_intent', 'sla_breach', 'high_value', 'automation_failure'], $prefs['categories']);
        $this->assertSame(['instant', 'off'], $prefs['modes']);
        $this->assertTrue($prefs['has_phone']);
        $this->assertTrue($prefs['available'], 'the workspace has a WhatsApp number');
        // What a rep hears at once by email is what they hear here once it is on; the rest is off.
        $this->assertSame('instant', $prefs['alerts']['hot_lead']);
        $this->assertSame('off', $prefs['alerts']['automation_failure']);

        $this->hot();
        $this->assertCount(0, $this->log(), 'nothing is sent to somebody who did not ask');
        $this->assertSame([], $this->provider->sent);
    }

    public function test_the_choice_is_saved_validated_and_audited(): void
    {
        $this->optIn(null, ['alerts' => ['automation_failure' => 'instant', 'hot_lead' => 'off', 'tracking_failure' => 'instant'], 'quiet' => ['enabled' => true, 'start' => '23:00', 'end' => '06:30']]);

        $prefs = $this->actingAs($this->rep)->getJson('/api/v1/auth/notification-preferences')->json('data.whatsapp');
        $this->assertTrue($prefs['enabled']);
        $this->assertSame('instant', $prefs['alerts']['automation_failure']);
        $this->assertSame('off', $prefs['alerts']['hot_lead']);
        $this->assertArrayNotHasKey('tracking_failure', $prefs['alerts'], 'not one of the six');
        $this->assertSame(['enabled' => true, 'start' => '23:00', 'end' => '06:30', 'source' => 'own'], $prefs['quiet']);

        $this->actingAs($this->rep)->patchJson('/api/v1/auth/notification-preferences', ['whatsapp' => ['alerts' => ['hot_lead' => 'daily']]])->assertStatus(422);
        $this->actingAs($this->rep)->patchJson('/api/v1/auth/notification-preferences', ['whatsapp' => ['quiet' => ['enabled' => true, 'start' => '25:99']]])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'notification_prefs.whatsapp_changed', 'user_id' => $this->rep->id]);
        $audit = AuditLog::withoutGlobalScopes()->where('action', 'notification_prefs.whatsapp_changed')->latest('id')->first();
        $this->assertStringNotContainsString('551234567', json_encode($audit->changes), 'the number is not in the trail');

        // Back to the workspace's own window.
        $this->actingAs($this->rep)->patchJson('/api/v1/auth/notification-preferences', ['whatsapp' => ['quiet' => null]])->assertOk();
        $this->assertSame('workspace', $this->actingAs($this->rep)->getJson('/api/v1/auth/notification-preferences')->json('data.whatsapp.quiet.source'));
    }

    public function test_changing_one_edge_of_the_quiet_hours_keeps_the_other_as_it_is_in_force(): void
    {
        // Starting from the workspace's window (21:00 → 08:00): moving the start keeps its end.
        $this->t['tenant']->settings->forceFill(['quiet_hours_enabled' => true, 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:00'])->save();
        $this->optIn(null, ['quiet' => ['enabled' => true, 'start' => '23:00']]);
        $quiet = $this->actingAs($this->rep)->getJson('/api/v1/auth/notification-preferences')->json('data.whatsapp.quiet');
        $this->assertSame(['enabled' => true, 'start' => '23:00', 'end' => '08:00', 'source' => 'own'], $quiet);

        // Then the end: the start stays.
        $this->actingAs($this->rep)->patchJson('/api/v1/auth/notification-preferences', ['whatsapp' => ['quiet' => ['enabled' => true, 'end' => '06:30']]])->assertOk();
        $quiet = $this->actingAs($this->rep)->getJson('/api/v1/auth/notification-preferences')->json('data.whatsapp.quiet');
        $this->assertSame(['enabled' => true, 'start' => '23:00', 'end' => '06:30', 'source' => 'own'], $quiet);

        // Switching it off keeps the window they set.
        $this->actingAs($this->rep)->patchJson('/api/v1/auth/notification-preferences', ['whatsapp' => ['quiet' => ['enabled' => false]]])->assertOk();
        $quiet = $this->actingAs($this->rep)->getJson('/api/v1/auth/notification-preferences')->json('data.whatsapp.quiet');
        $this->assertSame(['enabled' => false, 'start' => '23:00', 'end' => '06:30', 'source' => 'own'], $quiet);
    }

    public function test_a_channel_is_added_only_for_the_six_and_only_for_somebody_with_a_phone(): void
    {
        $this->optIn();
        $via = fn (string $kind, ?User $u = null) => (new ImportantNotification($kind, 't', 'b', 'x', null, 'warning'))->via($u ?? $this->rep->fresh());

        $this->assertContains(StaffWhatsappChannel::class, $via('hot_lead'));
        $this->assertContains(StaffWhatsappChannel::class, $via('appointment_requested'));
        // Tracking and budget are for the email and the tray, not the phone.
        $this->assertNotContains(StaffWhatsappChannel::class, $via('tracking_failure'));
        $this->assertNotContains(StaffWhatsappChannel::class, $via('budget_anomaly'));
        // Not for another person, and never Laravel's own channels.
        $this->assertNotContains(StaffWhatsappChannel::class, $via('hot_lead', $this->makeUser($this->t['tenant'], Role::Sales, ['phone' => '+966559999999'])));
        $this->assertNotContains('mail', $via('hot_lead'));

        $this->rep->forceFill(['phone' => null])->save();
        $this->assertNotContains(StaffWhatsappChannel::class, $via('hot_lead'));
    }

    // -------------------------------------------------------------- sending

    public function test_a_critical_alert_reaches_the_phone_with_the_title_the_sentence_and_the_link_and_is_logged(): void
    {
        $this->optIn();
        $this->hot('abc-123');

        $this->assertCount(1, $this->provider->sent);
        $this->assertSame('966551234567', $this->provider->sent[0]['to'], 'the number as WhatsApp wants it');
        $this->assertStringContainsString('*عميل ساخن*', $this->provider->sent[0]['body']);
        $this->assertStringContainsString('سلمان', $this->provider->sent[0]['body']);
        $this->assertStringContainsString('/leads/abc-123', $this->provider->sent[0]['body']);

        $row = $this->log()->first();
        $this->assertSame('sent', $row->status);
        $this->assertSame('hot_lead', $row->category);
        $this->assertStringStartsWith('wamid.mock.', $row->provider_message_id);
        $this->assertSame('عميل ساخن', $row->title);
        $this->assertNotNull($row->sent_at);
        $this->assertSame($this->rep->id, $row->user_id);
    }

    public function test_the_same_thing_is_told_once_and_the_repeat_is_logged_as_a_duplicate(): void
    {
        $this->optIn();
        $this->hot('same-lead');
        $this->hot('same-lead');
        $this->hot('other-lead');

        $this->assertSame(['sent', 'skipped', 'sent'], $this->log()->pluck('status')->all());
        $this->assertSame('duplicate', $this->log()[1]->reason);
        $this->assertCount(2, $this->provider->sent);
    }

    public function test_the_hourly_limit_holds_the_rest_back_and_says_so(): void
    {
        config(['leadflow_alerts.whatsapp.per_hour' => 2]);
        $this->optIn();

        foreach (['a', 'b', 'c', 'd'] as $lead) {
            $this->hot($lead);
        }

        $this->assertSame(['sent', 'sent', 'skipped', 'skipped'], $this->log()->pluck('status')->all());
        $this->assertSame(['rate_limited', 'rate_limited'], $this->log()->slice(2)->pluck('reason')->values()->all());
        $this->assertCount(2, $this->provider->sent);

        // An hour later the person has their share back.
        Carbon::setTestNow(now()->addMinutes(61));
        $this->hot('e');
        $this->assertSame('sent', $this->log()->last()->status);
    }

    public function test_the_daily_limit_holds_too(): void
    {
        config(['leadflow_alerts.whatsapp.per_hour' => 50, 'leadflow_alerts.whatsapp.per_day' => 3]);
        $this->optIn();

        foreach (range(1, 5) as $i) {
            $this->hot("lead-{$i}");
        }

        $this->assertSame(3, $this->log()->where('status', 'sent')->count());
        $this->assertSame(['rate_limited'], $this->log()->where('status', 'skipped')->pluck('reason')->unique()->values()->all());
    }

    public function test_quiet_hours_hold_it_back_their_own_or_the_workspaces(): void
    {
        // 23:30 in Riyadh.
        Carbon::setTestNow(Carbon::parse('2026-12-01 20:30:00', 'UTC'));

        // Their own window, 22:00 → 07:00.
        $this->optIn(null, ['quiet' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00']]);
        $this->hot('night-1');
        $this->assertSame('quiet_hours', $this->log()->last()->reason);
        $this->assertSame([], $this->provider->sent);

        // Outside it, it goes.
        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'UTC'));
        $this->hot('day-1');
        $this->assertSame('sent', $this->log()->last()->status);

        // With no window of their own, the workspace's protects them.
        $this->actingAs($this->rep)->patchJson('/api/v1/auth/notification-preferences', ['whatsapp' => ['quiet' => null]])->assertOk();
        $this->t['tenant']->settings->forceFill(['quiet_hours_enabled' => true, 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:00'])->save();
        Carbon::setTestNow(Carbon::parse('2026-12-01 20:30:00', 'UTC'));
        $this->hot('night-2');
        $this->assertSame('quiet_hours', $this->log()->last()->reason);

        // The email and the tray are untouched by any of it.
        $this->assertSame(3, DB::table('notifications')->where('notifiable_id', $this->rep->id)->count());
    }

    public function test_without_a_phone_or_a_connected_number_it_is_held_back_with_the_reason(): void
    {
        $alerts = app(StaffWhatsapp::class);
        $ask = fn (User $u, string $key) => $this->asTenant($this->t['tenant'], fn () => $alerts->queue($u, 'hot_lead', 'عميل ساخن', 'نص', $key));

        $nobody = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->assertSame('no_phone', $ask($nobody, 'k1')->reason);

        $bad = $this->makeUser($this->t['tenant'], Role::Sales, ['phone' => 'abc']);
        $this->assertSame('no_phone', $ask($bad, 'k2')->reason, 'a number that is not a number is no phone');

        $this->asTenant($this->t['tenant'], fn () => Integration::query()->delete());
        $this->assertSame('not_connected', $ask($this->rep, 'k3')->reason);
        $this->assertSame([], $this->provider->sent);
    }

    public function test_a_number_whatsapp_cannot_reach_is_a_failure_on_the_record_not_a_silent_loss(): void
    {
        $unreachable = $this->makeUser($this->t['tenant'], Role::Sales, ['phone' => '+966500000012']);
        $this->optIn($unreachable);
        $unreachable->fresh()->notify(new ImportantNotification('hot_lead', 'عميل ساخن', 'نص', 'u-1', null, 'critical'));

        $row = $this->log()->last();
        $this->assertSame('failed', $row->status);
        $this->assertSame('undeliverable', $row->reason);
        $this->assertStringContainsString('131026', $row->error);
        $this->assertSame(1, $row->attempts);
    }

    public function test_each_person_reads_only_their_own_log_and_another_workspace_never_appears(): void
    {
        $this->optIn();
        $this->hot('mine');
        $other = $this->makeUser($this->t['tenant'], Role::Sales, ['phone' => '+966553333333']);
        $this->optIn($other);
        $other->fresh()->notify(new ImportantNotification('hot_lead', 'عميل ساخن', 'نص', 'theirs', null, 'critical'));

        $mine = $this->actingAs($this->rep)->getJson('/api/v1/auth/notification-preferences/whatsapp-log')->assertOk()->json('data.log');
        $this->assertCount(1, $mine);
        $this->assertSame('sent', $mine[0]['status']);
        $this->assertArrayNotHasKey('body', $mine[0]);

        $else = $this->provisionTenant('Else Co');
        $this->assertSame([], $this->actingAs($else['admin'])->getJson('/api/v1/auth/notification-preferences/whatsapp-log')->json('data.log'));
        $this->assertSame(2, StaffAlertLog::withoutGlobalScopes()->count());
    }

    public function test_a_failure_worth_another_try_waits_on_the_backoff(): void
    {
        $this->app->instance(MockWhatsappProvider::class, new class extends MockWhatsappProvider
        {
            public function sendText($integration, string $toPhone, string $body): SentMessage
            {
                return SentMessage::failed('503', 'Service unavailable');
            }
        });
        Bus::fake();
        $alerts = app(StaffWhatsapp::class);
        $log = $this->asTenant($this->t['tenant'], fn () => $alerts->queue($this->rep, 'hot_lead', 'عميل ساخن', 'نص', 'retry-1'));
        $this->assertSame('queued', $log->status);

        $after = $this->asTenant($this->t['tenant'], fn () => $alerts->attempt($log->id, 'نص'));
        $this->assertSame('queued', $after->status, 'it waits to be tried again');
        $this->assertSame(1, $after->attempts);
        $this->assertStringContainsString('503', $after->error);
        Bus::assertDispatchedTimes(SendStaffWhatsappAlert::class, 2);
    }
}
