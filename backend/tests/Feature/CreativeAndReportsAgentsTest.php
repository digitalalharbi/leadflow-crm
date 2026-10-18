<?php

namespace Tests\Feature;

use App\Domain\Agents\CreativeAgent;
use App\Domain\Agents\Orchestrator;
use App\Domain\Agents\ReportsAgent;
use App\Models\Ad;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreativeAndReportsAgentsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Creative Co');
    }

    /** Two ads in one campaign: one steady, one whose click-through collapsed in the last three days. */
    private function seedAds(): array
    {
        return $this->asTenant($this->t['tenant'], function () {
            $campaign = Campaign::create(['platform' => 'meta', 'external_id' => 'c1', 'name' => 'حملة', 'origin' => 'sync', 'status' => 'active']);
            $steady = Ad::create(['campaign_id' => $campaign->id, 'external_id' => 'a1', 'name' => 'ثابت']);
            $tired = Ad::create(['campaign_id' => $campaign->id, 'external_id' => 'a2', 'name' => 'مرهق']);

            foreach (range(0, 9) as $d) {
                foreach ([[$steady, 50], [$tired, $d <= 2 ? 10 : 50]] as [$ad, $clicks]) {
                    MetricSnapshot::create([
                        'campaign_id' => $campaign->id, 'ad_id' => $ad->id, 'platform' => 'meta', 'level' => 'ad',
                        'date' => now()->subDays($d)->toDateString(), 'impressions' => 1000, 'clicks' => $clicks, 'spend_minor' => 5000,
                    ]);
                }
            }

            foreach (range(1, 6) as $i) {
                $lead = Lead::create(['full_name' => 'ع', 'phone_normalized' => '+96650088800'.$i, 'status' => $i <= 4 ? 'qualified' : 'new']);
                DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $campaign->id, 'ad_ref_id' => $steady->id]);
            }

            return [$campaign, $steady, $tired];
        });
    }

    public function test_fatigue_is_how_far_click_through_fell(): void
    {
        $this->assertSame(80, CreativeAgent::fatigue(['imp3' => 3000, 'clk3' => 30, 'imp7' => 7000, 'clk7' => 350]));
        $this->assertSame(0, CreativeAgent::fatigue(['imp3' => 3000, 'clk3' => 200, 'imp7' => 7000, 'clk7' => 350]));
        $this->assertNull(CreativeAgent::fatigue(['imp3' => 500, 'clk3' => 1, 'imp7' => 7000, 'clk7' => 350]), 'Judged on too few impressions.');
    }

    public function test_the_creative_review_names_the_tired_ad_and_the_winner(): void
    {
        $this->seedAds();

        $findings = $this->asTenant($this->t['tenant'], fn () => app(CreativeAgent::class)->review());
        $byKey = collect($findings)->groupBy(fn ($f) => $f->key);

        $this->assertSame('مرهق', $byKey['fatigue'][0]->figures['ad']);
        $this->assertSame(80, $byKey['fatigue'][0]->figures['fatigue']);
        $this->assertSame('ثابت', $byKey['winner_creative'][0]->figures['ad']);
    }

    public function test_the_campaign_screen_shows_each_ads_fatigue(): void
    {
        [$campaign] = $this->seedAds();

        $ads = collect($this->actingAs($this->t['admin'])->getJson("/api/v1/campaigns/{$campaign->uuid}")->assertOk()->json('data.ads'))->keyBy('name');

        $this->assertSame(80, $ads['مرهق']['fatigue']);
        $this->assertSame(0, $ads['ثابت']['fatigue']);
    }

    public function test_the_weekly_report_is_saved_as_written_with_its_notes(): void
    {
        $this->seedAds();

        $this->asTenant($this->t['tenant'], fn () => app(Orchestrator::class)->run('weekly_report'));

        $list = $this->actingAs($this->t['admin'])->getJson('/api/v1/agent-reports')->assertOk()->json('data.reports');
        $this->assertCount(1, $list);

        $report = $this->actingAs($this->t['admin'])->getJson('/api/v1/agent-reports/'.$list[0]['id'])->assertOk()->json('data.report');
        $this->assertSame(6, $report['data']['totals']['leads']);
        $this->assertSame(ReportsAgent::NOTES, $report['data']['notes']);
        $this->assertSame('meta', $report['data']['by_platform'][0]['platform']);

        $other = $this->provisionTenant('Other', ['admin_email' => 'r@example.test']);
        $this->actingAs($other['admin'])->getJson('/api/v1/agent-reports/'.$list[0]['id'])->assertNotFound();
    }

    public function test_marketing_and_sales_roll_up_from_campaign_to_project_to_company(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            $company = Company::create(['name' => 'شركة أ']);
            $project = Project::create(['name' => 'برج', 'company_id' => $company->id]);

            foreach (['meta' => 3, 'tiktok' => 2] as $platform => $n) {
                $campaign = Campaign::create(['platform' => $platform, 'name' => $platform, 'project_id' => $project->id]);
                foreach (range(1, $n) as $i) {
                    $lead = Lead::create(['full_name' => 'ع', 'phone_normalized' => '+9665009'.($n * 10 + $i).'0000', 'status' => 'qualified', 'project_id' => $project->id]);
                    DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $campaign->id]);
                }
            }
        });

        $byPlatform = $this->actingAs($this->t['admin'])->getJson('/api/v1/reports/marketing-sales?group=platform')->assertOk()->json('data');
        $byCompany = $this->actingAs($this->t['admin'])->getJson('/api/v1/reports/marketing-sales?group=company')->assertOk()->json('data');

        $this->assertCount(2, $byPlatform['rows']);
        $this->assertSame(5, $byPlatform['totals']['leads']);
        $this->assertCount(1, $byCompany['rows']);
        $this->assertSame('شركة أ', $byCompany['rows'][0]['name']);
        $this->assertSame(5, $byCompany['rows'][0]['qualified']);

        $rep = $this->makeUser($this->t['tenant']);
        $this->actingAs($rep)->getJson('/api/v1/reports/marketing-sales')->assertForbidden();
    }
}
