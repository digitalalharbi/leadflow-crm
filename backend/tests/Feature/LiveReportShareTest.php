<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportTemplates;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\ReportShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A report behind a link: live, optionally locked and timed, revocable, and never showing what
 * its sections do not allow.
 */
class LiveReportShareTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Share Co');

        $this->asTenant($this->t['tenant'], function () {
            $campaign = Campaign::create(['platform' => 'meta', 'name' => 'حملة النرجس', 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR']);
            MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $campaign->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 100_000, 'currency' => 'SAR']);

            foreach ([['contacted', null], ['won', 50_000_000]] as [$status, $value]) {
                $lead = Lead::create(['full_name' => 'عميل', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => $status, 'deal_value_minor' => $value, 'assigned_to' => $this->t['admin']->id]);
                DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $campaign->id, 'created_at' => now()->subDay()]);
            }
        });
    }

    private function make(array $data = []): array
    {
        return $this->actingAs($this->t['admin'])->postJson('/api/v1/report-shares', $data + ['name' => 'تقرير الإدارة', 'template' => 'executive'])
            ->assertCreated()->json('data.share');
    }

    private function open(array $share, array $headers = [])
    {
        $token = basename($share['url']);

        return $this->withHeaders($headers)->getJson("/api/v1/shared/reports/{$token}");
    }

    public function test_a_link_opens_the_live_numbers_without_logging_in(): void
    {
        $share = $this->make();
        $this->assertStringContainsString('/reports/share/', $share['url']);
        $this->assertSame('active', $share['status']);

        // Anonymous: no session.
        $data = $this->open($share)->assertOk()->json('data');

        $this->assertSame(2, $data['kpis']['leads']);
        $this->assertSame(1, $data['kpis']['won']);
        $this->assertEquals(500000, $data['kpis']['revenue']);
        $this->assertEquals(1000, $data['kpis']['spend']);
        $this->assertEquals(500, $data['kpis']['roas']);
        $this->assertSame(['leads', 'qualified', 'interested', 'visits', 'reservations', 'won'], collect($data['funnel'])->pluck('key')->all());
        $this->assertSame('Share Co', $data['workspace']['name']);

        // Live: a new lead is in the next load.
        $this->asTenant($this->t['tenant'], fn () => DB::table('leads')->where('id', Lead::create(['full_name' => 'جديد', 'phone_normalized' => '+9665599'.random_int(10000, 99999)])->id)->update(['created_at' => now()->subHour()]));
        $this->assertSame(3, $this->open($share)->assertOk()->json('data.kpis.leads'));
    }

    public function test_sections_that_are_off_are_not_in_the_response_at_all(): void
    {
        $client = $this->make(['template' => 'client']);

        $text = json_encode($this->open($client)->assertOk()->json('data'));
        $data = json_decode($text, true);

        $this->assertArrayHasKey('leads', $data['kpis']);
        foreach (['spend', 'cpl', 'cac', 'roas', 'revenue'] as $money) {
            $this->assertArrayNotHasKey($money, $data['kpis']);
        }
        $this->assertArrayNotHasKey('cost', $data['funnel'][0]);
        $this->assertArrayNotHasKey('spend', $data['trend'][0]);
        $this->assertArrayNotHasKey('revenue', $data['trend'][0]);
        $this->assertArrayNotHasKey('team', $data);
        $this->assertArrayNotHasKey('campaigns', $data);
        $this->assertStringNotContainsString('حملة النرجس', $text, 'a campaign name does not leave in a client report');
    }

    public function test_each_section_can_be_switched_on_or_off(): void
    {
        $share = $this->make(['template' => 'client', 'sections' => ['kpi_volume' => true, 'campaigns' => true, 'team' => true, 'kpi_revenue' => true]]);

        $data = $this->open($share)->assertOk()->json('data');

        $this->assertSame(['kpi_volume', 'kpi_revenue', 'campaigns', 'team'], $data['sections']);
        $this->assertEquals(500000, $data['kpis']['revenue']);
        $this->assertArrayNotHasKey('spend', $data['kpis']);
        $this->assertSame('حملة النرجس', $data['campaigns'][0]['name']);
        $this->assertArrayNotHasKey('spend', $data['campaigns'][0]);
        $this->assertArrayNotHasKey('funnel', $data);
        // Names only: a link that leaves the building carries no contact details.
        $this->assertSame(['name', 'leads', 'interested', 'visits', 'reservations', 'won'], array_keys($data['team'][0]));

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/report-shares/{$share['id']}", ['sections' => ['kpi_volume' => true]])->assertOk();
        $this->assertSame(['kpi_volume'], $this->open($share)->json('data.sections'));
    }

    public function test_a_password_is_asked_for_and_checked(): void
    {
        $share = $this->make(['password' => 'secret-pass']);
        $this->assertTrue($share['has_password']);

        $asked = $this->open($share)->assertStatus(401)->json('data');
        $this->assertTrue($asked['password_required']);
        $this->assertArrayNotHasKey('kpis', $asked, 'nothing about the numbers before the password');

        $this->open($share, ['X-Share-Password' => 'wrong'])->assertStatus(401);
        $this->open($share, ['X-Share-Password' => 'secret-pass'])->assertOk()->assertJsonPath('data.kpis.leads', 2);

        // Password removed: open to anybody with the link.
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/report-shares/{$share['id']}", ['password' => null])->assertOk()->assertJsonPath('data.share.has_password', false);
        $this->open($share)->assertOk();
    }

    public function test_an_expired_link_says_so_and_a_revoked_one_is_gone(): void
    {
        $share = $this->make(['expires_at' => now()->addDay()->toIso8601String()]);
        $this->open($share)->assertOk();

        DB::table('report_shares')->update(['expires_at' => now()->subMinute()]);
        $this->open($share)->assertStatus(410);

        DB::table('report_shares')->update(['expires_at' => null]);
        $this->open($share)->assertOk();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/revoke")->assertOk()->assertJsonPath('data.share.status', 'revoked');
        $this->open($share)->assertNotFound();
    }

    public function test_regenerating_locks_out_the_old_link_and_opens_a_revoked_report_again(): void
    {
        $share = $this->make();
        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/revoke")->assertOk();

        $new = $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/regenerate")->assertOk()->json('data.share');

        $this->assertNotSame($share['url'], $new['url']);
        $this->open($share)->assertNotFound();
        $this->open($new)->assertOk();
        $this->assertSame('active', $new['status']);
    }

    public function test_views_are_counted_and_the_last_one_remembered(): void
    {
        $share = $this->make();
        $this->open($share)->assertOk();
        $this->open($share)->assertOk();
        $this->open($share, ['X-Share-Password' => 'x'])->assertOk();

        $listed = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/report-shares')->json('data.shares'))->firstWhere('id', $share['id']);

        $this->assertSame(3, $listed['view_count']);
        $this->assertNotNull($listed['last_viewed_at']);
    }

    public function test_everything_done_to_a_link_is_audited_without_the_secret(): void
    {
        $share = $this->make(['password' => 'secret-pass']);
        $token = basename($share['url']);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/report-shares/{$share['id']}", ['days' => 7])->assertOk();
        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/regenerate")->assertOk();
        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/revoke")->assertOk();
        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/report-shares/{$share['id']}")->assertOk();

        $rows = $this->asTenant($this->t['tenant'], fn () => AuditLog::where('action', 'like', 'report_share.%')->orderBy('id')->get());
        $this->assertSame(['report_share.created', 'report_share.updated', 'report_share.regenerated', 'report_share.revoked', 'report_share.deleted'], $rows->pluck('action')->all());
        $this->assertStringNotContainsString($token, json_encode($rows->pluck('changes')));
        $this->assertStringNotContainsString('secret-pass', json_encode($rows->pluck('changes')));
    }

    public function test_the_token_is_not_stored_in_the_clear(): void
    {
        $share = $this->make();
        $token = basename($share['url']);
        $row = DB::table('report_shares')->first();

        $this->assertNotSame($token, $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode($row));
        $this->assertSame(ReportShare::hash($token), $row->token_hash);
    }

    public function test_only_managers_make_links_and_one_workspace_cannot_touch_anothers(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->actingAs($rep)->postJson('/api/v1/report-shares', ['name' => 'x', 'template' => 'client'])->assertForbidden();
        $this->actingAs($rep)->getJson('/api/v1/report-shares')->assertForbidden();

        $share = $this->make();
        $other = $this->provisionTenant('Rival Co');
        $this->actingAs($other['admin'])->postJson("/api/v1/report-shares/{$share['id']}/revoke")->assertNotFound();
        $this->assertSame([], $this->actingAs($other['admin'])->getJson('/api/v1/report-shares')->json('data.shares'));
    }

    public function test_templates_start_from_the_right_sections(): void
    {
        $this->assertSame(['kpi_volume', 'funnel', 'trend'], array_keys(array_filter(ReportTemplates::sectionsFor('client'))));
        $this->assertSame(ReportTemplates::SECTIONS, array_keys(array_filter(ReportTemplates::sectionsFor('management'))));
        $this->assertEqualsCanonicalizing(['executive', 'marketing', 'sales', 'client', 'partner', 'management'], array_keys(ReportTemplates::TEMPLATES));
    }

    public function test_a_malformed_or_unknown_token_is_a_plain_404(): void
    {
        $this->getJson('/api/v1/shared/reports/short')->assertNotFound();
        $this->getJson('/api/v1/shared/reports/'.str_repeat('a', 40))->assertNotFound();
    }
}
