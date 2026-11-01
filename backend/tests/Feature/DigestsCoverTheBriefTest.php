<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionLog;
use App\Domain\Digest\DigestComposer;
use App\Domain\Mail\DigestEmail;
use App\Domain\Notifications\ImportantAlerts;
use App\Domain\Notifications\NotificationPrefs;
use App\Enums\Role;
use App\Models\Anomaly;
use App\Models\AutomationRule;
use App\Models\Campaign;
use App\Models\EmailDelivery;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What each summary has to say, and that every figure opens the list it counts. The daily one is the
 * sales floor's morning; the weekly ones are marketing's and the executive's; the monthly one is the
 * month against the month before it. Short, visual, and every number a way into the product.
 */
class DigestsCoverTheBriefTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Brief Co');
        $this->rep = $this->makeUser($this->t['tenant'], Role::Sales);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function compose(string $kind, ?User $user = null): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(DigestComposer::class)->compose($kind, $user ?? $this->t['admin']));
    }

    /** @return array<string,array<string,mixed>> */
    private function stats(array $digest): array
    {
        return collect($digest['stats'])->keyBy('label')->all();
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($attributes) {
            $lead = Lead::create([
                'full_name' => 'عميل '.random_int(100, 999), 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'new',
                'assigned_to' => $this->rep->id,
            ]);
            // Dates, money and the machine's own columns are not mass-assignable: set the way the product sets them.
            if (($attributes['status'] ?? 'new') !== 'new') {
                $attributes += ['first_contact_at' => now()->subHours(5), 'last_contact_at' => now()->subHours(5)];
            }
            $lead->forceFill($attributes)->saveQuietly();

            return $lead->fresh();
        });
    }

    /** A campaign that was paid for and brought a lead that was won — so there is something to rank and attribute. */
    private function aCampaignThatPaidOff(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            $campaign = Campaign::create(['platform' => 'meta', 'name' => 'حملة الصيف', 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR']);
            MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $campaign->id, 'date' => now()->subDays(2)->toDateString(), 'spend_minor' => 100_000, 'impressions' => 1000, 'clicks' => 100, 'currency' => 'SAR']);

            foreach ([['status' => 'won', 'deal_value_minor' => 50_000_000, 'won_at' => now()->subDay()], ['status' => 'new', 'deal_value_minor' => null]] as $i => $attributes) {
                $lead = Lead::create(['full_name' => "عميل الحملة {$i}", 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'new']);
                DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $campaign->id, 'platform' => 'meta', 'created_at' => now()->subDay(), 'first_contact_at' => $attributes['status'] === 'won' ? now()->subDay() : null, 'last_contact_at' => $attributes['status'] === 'won' ? now()->subDay() : null] + $attributes);
            }
        });
    }

    private function listTitles(array $digest): array
    {
        return array_column($digest['lists'], 'title');
    }

    public function test_the_daily_digest_carries_the_whole_floor_and_each_figure_opens_its_list(): void
    {
        Carbon::setTestNow('2026-10-23 05:00:00');
        $waiting = $this->lead(['created_at' => now()->subHours(3)]);
        $this->lead(['created_at' => now()->subHours(4)]);
        $this->lead(['status' => 'contacted', 'first_contact_at' => now()->subHours(2), 'temperature' => 'hot', 'created_at' => now()->subDays(3)]);
        $this->asTenant($this->t['tenant'], fn () => $this->lead(['status' => 'won', 'won_at' => now()->subDay()->setTime(12, 0), 'deal_value_minor' => 90000000, 'created_at' => now()->subDays(9)]));
        $this->asTenant($this->t['tenant'], function () use ($waiting) {
            $rule = AutomationRule::create(['name' => 'رسالة الترحيب', 'trigger' => 'lead.created', 'conditions' => [], 'actions' => [['type' => 'send_whatsapp']], 'mode' => 'auto', 'is_enabled' => true, 'priority' => 100]);
            app(ActionLog::class)->record($waiting, $rule, 'lead.created', ['type' => 'send_whatsapp'], 'failed', ['description' => 'لم تُرسل']);
        });

        $stats = $this->stats($this->compose('daily_sales'));

        foreach (['عملاء الأمس', 'بانتظار أول تواصل', 'باهتمام مرتفع', 'عملاء مهتمون بانتظار الرد', 'تجاوز مهلة الرد', 'متابعات متأخرة', 'طلبات زيارة', 'زيارات اليوم', 'حجوزات بانتظار التأكيد', 'صفقات أمس', 'إيراد أمس', 'فشل الأتمتة'] as $label) {
            $this->assertArrayHasKey($label, $stats, "«{$label}» is missing from the morning summary");
            $this->assertStringStartsWith('/', (string) ($stats[$label]['link'] ?? ''), "«{$label}» opens nothing");
        }

        $this->assertSame(2, $stats['بانتظار أول تواصل']['value']);
        $this->assertSame(1, $stats['باهتمام مرتفع']['value']);
        $this->assertSame(1, $stats['صفقات أمس']['value']);
        $this->assertStringContainsString('900', (string) $stats['إيراد أمس']['value']);
        $this->assertSame(1, $stats['فشل الأتمتة']['value']);
        $this->assertSame('/leads?temperature=hot', $stats['باهتمام مرتفع']['link']);
        $this->assertSame('/leads?view=interested', $stats['عملاء مهتمون بانتظار الرد']['link']);
        $this->assertSame('/automation', $stats['فشل الأتمتة']['link']);
    }

    public function test_a_rep_is_not_shown_the_automations_workings_nor_the_workspaces_money(): void
    {
        $this->aCampaignThatPaidOff();
        $stats = $this->stats($this->compose('daily_sales', $this->rep));

        $this->assertArrayNotHasKey('فشل الأتمتة', $stats);
        $this->assertSame(0, $this->stats($this->compose('daily_sales', $this->rep))['صفقات أمس']['value'], 'a rep is told only of their own deals');
        $this->assertNotNull($this->compose('weekly_marketing')['stats'], 'while the admin has the whole summary');
        $this->assertArrayHasKey('بانتظار أول تواصل', $stats);
    }

    public function test_the_morning_lists_name_the_leads_and_open_each_one(): void
    {
        $lead = $this->lead(['created_at' => now()->subHours(3)]);
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['interest_level' => 'hot', 'interest_level_changed_at' => now()->subHours(6), 'status' => 'contacted', 'first_contact_at' => now()->subHours(5)])->saveQuietly());

        $digest = $this->compose('daily_sales');
        $first = collect($digest['lists'])->firstWhere('title', 'ابدأ بهؤلاء');

        $this->assertNotNull($first);
        $this->assertStringContainsString($lead->full_name, $first['items'][0]);
        $this->assertSame('/leads/'.$lead->uuid, $first['links'][0], 'it opens that lead, not a dashboard');
    }

    public function test_the_weekly_marketing_digest_carries_the_money_the_funnel_and_where_it_came_from(): void
    {
        $this->aCampaignThatPaidOff();
        $digest = $this->compose('weekly_marketing');
        $labels = array_keys($this->stats($digest));

        foreach (['الإنفاق', 'العملاء', 'مؤهلون', 'مهتمون', 'زيارات', 'حجوزات', 'صفقات', 'الإيراد', 'تكلفة العميل', 'تكلفة الاكتساب', 'العائد على الإنفاق'] as $label) {
            $this->assertContains($label, $labels, "«{$label}» is missing from the weekly marketing summary");
        }
        $this->assertContains('أفضل عائد', $this->listTitles($digest));
        $this->assertContains('الإسناد حسب المنصة', $this->listTitles($digest));
    }

    public function test_what_is_wrong_is_in_the_summary_and_what_is_fine_is_left_out(): void
    {
        $quiet = $this->compose('weekly_marketing');
        $this->assertNotContains('مشاكل الأتمتة', $this->listTitles($quiet), 'nothing failed, so nothing is listed');

        $this->asTenant($this->t['tenant'], function () {
            $lead = $this->lead();
            $rule = AutomationRule::create(['name' => 'قاعدة', 'trigger' => 'lead.created', 'conditions' => [], 'actions' => [['type' => 'send_whatsapp']], 'mode' => 'auto', 'is_enabled' => true, 'priority' => 100]);
            app(ActionLog::class)->record($lead, $rule, 'lead.created', ['type' => 'send_whatsapp'], 'failed', ['description' => 'لم تُرسل']);
        });

        $noisy = $this->compose('weekly_marketing');
        $this->assertContains('مشاكل الأتمتة', $this->listTitles($noisy));
    }

    public function test_the_weekly_executive_digest_adds_cost_attribution_and_the_automation(): void
    {
        $this->aCampaignThatPaidOff();
        $digest = $this->compose('weekly_executive');
        $labels = array_keys($this->stats($digest));

        foreach (['عملاء جدد', 'مؤهلون', 'مهتمون', 'زيارات', 'حجوزات', 'مبيعات', 'الإيراد', 'الإنفاق', 'تكلفة العميل', 'تكلفة الاكتساب', 'العائد على الإنفاق', 'منصات في خطر', 'فشل الأتمتة'] as $label) {
            $this->assertContains($label, $labels, "«{$label}» is missing from the executive summary");
        }
        foreach (['أفضل الحملات', 'الإسناد حسب المنصة'] as $title) {
            $this->assertContains($title, $this->listTitles($digest), "«{$title}» is missing");
        }
    }

    public function test_the_monthly_digest_adds_the_rates_the_team_the_attribution_and_what_was_noticed(): void
    {
        // Mid-month, so "the month that has just ended" is the same month in UTC (the test's dates) and in the workspace's
        // timezone (the digest's window); in the first hours of a month in Riyadh they disagree.
        $this->travelTo(now()->setDay(15)->setTime(12, 0));
        $this->aCampaignThatPaidOff();
        // The campaign above is dated yesterday; the monthly page reads the month that has just ended.
        $this->asTenant($this->t['tenant'], fn () => DB::table('leads')->whereNotNull('campaign_ref_id')->update(['created_at' => now()->subMonth()->startOfMonth()->addDays(6), 'won_at' => now()->subMonth()->startOfMonth()->addDays(7)]));
        $this->asTenant($this->t['tenant'], fn () => DB::table('metric_snapshots')->update(['date' => now()->subMonth()->startOfMonth()->addDays(5)->toDateString()]));
        $this->asTenant($this->t['tenant'], fn () => Anomaly::create(['kind' => 'cpl_spike', 'subject' => 'حملة الصيف', 'severity' => 'warning', 'observed' => 90, 'expected' => 40, 'detected_on' => now()->subMonth()->startOfMonth()->addDays(4)->toDateString()]));
        $digest = $this->compose('monthly_executive');
        $labels = array_keys($this->stats($digest));

        foreach (['نسبة التأهيل', 'نسبة الاهتمام', 'نسبة الإغلاق', 'الرد في الوقت', 'نسبة التواصل', 'نجاح الأتمتة', 'فشل الأتمتة', 'منصات في خطر'] as $label) {
            $this->assertContains($label, $labels, "«{$label}» is missing from the monthly summary");
        }
        foreach (['الإسناد حسب المنصة', 'ملاحظات الشهر'] as $title) {
            $this->assertContains($title, $this->listTitles($digest), "«{$title}» is missing");
        }
        $notes = collect($digest['lists'])->firstWhere('title', 'ملاحظات الشهر');
        $this->assertStringContainsString('حملة الصيف', implode(' ', $notes['items']));
    }

    public function test_the_email_makes_every_figure_a_link_in_the_brand_green_and_shows_no_raw_keys(): void
    {
        $html = DigestEmail::compose($this->compose('daily_sales'), [], 'Brief Co')['html'];
        $base = rtrim((string) config('integrations.app_url'), '/');

        $this->assertStringContainsString('href="'.$base.'/leads?view=interested"', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('#1D5E52', $html, 'the kit\'s green');
        $this->assertStringNotContainsString('#0f766e', $html, 'not the old teal');
        $this->assertDoesNotMatchRegularExpression('/daily_sales|lead_id|\bnull\b|Array/', $html);
    }

    public function test_the_scheduled_command_sends_the_morning_summary_with_its_links_end_to_end(): void
    {
        config(['leadflow_mail.provider' => 'log']);
        $this->artisan('leadflow:send-digests', ['--tenant' => $this->t['tenant']->slug, '--now' => true, '--kind' => 'daily'])->assertSuccessful();

        $delivery = EmailDelivery::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->where('kind', 'like', 'digest.daily%')->first();

        $this->assertNotNull($delivery, 'the command sent nothing');
        $this->assertStringContainsString('/leads?view=interested', (string) ($delivery->html ?? $delivery->body ?? ''));
    }

    public function test_an_alert_heard_at_once_is_not_also_repeated_in_the_summary(): void
    {
        NotificationPrefs::save($this->rep, ['alerts' => ['hot_lead' => 'instant', 'high_value' => 'daily']]);
        $this->asTenant($this->t['tenant'], function () {
            $lead = $this->lead(['assigned_to' => $this->rep->id]);
            ImportantAlerts::hotLead($lead);
            ImportantAlerts::highValue($lead->forceFill(['budget_max' => 99000000]));
        });

        $rollup = $this->asTenant($this->t['tenant'], fn () => app(DigestComposer::class)->rollup($this->rep->fresh(), 'daily', now()->subDay()));

        $this->assertSame([], array_values(array_filter($rollup, fn ($r) => str_contains($r['title'], 'اهتمام مرتفع'))), 'the hot-lead alert came at once; the summary does not say it again');
    }
}
