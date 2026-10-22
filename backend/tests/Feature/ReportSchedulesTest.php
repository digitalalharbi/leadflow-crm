<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportSchedules;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\EmailDelivery;
use App\Models\ReportSchedule;
use App\Models\ReportShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Reports that send themselves: daily, weekly on a weekday, monthly on a day — at an hour of the
 * workspace's own clock, to a list of people, as a link; once per run, never for a closed report.
 */
class ReportSchedulesTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $share;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Schedule Co');
        $this->t['tenant']->settings->forceFill(['timezone' => 'Asia/Riyadh'])->save();
        // The clock is frozen weeks ahead: the subscription must outlast it.
        $this->t['tenant']->update(['subscription_status' => 'active', 'trial_ends_at' => null, 'subscription_ends_at' => '2030-01-01 00:00:00']);
        // Tuesday 2026-12-01, 08:00 in Riyadh.
        Carbon::setTestNow(Carbon::parse('2026-12-01 05:00:00', 'UTC'));
        $this->share = $this->actingAs($this->t['admin'])->postJson('/api/v1/report-shares', ['name' => 'تقرير الإدارة', 'preset' => 'executive_management', 'password' => 'سر-1234'])->assertCreated()->json('data.share');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function schedule(array $data = [], $as = null)
    {
        return $this->actingAs($as ?? $this->t['admin'])->postJson("/api/v1/report-shares/{$this->share['id']}/schedules", $data + ['frequency' => 'daily', 'recipients' => ['owner@example.test']]);
    }

    private function deliveries()
    {
        return EmailDelivery::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->where('subject_type', 'report_schedule')->orderBy('id')->get();
    }

    private function runNow(): int
    {
        return $this->asTenant($this->t['tenant'], fn () => app(ReportSchedules::class)->runDue());
    }

    // ------------------------------------------------------------ making one

    public function test_a_schedule_is_made_for_daily_weekly_or_monthly_and_says_when_it_next_runs(): void
    {
        // 08:00 Riyadh today has just struck: a daily one at eight is tomorrow; at nine, today.
        $this->assertSame('2026-12-02T05:00:00+00:00', $this->schedule(['frequency' => 'daily', 'send_hour' => 8])->assertCreated()->json('data.schedule.next_run_at'));
        $this->assertSame('2026-12-01T06:00:00+00:00', $this->schedule(['frequency' => 'daily', 'send_hour' => 9])->assertCreated()->json('data.schedule.next_run_at'));
        // Friday, 4 December.
        $this->assertSame('2026-12-04T05:00:00+00:00', $this->schedule(['frequency' => 'weekly', 'weekday' => 5, 'send_hour' => 8])->assertCreated()->json('data.schedule.next_run_at'));
        // The 1st at eight has struck: January.
        $this->assertSame('2027-01-01T05:00:00+00:00', $this->schedule(['frequency' => 'monthly', 'month_day' => 1, 'send_hour' => 8])->assertCreated()->json('data.schedule.next_run_at'));
        $this->assertSame('2026-12-15T05:00:00+00:00', $this->schedule(['frequency' => 'monthly', 'month_day' => 15, 'send_hour' => 8])->assertCreated()->json('data.schedule.next_run_at'));

        $listed = $this->actingAs($this->t['admin'])->getJson("/api/v1/report-shares/{$this->share['id']}/schedules")->assertOk()->json('data.schedules');
        $this->assertCount(5, $listed);

        // And the report's own listing says how it sends itself.
        $share = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/report-shares')->json('data.shares'))->firstWhere('id', $this->share['id']);
        $this->assertSame(['daily', 'daily', 'weekly', 'monthly', 'monthly'], array_column($share['schedules'], 'frequency'));
        $this->assertSame(1, $share['schedules'][0]['recipients']);
    }

    public function test_it_is_validated_and_bounded(): void
    {
        $this->schedule(['frequency' => 'hourly'])->assertStatus(422);
        $this->schedule(['frequency' => 'weekly'])->assertStatus(422);
        $this->schedule(['frequency' => 'weekly', 'weekday' => 8])->assertStatus(422);
        $this->schedule(['frequency' => 'monthly'])->assertStatus(422);
        $this->schedule(['frequency' => 'monthly', 'month_day' => 31])->assertStatus(422);
        $this->schedule(['send_hour' => 24])->assertStatus(422);
        $this->schedule(['recipients' => []])->assertStatus(422);
        $this->schedule(['recipients' => ['not-an-email']])->assertStatus(422);
        $this->schedule(['recipients' => array_map(fn ($i) => "r{$i}@example.test", range(1, 21))])->assertStatus(422);

        // Duplicates and case are one recipient.
        $made = $this->schedule(['recipients' => ['A@example.test', 'a@example.test', 'b@example.test']])->assertCreated()->json('data.schedule');
        $this->assertSame(['a@example.test', 'b@example.test'], $made['recipients']);

        foreach (range(1, 4) as $_) {
            $this->schedule()->assertCreated();
        }
        $this->schedule()->assertStatus(422);
    }

    public function test_it_can_be_changed_paused_and_deleted_and_each_is_on_the_trail(): void
    {
        $id = $this->schedule(['frequency' => 'daily'])->assertCreated()->json('data.schedule.id');

        $weekly = $this->actingAs($this->t['admin'])->patchJson("/api/v1/report-schedules/{$id}", ['frequency' => 'weekly', 'weekday' => 1, 'send_hour' => 7])->assertOk()->json('data.schedule');
        $this->assertSame('weekly', $weekly['frequency']);
        $this->assertSame('2026-12-07T04:00:00+00:00', $weekly['next_run_at'], 'Monday the 7th, seven in Riyadh');

        $paused = $this->actingAs($this->t['admin'])->patchJson("/api/v1/report-schedules/{$id}", ['enabled' => false])->assertOk()->json('data.schedule');
        $this->assertFalse($paused['enabled']);
        $this->assertNull($paused['next_run_at']);

        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/report-schedules/{$id}")->assertOk();
        $this->assertDatabaseMissing('report_schedules', ['uuid' => $id]);

        foreach (['report_share.schedule_created', 'report_share.schedule_updated', 'report_share.schedule_deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
        $trail = AuditLog::withoutGlobalScopes()->where('action', 'report_share.schedule_created')->first();
        $this->assertSame(1, $trail->changes['recipients'], 'a count, not the addresses');
    }

    // ------------------------------------------------------------ sending

    public function test_a_due_schedule_queues_the_link_to_each_person_once_and_never_the_numbers_or_the_password(): void
    {
        $this->schedule(['frequency' => 'daily', 'send_hour' => 9, 'recipients' => ['a@example.test', 'b@example.test'], 'message' => "<b>مرفق</b> التقرير الشهري\nمع التحية"]);

        $this->assertSame(0, $this->runNow(), 'not due yet');

        Carbon::setTestNow(Carbon::parse('2026-12-01 06:00:30', 'UTC'));
        $this->assertSame(1, $this->runNow());

        $mails = $this->deliveries()->each->makeVisible('html');
        $this->assertSame(['a@example.test', 'b@example.test'], $mails->pluck('to_email')->all());
        $this->assertSame(['logged'], $mails->pluck('status')->unique()->values()->all(), 'the log provider never says sent');

        $html = $mails[0]->html;
        $this->assertStringContainsString($this->share['url'], $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('تقرير الإدارة', $html);
        $this->assertStringContainsString('محميّ بكلمة مرور', $html, 'the reader is told a password is needed');
        $this->assertStringNotContainsString('سر-1234', $html, 'never the password');
        $this->assertStringNotContainsString('<b>مرفق</b>', $html, 'the sender’s words are escaped');
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringNotContainsString('exec_marketing', $html);

        // It has moved on to tomorrow, and the same day is not sent twice.
        $schedule = ReportSchedule::withoutGlobalScopes()->first();
        $this->assertSame('sent', $schedule->last_status);
        $this->assertSame('2026-12-02T06:00:00+00:00', $schedule->next_run_at->toIso8601String());
        $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();
        $this->runNow();
        $this->assertCount(2, $this->deliveries(), 'the same person, the same report, the same day: one email');
    }

    public function test_a_weekly_and_a_monthly_run_on_their_own_days(): void
    {
        $this->schedule(['frequency' => 'weekly', 'weekday' => 5, 'send_hour' => 8]);
        $this->schedule(['frequency' => 'monthly', 'month_day' => 15, 'send_hour' => 8, 'recipients' => ['m@example.test']]);

        Carbon::setTestNow(Carbon::parse('2026-12-04 05:00:10', 'UTC'));
        $this->assertSame(1, $this->runNow(), 'Friday: the weekly one');
        $this->assertSame(['owner@example.test'], $this->deliveries()->pluck('to_email')->all());

        // A week later the weekly one is on its day again, so it is simply on time.
        Carbon::setTestNow(Carbon::parse('2026-12-11 05:00:10', 'UTC'));
        $this->assertSame(1, $this->runNow());
        $this->assertCount(2, $this->deliveries());

        // The 15th: the monthly one, and only it (the weekly one is next due on the 18th).
        Carbon::setTestNow(Carbon::parse('2026-12-15 05:00:10', 'UTC'));
        $this->assertSame(1, $this->runNow());
        $this->assertContains('m@example.test', $this->deliveries()->pluck('to_email')->all());
        $this->assertCount(3, $this->deliveries());

        // A weekly schedule that was missed for several weeks sends once when it is next looked at — not once per missed week.
        Carbon::setTestNow(Carbon::parse('2027-01-20 05:00:10', 'UTC'));
        $this->assertSame(2, $this->runNow(), 'the weekly one that is overdue, and the monthly one');
        $this->assertCount(5, $this->deliveries());

        $weekly = ReportSchedule::withoutGlobalScopes()->where('frequency', 'weekly')->first();
        $this->assertSame('2027-01-22T05:00:00+00:00', $weekly->next_run_at->toIso8601String(), 'the next Friday after the catch-up');
        $monthly = ReportSchedule::withoutGlobalScopes()->where('frequency', 'monthly')->first();
        $this->assertSame('2027-02-15T05:00:00+00:00', $monthly->next_run_at->toIso8601String());
    }

    public function test_a_report_that_was_closed_stops_its_schedule_instead_of_mailing_a_dead_link(): void
    {
        $id = $this->schedule(['frequency' => 'daily', 'send_hour' => 9])->assertCreated()->json('data.schedule.id');
        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$this->share['id']}/revoke")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-12-01 06:00:30', 'UTC'));
        $this->runNow();

        $this->assertCount(0, $this->deliveries());
        $schedule = ReportSchedule::withoutGlobalScopes()->where('uuid', $id)->first();
        $this->assertFalse($schedule->enabled);
        $this->assertSame('stopped', $schedule->last_status);
        $this->assertNull($schedule->next_run_at);

        // Turning it on again needs an open report.
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/report-schedules/{$id}", ['enabled' => true])->assertStatus(422);
        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$this->share['id']}/regenerate")->assertOk();
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/report-schedules/{$id}", ['enabled' => true])->assertOk()->assertJsonPath('data.schedule.enabled', true);
    }

    public function test_an_expired_report_is_closed_too(): void
    {
        $this->schedule(['frequency' => 'daily', 'send_hour' => 9]);
        ReportShare::withoutGlobalScopes()->whereKey(ReportShare::withoutGlobalScopes()->first()->id)->update(['expires_at' => now()->subHour()]);

        Carbon::setTestNow(Carbon::parse('2026-12-01 06:00:30', 'UTC'));
        $this->runNow();

        $this->assertCount(0, $this->deliveries());
        $this->assertSame('stopped', ReportSchedule::withoutGlobalScopes()->first()->last_status);
    }

    public function test_the_command_runs_every_workspace_and_never_crosses_them(): void
    {
        $this->schedule(['frequency' => 'daily', 'send_hour' => 9, 'recipients' => ['mine@example.test']]);

        $other = $this->provisionTenant('Else Co');
        $other['tenant']->settings->forceFill(['timezone' => 'Asia/Riyadh'])->save();
        $theirs = $this->actingAs($other['admin'])->postJson('/api/v1/report-shares', ['name' => 'لهم', 'preset' => 'client'])->assertCreated()->json('data.share');
        $this->actingAs($other['admin'])->postJson("/api/v1/report-shares/{$theirs['id']}/schedules", ['frequency' => 'daily', 'send_hour' => 9, 'recipients' => ['theirs@example.test']])->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-12-01 06:00:30', 'UTC'));
        Artisan::call('leadflow:send-report-schedules');

        $this->assertSame(['mine@example.test'], $this->deliveries()->pluck('to_email')->all());
        $this->assertSame(['theirs@example.test'], EmailDelivery::withoutGlobalScopes()->where('tenant_id', $other['tenant']->id)->pluck('to_email')->all());

        // Only one workspace when asked.
        Carbon::setTestNow(Carbon::parse('2026-12-02 06:00:30', 'UTC'));
        EmailDelivery::withoutGlobalScopes()->delete();
        Artisan::call('leadflow:send-report-schedules', ['--tenant' => $other['tenant']->slug]);
        $this->assertCount(0, $this->deliveries());
        $this->assertCount(1, EmailDelivery::withoutGlobalScopes()->where('tenant_id', $other['tenant']->id)->get());
    }

    // ------------------------------------------------------------ who

    public function test_only_those_who_make_reports_manage_their_schedules_and_workspaces_never_cross(): void
    {
        $id = $this->schedule()->assertCreated()->json('data.schedule.id');

        foreach ([Role::Sales, Role::ClientViewer, Role::BrokerViewer] as $role) {
            $who = $this->makeUser($this->t['tenant'], $role);
            $this->actingAs($who)->getJson("/api/v1/report-shares/{$this->share['id']}/schedules")->assertForbidden();
            $this->schedule([], $who)->assertForbidden();
            $this->actingAs($who)->patchJson("/api/v1/report-schedules/{$id}", ['enabled' => false])->assertForbidden();
            $this->actingAs($who)->deleteJson("/api/v1/report-schedules/{$id}")->assertForbidden();
        }

        $this->schedule([], $this->makeUser($this->t['tenant'], Role::Manager))->assertCreated();

        $other = $this->provisionTenant('Else');
        $this->actingAs($other['admin'])->getJson("/api/v1/report-shares/{$this->share['id']}/schedules")->assertNotFound();
        $this->actingAs($other['admin'])->patchJson("/api/v1/report-schedules/{$id}", ['enabled' => false])->assertNotFound();
        $this->actingAs($other['admin'])->deleteJson("/api/v1/report-schedules/{$id}")->assertNotFound();
        $this->assertDatabaseHas('report_schedules', ['uuid' => $id]);
    }

    public function test_deleting_a_report_takes_its_schedules_with_it(): void
    {
        $this->schedule();
        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/report-shares/{$this->share['id']}")->assertOk();

        $this->assertSame(0, ReportSchedule::withoutGlobalScopes()->count());
    }
}
