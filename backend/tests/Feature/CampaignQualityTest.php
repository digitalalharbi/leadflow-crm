<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\QualificationStatus;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The report that pays for the product.
 *
 * A campaign bringing 500 leads and one appointment is worse than one bringing
 * 300 and fifteen, and a lead count says the opposite. These tests are built
 * around exactly that comparison.
 */
class CampaignQualityTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Campaign Co');
    }

    public function test_it_separates_a_good_campaign_from_a_noisy_one(): void
    {
        // Fewer leads, far better ones.
        $this->seedCampaign('meta', 'حملة أ', total: 30, contacted: 28, replied: 20, qualified: 18, appointments: 6, won: 3);
        // More leads, almost none worth calling.
        $this->seedCampaign('tiktok', 'حملة ب', total: 50, contacted: 40, replied: 8, qualified: 4, appointments: 1, won: 0);

        $response = $this->actingAs($this->t['admin'])->getJson('/api/v1/reports/campaign-quality');

        $response->assertSuccessful();

        $rows = collect($response->json('data.rows'))->keyBy('campaign');

        $this->assertSame(30, $rows['حملة أ']['leads']);
        $this->assertSame(18, $rows['حملة أ']['qualified']);
        $this->assertSame(50, $rows['حملة ب']['leads']);
        $this->assertSame(4, $rows['حملة ب']['qualified']);

        $this->assertGreaterThan(
            $rows['حملة ب']['qualification_rate'],
            $rows['حملة أ']['qualification_rate'],
            'The smaller campaign qualifies a far higher share and must read that way.',
        );

        // The row that brought more leads must not sort or read as the better one.
        $this->assertGreaterThan($rows['حملة ب']['win_rate'], $rows['حملة أ']['win_rate']);
    }

    public function test_a_won_lead_still_counts_as_an_appointment(): void
    {
        // Otherwise the campaigns that close best would show the fewest
        // appointments, because their leads moved past that stage.
        $this->seedCampaign('meta', 'حملة البيع', total: 4, contacted: 4, replied: 4, qualified: 4, appointments: 1, won: 2);

        $row = collect($this->actingAs($this->t['admin'])
            ->getJson('/api/v1/reports/campaign-quality')
            ->json('data.rows'))->firstWhere('campaign', 'حملة البيع');

        $this->assertSame(3, $row['appointments']);
    }

    public function test_the_platform_is_reported_beside_the_campaign(): void
    {
        $this->seedCampaign('snapchat', 'حملة سناب', total: 3, contacted: 1);

        $row = collect($this->actingAs($this->t['admin'])
            ->getJson('/api/v1/reports/campaign-quality')
            ->json('data.rows'))->firstWhere('campaign', 'حملة سناب');

        $this->assertSame('snapchat', $row['platform']);
    }

    public function test_it_can_be_filtered_by_date(): void
    {
        $this->seedCampaign('meta', 'حملة قديمة', total: 5, createdAt: now()->subMonths(2));
        $this->seedCampaign('meta', 'حملة حالية', total: 2);

        $campaigns = collect($this->actingAs($this->t['admin'])
            ->getJson('/api/v1/reports/campaign-quality?date_from='.now()->subWeek()->toDateString())
            ->json('data.rows'))->pluck('campaign');

        $this->assertTrue($campaigns->contains('حملة حالية'));
        $this->assertFalse($campaigns->contains('حملة قديمة'));
    }

    public function test_it_never_counts_another_workspaces_campaign(): void
    {
        $other = $this->provisionTenant('Rival Co');

        $this->asTenant($other['tenant'], fn () => Lead::create([
            'full_name' => 'عميل منافس',
            'phone' => '0599999999',
            'phone_normalized' => '+966599999999',
            'platform' => 'meta',
            'campaign_name' => 'حملة سرية',
        ]));

        $this->seedCampaign('meta', 'حملتنا', total: 2);

        $campaigns = collect($this->actingAs($this->t['admin'])
            ->getJson('/api/v1/reports/campaign-quality')
            ->json('data.rows'))->pluck('campaign');

        $this->assertTrue($campaigns->contains('حملتنا'));
        $this->assertFalse($campaigns->contains('حملة سرية'));
    }

    public function test_a_sales_rep_cannot_read_the_report(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/reports/campaign-quality')->assertStatus(403);
    }

    public function test_the_export_is_a_csv_with_the_documented_columns(): void
    {
        $this->seedCampaign('meta', 'حملة التصدير', total: 2, contacted: 1);

        $response = $this->actingAs($this->t['admin'])->get('/api/v1/reports/campaign-quality/export');

        $response->assertSuccessful();
        $csv = $response->streamedContent();

        foreach (['Platform', 'Campaign', 'Leads', 'Contacted', 'Replied', 'Qualified', 'Hot', 'Appointments', 'Won'] as $column) {
            $this->assertStringContainsString($column, $csv);
        }

        $this->assertStringContainsString('حملة التصدير', $csv);
    }

    public function test_performance_reports_the_rates_and_the_response_time(): void
    {
        $this->seedCampaign('meta', 'حملة الأداء', total: 10, contacted: 8, replied: 5, qualified: 4, appointments: 2, won: 1);

        $response = $this->actingAs($this->t['admin'])->getJson('/api/v1/reports/performance');

        $response->assertSuccessful()
            ->assertJsonPath('data.counts.leads', 10)
            ->assertJsonPath('data.counts.contacted', 8)
            ->assertJsonPath('data.counts.replied', 5)
            ->assertJsonPath('data.rates.contact_rate', 80)
            ->assertJsonPath('data.rates.reply_rate', 50);

        // Contacted an hour after arriving, in the fixture below.
        $this->assertEqualsWithDelta(3600, $response->json('data.timing.avg_first_contact_seconds'), 60);
    }

    public function test_leads_nobody_contacted_do_not_flatter_the_response_time(): void
    {
        // Averaging the untouched ones in as zero would make a team that ignores
        // half its leads look twice as fast as one that answers all of them.
        $this->seedCampaign('meta', 'حملة', total: 10, contacted: 1);

        $seconds = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/reports/performance')
            ->json('data.timing.avg_first_contact_seconds');

        $this->assertEqualsWithDelta(3600, $seconds, 60);
    }

    public function test_the_sales_team_report_measures_rather_than_ranks(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->seedCampaign('meta', 'حملة الفريق', total: 6, contacted: 4, replied: 3, qualified: 2, won: 1);

        $this->asTenant($this->t['tenant'], fn () => Lead::query()->limit(3)->update(['assigned_to' => $rep->id]));

        $rows = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/reports/sales-team')
            ->assertSuccessful()
            ->json('data.rows');

        $this->assertNotEmpty($rows);

        $mine = collect($rows)->firstWhere('name', $rep->name);
        $this->assertNotNull($mine);
        $this->assertSame(3, $mine['leads']);

        // Measurements, not a score: there is no rank, grade or position.
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('rank', $row);
            $this->assertArrayNotHasKey('score', $row);
            $this->assertArrayHasKey('avg_first_contact_seconds', $row);
        }
    }

    public function test_the_sales_team_report_answers_inside_a_date_range(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->seedCampaign('meta', 'حملة المدى', total: 4, contacted: 2);
        $this->asTenant($this->t['tenant'], fn () => Lead::query()->limit(2)->update(['assigned_to' => $rep->id]));

        // The report joins the users table; a bare `created_at` in the date filter is ambiguous there and was a 500.
        $rows = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/reports/sales-team?date_from='.now()->subDays(30)->toDateString().'&date_to='.now()->addDay()->toDateString())
            ->assertSuccessful()
            ->json('data.rows');

        $this->assertSame(2, collect($rows)->firstWhere('name', $rep->name)['leads']);
    }

    public function test_the_sales_team_report_is_not_open_to_reps(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/reports/sales-team')->assertStatus(403);
    }

    public function test_the_sales_team_report_never_counts_another_workspace(): void
    {
        $other = $this->provisionTenant('Rival Co');
        $stranger = $this->makeUser($other['tenant'], Role::Sales);

        $this->asTenant($other['tenant'], fn () => Lead::create([
            'full_name' => 'عميل منافس',
            'phone' => '0599000111',
            'phone_normalized' => '+966599000111',
            'assigned_to' => $stranger->id,
        ]));

        $this->seedCampaign('meta', 'حملتنا', total: 2);

        $names = collect($this->actingAs($this->t['admin'])
            ->getJson('/api/v1/reports/sales-team')
            ->json('data.rows'))->pluck('name');

        $this->assertFalse($names->contains($stranger->name));
    }

    public function test_projects_carry_their_own_funnel_metrics(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create([
            'name' => 'مشروع القياس',
            'location' => 'الرياض',
            'starting_price' => 900_000,
        ]));

        $this->seedCampaign('meta', 'حملة المشروع', total: 5, contacted: 4, qualified: 3, appointments: 1, won: 1);
        $this->asTenant($this->t['tenant'], fn () => Lead::query()->update(['project_id' => $project->id]));

        $metrics = collect($this->actingAs($this->t['admin'])
            ->getJson('/api/v1/projects')
            ->assertSuccessful()
            ->json('data.metrics'))->firstWhere('id', $project->uuid);

        $this->assertSame(5, $metrics['leads']);
        $this->assertSame(3, $metrics['qualified']);
        $this->assertSame(2, $metrics['appointments']);
        $this->assertSame(1, $metrics['won']);
        $this->assertEquals(60, $metrics['qualification_rate']);
    }

    public function test_a_project_with_no_leads_reports_zeroes(): void
    {
        $empty = $this->asTenant($this->t['tenant'], fn () => Project::create([
            'name' => 'مشروع جديد',
            'location' => 'جدة',
        ]));

        $metrics = collect($this->actingAs($this->t['admin'])
            ->getJson('/api/v1/projects')
            ->json('data.metrics'))->firstWhere('id', $empty->uuid);

        $this->assertSame(0, $metrics['leads']);
        $this->assertEquals(0, $metrics['qualification_rate']);
    }

    public function test_project_metrics_respect_what_a_rep_may_see(): void
    {
        // A rep's project card must count their own leads, not the workspace's.
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'مشروع مشترك']));

        $this->seedCampaign('meta', 'حملة', total: 4);
        $this->asTenant($this->t['tenant'], function () use ($project, $rep) {
            Lead::query()->update(['project_id' => $project->id]);
            Lead::query()->limit(1)->update(['assigned_to' => $rep->id]);
        });

        $mine = collect($this->actingAs($rep)->getJson('/api/v1/projects')->json('data.metrics'))
            ->firstWhere('id', $project->uuid);

        $this->assertSame(1, $mine['leads']);
    }

    /** Builds a campaign with a known funnel shape. */
    private function seedCampaign(
        string $platform,
        string $campaign,
        int $total,
        int $contacted = 0,
        int $replied = 0,
        int $qualified = 0,
        int $appointments = 0,
        int $won = 0,
        ?\DateTimeInterface $createdAt = null,
    ): void {
        $createdAt ??= now()->subDay();

        $this->asTenant($this->t['tenant'], function () use ($platform, $campaign, $total, $contacted, $replied, $qualified, $appointments, $won, $createdAt) {
            for ($i = 0; $i < $total; $i++) {
                $status = match (true) {
                    $i < $won => LeadStatus::Won,
                    $i < $won + $appointments => LeadStatus::Appointment,
                    $i < $contacted => LeadStatus::Contacted,
                    default => LeadStatus::New,
                };

                $lead = Lead::create([
                    'full_name' => $campaign.' '.$i,
                    'phone' => '05'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                    'phone_normalized' => '+9665'.str_pad((string) ($i + crc32($campaign) % 1000000), 8, '0', STR_PAD_LEFT),
                    'platform' => $platform,
                    'campaign_name' => $campaign,
                    'status' => $status,
                    'qualification_status' => $i < $qualified
                        ? QualificationStatus::Qualified
                        : QualificationStatus::Unqualified,
                    'first_contact_at' => $i < $contacted ? (clone $createdAt)->modify('+1 hour') : null,
                    'first_response_at' => $i < $replied ? (clone $createdAt)->modify('+2 hours') : null,
                ]);

                // created_at is guarded, and these fixtures need leads that
                // arrived at a known moment so the response times mean something.
                $lead->forceFill(['created_at' => $createdAt])->saveQuietly();
            }
        });
    }
}
