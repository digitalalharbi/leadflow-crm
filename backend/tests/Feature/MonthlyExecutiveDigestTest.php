<?php

namespace Tests\Feature;

use App\Domain\Access\AccessResolver;
use App\Domain\Digest\DigestComposer;
use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\NotificationPrefs;
use App\Enums\Role;
use App\Models\EmailDelivery;
use App\Models\Lead;
use App\Models\PermissionRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The monthly executive digest: the month that has just ended against the one before, for the person
 * who answers for the whole workspace — on the first of the month, at eight where the workspace is,
 * once, and without money for somebody who may not see it. Rendered right to left, with no raw keys.
 */
class MonthlyExecutiveDigestTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Month Co');
        $this->t['tenant']->settings->forceFill(['timezone' => 'Asia/Riyadh'])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function compose(string $kind, $user): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(DigestComposer::class)->compose($kind, $user));
    }

    private function deliveries()
    {
        return EmailDelivery::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->where('kind', 'like', 'digest.%')->orderBy('id')->get();
    }

    public function test_it_is_one_of_the_four_summaries_and_only_the_admin_gets_it_by_default(): void
    {
        $this->assertContains('monthly_executive', AlertKinds::DIGESTS);
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->assertTrue(NotificationPrefs::for($this->t['admin'])['digests']['monthly_executive']);
        $this->assertFalse(NotificationPrefs::for($manager)['digests']['monthly_executive']);
        $this->assertFalse(NotificationPrefs::for($rep)['digests']['monthly_executive']);

        $saved = $this->actingAs($manager)->patchJson('/api/v1/auth/notification-preferences', ['digests' => ['monthly_executive' => true, 'not_a_digest' => true]])->assertOk()->json('data');
        $this->assertTrue($saved['digests']['monthly_executive']);
        $this->assertArrayNotHasKey('not_a_digest', $saved['digests']);
        $this->assertContains('monthly_executive', $saved['digest_kinds']);
    }

    public function test_it_covers_the_month_that_just_ended_with_the_funnel_the_money_the_team_and_the_automation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 05:30:00', 'UTC'));

        $this->asTenant($this->t['tenant'], function () {
            foreach (range(1, 4) as $i) {
                $lead = Lead::create(['full_name' => "عميل {$i}", 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => $i === 1 ? 'won' : 'contacted', 'first_contact_at' => now(), 'last_contact_at' => now(), 'deal_value_minor' => $i === 1 ? 90_000_000 : null, 'won_at' => $i === 1 ? '2026-11-20 10:00:00' : null]);
                DB::table('leads')->where('id', $lead->id)->update(['created_at' => '2026-11-10 12:00:00']);
            }
            $old = Lead::create(['full_name' => 'قديم', 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => 'contacted']);
            DB::table('leads')->where('id', $old->id)->update(['created_at' => '2026-10-10 12:00:00']);
        });

        $digest = $this->compose('monthly_executive', $this->t['admin']);

        $this->assertSame('monthly_executive', $digest['kind']);
        $this->assertSame('الملخص التنفيذي الشهري', $digest['title']);
        $this->assertSame('2026-11-01 → 2026-11-30', $digest['period'], 'the calendar month that has just ended');

        $stats = collect($digest['stats'])->keyBy('label');
        foreach (['عملاء جدد', 'مؤهلون', 'مهتمون', 'زاروا', 'حجزوا', 'اشتروا', 'الإيراد', 'الإنفاق', 'تكلفة العميل', 'تكلفة الاكتساب', 'العائد على الإنفاق', 'الرد في الوقت', 'نجاح الأتمتة'] as $label) {
            $this->assertTrue($stats->has($label), "the page has «{$label}»");
        }
        $this->assertSame(4, $stats['عملاء جدد']['value'], 'only November counts');
        $this->assertSame(300.0, (float) $stats['عملاء جدد']['change'], 'four against the one in October');
        $this->assertSame(1, $stats['اشتروا']['value']);
        $this->assertStringContainsString('900,000', $stats['الإيراد']['value']);
    }

    public function test_somebody_who_may_not_see_money_gets_a_page_without_it(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        foreach (['spend.view', 'revenue.view'] as $permission) {
            $this->asTenant($this->t['tenant'], fn () => PermissionRule::create(['layer' => 'role', 'role_key' => 'manager', 'permission' => $permission, 'effect' => 'deny']));
        }
        AccessResolver::flush();

        $labels = collect($this->compose('monthly_executive', $manager->fresh())['stats'])->pluck('label')->all();

        $this->assertContains('عملاء جدد', $labels);
        $this->assertContains('نجاح الأتمتة', $labels);
        foreach (['الإيراد', 'الإنفاق', 'تكلفة العميل', 'تكلفة الاكتساب', 'العائد على الإنفاق'] as $money) {
            $this->assertNotContains($money, $labels, "no «{$money}» for somebody without it");
        }
    }

    public function test_it_goes_out_on_the_first_at_eight_where_the_workspace_is_once_to_those_who_chose_it(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);

        // The first of the month, 08:00 in Riyadh. Not a Sunday: no weekly pair.
        Carbon::setTestNow(Carbon::parse('2026-12-01 05:00:00', 'UTC'));
        Artisan::call('leadflow:send-digests', ['--tenant' => $this->t['tenant']->slug]);

        $monthly = $this->deliveries()->where('kind', 'digest.monthly_executive');
        $this->assertSame([$this->t['admin']->email], $monthly->pluck('to_email')->all(), 'the admin only: the manager did not choose it');
        $this->assertSame('logged', $monthly->first()->status, 'the log provider never says sent');
        $this->assertNotContains('digest.weekly_executive', $this->deliveries()->pluck('kind')->all(), 'a Tuesday has no weekly page');

        // Run again in the same hour: nothing more.
        Artisan::call('leadflow:send-digests', ['--tenant' => $this->t['tenant']->slug]);
        $this->assertCount(1, $this->deliveries()->where('kind', 'digest.monthly_executive'));

        // The manager chooses it: the next first-of-the-month reaches them too.
        $this->actingAs($manager)->patchJson('/api/v1/auth/notification-preferences', ['digests' => ['monthly_executive' => true]])->assertOk();
        EmailDelivery::withoutGlobalScopes()->delete();
        DB::table('alert_marks')->where('key', 'like', 'digest:%')->delete();
        Artisan::call('leadflow:send-digests', ['--tenant' => $this->t['tenant']->slug]);
        $this->assertEqualsCanonicalizing([$this->t['admin']->email, $manager->email], $this->deliveries()->where('kind', 'digest.monthly_executive')->pluck('to_email')->all());

        // The second of the month, the wrong hour: nothing.
        EmailDelivery::withoutGlobalScopes()->delete();
        DB::table('alert_marks')->where('key', 'like', 'digest:%')->delete();
        Carbon::setTestNow(Carbon::parse('2026-12-02 05:00:00', 'UTC'));
        Artisan::call('leadflow:send-digests', ['--tenant' => $this->t['tenant']->slug]);
        $this->assertCount(0, $this->deliveries()->where('kind', 'digest.monthly_executive'));

        Carbon::setTestNow(Carbon::parse('2026-12-01 06:00:00', 'UTC'));
        EmailDelivery::withoutGlobalScopes()->delete();
        Artisan::call('leadflow:send-digests', ['--tenant' => $this->t['tenant']->slug]);
        $this->assertCount(0, $this->deliveries()->where('kind', 'digest.monthly_executive'), 'nine in the morning is not eight');
    }

    public function test_a_manual_run_can_ask_for_the_monthly_page_alone(): void
    {
        Artisan::call('leadflow:send-digests', ['--now' => true, '--kind' => 'monthly', '--tenant' => $this->t['tenant']->slug]);

        $this->assertSame(['digest.monthly_executive'], $this->deliveries()->pluck('kind')->unique()->values()->all());
    }

    public function test_the_email_is_right_to_left_names_the_month_and_leaks_no_raw_keys(): void
    {
        Artisan::call('leadflow:send-digests', ['--now' => true, '--kind' => 'monthly', '--tenant' => $this->t['tenant']->slug]);
        $mail = $this->deliveries()->first()->makeVisible('html');

        $this->assertSame('الملخص التنفيذي الشهري', $mail->subject);
        $this->assertStringContainsString('dir="rtl"', $mail->html);
        $this->assertStringContainsString('الملخص التنفيذي الشهري', $mail->html);
        $this->assertStringContainsString('عملاء جدد', $mail->html);
        $this->assertStringContainsString('افتح مهتم', $mail->html);
        $this->assertStringContainsString('الملف الشخصي', $mail->html, 'it says how to stop it');
        $this->assertDoesNotMatchRegularExpression('/\{\{|\}\}|@(if|foreach|section)|\$digest|monthly_executive/', $mail->html);
        $this->assertSame($this->t['admin']->email, $mail->to_email);
    }
}
