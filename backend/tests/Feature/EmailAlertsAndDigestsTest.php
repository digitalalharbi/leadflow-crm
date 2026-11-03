<?php

namespace Tests\Feature;

use App\Domain\Digest\DigestComposer;
use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\NotificationPrefs;
use App\Enums\Role;
use App\Models\AdConnection;
use App\Models\EmailDelivery;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\Channels\ProviderMailChannel;
use App\Notifications\Channels\TenantDatabaseChannel;
use App\Notifications\ImportantNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Email alerts at once for the few things worth interrupting for, and three summaries — each to
 * the people who chose it, once, at eight in the morning where the workspace is. Every one of them
 * is a delivery through the product's own door (App\Domain\Mail), never Laravel's mailer.
 */
class EmailAlertsAndDigestsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private User $manager;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alerts Co');
        $this->manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $this->rep = $this->makeUser($this->t['tenant'], Role::Sales);
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create($attributes + [
            'full_name' => 'سلمان', 'phone_normalized' => '+9665512'.self::uniqueDigits(5), 'status' => 'contacted', 'assigned_to' => $this->rep->id,
        ]));
    }

    private function channelsFor(User $user, string $kind): array
    {
        return (new ImportantNotification($kind, 't', 'b', null, null, 'warning'))->via($user);
    }

    // ----------------------------------------------------------- preferences

    public function test_defaults_follow_what_a_person_does_and_can_be_changed(): void
    {
        $rep = NotificationPrefs::for($this->rep);
        $this->assertSame('instant', $rep['alerts']['hot_lead']);
        $this->assertSame('off', $rep['alerts']['tracking_failure'], 'a rep is not woken by a pixel');
        $this->assertTrue($rep['digests']['daily_sales']);
        $this->assertFalse($rep['digests']['weekly_marketing']);

        $manager = NotificationPrefs::for($this->manager);
        $this->assertSame('instant', $manager['alerts']['tracking_failure']);
        $this->assertSame('daily', $manager['alerts']['high_value']);
        $this->assertTrue($manager['digests']['weekly_marketing']);
        $this->assertFalse($manager['digests']['weekly_executive']);
        $this->assertTrue(NotificationPrefs::for($this->t['admin'])['digests']['weekly_executive']);

        $saved = $this->actingAs($this->rep)->patchJson('/api/v1/auth/notification-preferences', [
            'alerts' => ['hot_lead' => 'daily', 'high_value' => 'weekly', 'not_a_category' => 'instant'],
            'digests' => ['weekly_marketing' => true],
        ])->assertOk()->json('data');

        $this->assertSame('daily', $saved['alerts']['hot_lead']);
        $this->assertSame('weekly', $saved['alerts']['high_value']);
        $this->assertSame('instant', $saved['alerts']['visit_requested'], 'untouched settings keep their default');
        $this->assertTrue($saved['digests']['weekly_marketing']);
        $this->assertArrayNotHasKey('not_a_category', $saved['alerts']);
        $this->assertSame(AlertKinds::CATEGORIES, $saved['categories']);

        // The screen is told whether the door really sends: the log provider does not; a configured SMTP does.
        $this->assertFalse($this->actingAs($this->rep)->getJson('/api/v1/auth/notification-preferences')->json('data.mail_configured'));
        config(['leadflow_mail.provider' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.username' => 'user']);
        $this->assertTrue($this->actingAs($this->rep)->getJson('/api/v1/auth/notification-preferences')->json('data.mail_configured'));
        config(['leadflow_mail.provider' => 'log']);

        $this->actingAs($this->rep)->patchJson('/api/v1/auth/notification-preferences', ['alerts' => ['hot_lead' => 'sometimes']])->assertStatus(422);
        $this->assertSame('daily', $this->actingAs($this->rep)->getJson('/api/v1/auth/notification-preferences')->json('data.alerts.hot_lead'));
    }

    // ------------------------------------------------------------ instant mail

    public function test_an_alert_is_emailed_at_once_only_to_those_who_chose_instant(): void
    {
        $this->assertContains(ProviderMailChannel::class, $this->channelsFor($this->rep, 'hot_lead'));

        NotificationPrefs::save($this->rep, ['alerts' => ['hot_lead' => 'daily']]);
        $this->assertNotContains(ProviderMailChannel::class, $this->channelsFor($this->rep->fresh(), 'hot_lead'));

        NotificationPrefs::save($this->rep, ['alerts' => ['hot_lead' => 'off']]);
        $this->assertNotContains(ProviderMailChannel::class, $this->channelsFor($this->rep->fresh(), 'hot_lead'));

        // Always kept in the app, whatever the email setting.
        $this->assertContains(TenantDatabaseChannel::class, $this->channelsFor($this->rep->fresh(), 'hot_lead'));

        // In no category: never an email.
        $this->assertNotContains(ProviderMailChannel::class, $this->channelsFor($this->manager, 'not_a_kind'));
        // And never Laravel's own mail channel, whatever the setting.
        $this->assertNotContains('mail', $this->channelsFor($this->rep->fresh(), 'hot_lead'));
    }

    public function test_every_alert_category_has_a_notification_that_belongs_to_it(): void
    {
        foreach (AlertKinds::CATEGORIES as $category) {
            $this->assertNotEmpty(AlertKinds::kindsOf($category), "{$category} has no kind");
        }

        $this->assertSame('sla_breach', AlertKinds::categoryOf('interest_sla_breached'));
        $this->assertSame('visit_requested', AlertKinds::categoryOf('appointment_requested'));
        $this->assertNull(AlertKinds::categoryOf('something_else'));
    }

    public function test_the_email_is_right_to_left_names_the_thing_and_links_to_the_lead(): void
    {
        $html = (string) (new ImportantNotification('hot_lead', 'عميل ساخن', 'سلمان أصبح ساخنًا — اتصل به الآن.', 'abc-lead', null, 'critical'))->toMail($this->rep)->render();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('عميل ساخن', $html);
        $this->assertStringContainsString('سلمان أصبح ساخنًا', $html);
        $this->assertStringContainsString('/leads/abc-lead', $html);
        $this->assertStringContainsString('الملف الشخصي', $html, 'it says how to stop it');
    }

    // ---------------------------------------------------------------- producers

    public function test_a_lead_turning_hot_and_a_reservation_request_alert_the_owner_once(): void
    {
        Notification::fake();

        $lead = $this->lead();
        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $lead->forceFill(['temperature' => 'hot'])->save();
            $lead->forceFill(['temperature' => 'warm'])->save();
            $lead->forceFill(['temperature' => 'hot'])->save();
            $lead->forceFill(['reservation_intent' => true])->save();
        });

        $kinds = fn () => collect(Notification::sent($this->rep, ImportantNotification::class))->pluck('kind')->all();

        $this->assertSame(1, collect($kinds())->filter(fn ($k) => $k === 'hot_lead')->count(), 'told once, however often it flips');
        $this->assertContains('reservation_intent', $kinds());
    }

    public function test_a_connected_platform_that_stopped_delivering_alerts_the_managers_once_a_day(): void
    {
        $this->asTenant($this->t['tenant'], fn () => AdConnection::create(['platform' => 'meta', 'credentials' => ['access_token' => 'tok'], 'is_active' => true]));
        Notification::fake();

        Artisan::call('leadflow:watch-tracking');
        Artisan::call('leadflow:watch-tracking');

        Notification::assertSentToTimes($this->t['admin'], ImportantNotification::class, 1);
        Notification::assertSentToTimes($this->manager, ImportantNotification::class, 1);
        Notification::assertNotSentTo($this->rep, ImportantNotification::class);
        Notification::assertSentTo($this->manager, ImportantNotification::class, fn ($n) => $n->kind === 'tracking_failure' && $n->severity === 'critical');
    }

    // ------------------------------------------------------------------ digests

    /** The digests queued as deliveries — the only way a summary leaves — newest run only. */
    private function deliveries()
    {
        return EmailDelivery::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->where('kind', 'like', 'digest.%')->orderBy('id')->get();
    }

    private function digests(string $kind): array
    {
        EmailDelivery::withoutGlobalScopes()->delete();
        Artisan::call('leadflow:send-digests', ['--now' => true, '--kind' => $kind, '--tenant' => $this->t['tenant']->slug]);

        $who = fn (EmailDelivery $d) => match ($d->to_email) {
            $this->t['admin']->email => 'admin', $this->manager->email => 'manager', $this->rep->email => 'rep', default => 'other',
        };

        return $this->deliveries()->groupBy($who)->map(fn ($rows) => $rows->map(fn (EmailDelivery $d) => substr($d->kind, 7))->all())->all();
    }

    public function test_each_digest_goes_to_the_people_who_chose_it_and_only_once_a_day(): void
    {
        $daily = $this->digests('daily');
        $this->assertSame(['daily_sales'], $daily['admin']);
        $this->assertSame(['daily_sales'], $daily['manager']);
        $this->assertSame(['daily_sales'], $daily['rep']);

        // Through the door, honestly: the log provider writes them down and says so.
        $this->assertSame(['logged'], $this->deliveries()->pluck('status')->unique()->values()->all());
        $this->assertSame(['user'], $this->deliveries()->pluck('subject_type')->unique()->values()->all());

        // A second run the same day sends nothing more.
        Artisan::call('leadflow:send-digests', ['--now' => true, '--kind' => 'daily', '--tenant' => $this->t['tenant']->slug]);
        $this->assertCount(3, $this->deliveries());

        NotificationPrefs::save($this->rep, ['digests' => ['daily_sales' => false]]);
        DB::table('alert_marks')->where('key', 'like', 'digest:%')->delete();
        $this->assertArrayNotHasKey('rep', $this->digests('daily'), 'switched off means nothing arrives');
    }

    public function test_weekly_marketing_and_executive_follow_their_defaults_and_choices(): void
    {
        $weekly = $this->digests('weekly');

        $this->assertEqualsCanonicalizing(['weekly_marketing', 'weekly_executive'], $weekly['admin']);
        $this->assertSame(['weekly_marketing'], $weekly['manager']);
        $this->assertArrayNotHasKey('rep', $weekly);
    }

    public function test_a_digest_is_sent_only_in_the_hour_that_is_eight_where_the_workspace_is(): void
    {
        $this->t['tenant']->settings->forceFill(['timezone' => 'Asia/Riyadh'])->save();
        // Sunday 08:00 in Riyadh is 05:00 UTC.
        $sunday = Carbon::parse('2026-11-01 05:00:00', 'UTC');
        $this->assertSame(0, $sunday->copy()->setTimezone('Asia/Riyadh')->dayOfWeek);

        EmailDelivery::withoutGlobalScopes()->delete();
        Carbon::setTestNow($sunday->copy()->addHour());
        Artisan::call('leadflow:send-digests', ['--tenant' => $this->t['tenant']->slug]);
        $this->assertCount(0, $this->deliveries());

        Carbon::setTestNow($sunday);
        Artisan::call('leadflow:send-digests', ['--tenant' => $this->t['tenant']->slug]);
        $kinds = $this->deliveries()->map(fn (EmailDelivery $d) => substr($d->kind, 7))->unique()->values()->all();
        // Sunday the 1st: the day's, the week's two, and the month's.
        $this->assertEqualsCanonicalizing(['daily_sales', 'weekly_marketing', 'weekly_executive', 'monthly_executive'], $kinds);

        // A Monday morning: the daily one only.
        EmailDelivery::withoutGlobalScopes()->delete();
        DB::table('alert_marks')->where('key', 'like', 'digest:%')->delete();
        Carbon::setTestNow($sunday->copy()->addDay());
        Artisan::call('leadflow:send-digests', ['--tenant' => $this->t['tenant']->slug]);
        $this->assertSame(['daily_sales'], $this->deliveries()->map(fn (EmailDelivery $d) => substr($d->kind, 7))->unique()->values()->all());

        Carbon::setTestNow();
    }

    public function test_alerts_set_to_daily_ride_in_the_digest_and_arrive_alone_when_there_is_none(): void
    {
        // The manager hears about high-value leads in the daily summary.
        $this->manager->notify(new ImportantNotification('high_value_lead', 'عميل عالي القيمة', 'سلمان — ميزانية كبيرة', null, null, 'warning'));
        $this->manager->notify(new ImportantNotification('hot_lead', 'عميل ساخن', 'فوري — لا يدخل الملخص', null, null, 'critical'));

        $compose = fn (User $u) => $this->asTenant($this->t['tenant'], fn () => app(DigestComposer::class)->rollup($u, 'daily', now()->subDay()));
        $this->assertSame(['عميل عالي القيمة'], collect($compose($this->manager))->pluck('title')->all());

        EmailDelivery::withoutGlobalScopes()->delete();
        Artisan::call('leadflow:send-digests', ['--now' => true, '--kind' => 'daily', '--tenant' => $this->t['tenant']->slug]);
        $mail = $this->deliveries()->first(fn (EmailDelivery $d) => $d->to_email === $this->manager->email)->makeVisible('html');
        $this->assertSame('digest.daily_sales', $mail->kind);
        $this->assertStringContainsString('عميل عالي القيمة', $mail->html);
        $this->assertStringNotContainsString('عميل ساخن', $mail->html, 'an instant alert does not ride in the summary');

        // Without a summary chosen, the roll-up is mailed on its own.
        NotificationPrefs::save($this->manager, ['digests' => ['daily_sales' => false]]);
        DB::table('alert_marks')->where('key', 'like', 'digest:%')->delete();
        EmailDelivery::withoutGlobalScopes()->delete();
        Artisan::call('leadflow:send-digests', ['--now' => true, '--kind' => 'daily', '--tenant' => $this->t['tenant']->slug]);
        $alone = $this->deliveries()->first(fn (EmailDelivery $d) => $d->to_email === $this->manager->email);
        $this->assertSame('digest.alerts_daily', $alone->kind);
        $this->assertStringContainsString('عميل عالي القيمة', $alone->makeVisible('html')->html);
    }

    public function test_the_daily_sales_digest_counts_what_is_waiting_for_that_person(): void
    {
        $mine = $this->lead(['full_name' => 'يضيع منهم']);
        $theirs = $this->lead(['assigned_to' => $this->manager->id, 'full_name' => 'ليس لي']);
        foreach ([$mine, $theirs] as $lead) {
            DB::table('leads')->where('id', $lead->id)->update(['interest_level' => 'hot', 'interest_level_changed_at' => now()->subHours(3)]);
        }

        $compose = fn (User $u) => $this->asTenant($this->t['tenant'], fn () => app(DigestComposer::class)->compose('daily_sales', $u));
        $stats = fn (User $u) => collect($compose($u)['stats'])->pluck('value', 'label');

        $this->assertSame(1, $stats($this->rep)['عملاء مهتمون بانتظار الرد']);
        $this->assertSame(1, $stats($this->rep)['تجاوز مهلة الرد']);
        $this->assertSame(2, $stats($this->t['admin'])['عملاء مهتمون بانتظار الرد'], 'a manager sees everyone');
        $this->assertSame(['يضيع منهم — اهتمام مرتفع'], $compose($this->rep)['lists'][0]['items']);
    }
}
