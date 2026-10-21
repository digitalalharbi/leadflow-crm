<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Tracking\Attribution;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Partners bring customers, each through links of their own; a QR code is one of those links on
 * paper; a broker with a login sees only what their own partner brought.
 */
class PartnersAndQrTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Partners Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function api()
    {
        return $this->actingAs($this->t['admin']);
    }

    private function partner(array $extra = []): array
    {
        return $this->api()->postJson('/api/v1/partners', $extra + ['name' => 'وسيط الرياض', 'type' => 'broker', 'commission_percent' => 2.5])->assertCreated()->json('data.partner');
    }

    private function link(string $partnerId, array $extra = []): array
    {
        return $this->api()->postJson('/api/v1/tracking/links', $extra + ['name' => 'رابط الوسيط', 'channel' => 'broker', 'partner_id' => $partnerId, 'project_id' => $this->project->uuid])->assertCreated()->json('data.link');
    }

    private function leadVia(array $link, array $attributes = []): Lead
    {
        $token = $link['token'];

        return $this->asTenant($this->t['tenant'], function () use ($token, $attributes) {
            $lead = Lead::create($attributes + ['full_name' => 'عميل', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'contacted', 'project_id' => $this->project->id]);
            $link = TrackingLink::where('token', $token)->first();
            // What stitching does when a visitor who clicked the link becomes a lead.
            DB::table('leads')->where('id', $lead->id)->update(['first_touch_link_id' => $link->id, 'partner_id' => $link->partner_id]);

            return $lead->fresh();
        });
    }

    public function test_a_partner_is_made_changed_and_audited_and_a_link_knows_whose_it_is(): void
    {
        $partner = $this->partner();
        $this->assertSame('broker', $partner['type']);
        $this->assertFalse($partner['show_revenue']);

        $link = $this->link($partner['id'], ['placement' => 'billboard']);
        $this->assertSame($partner['id'], $link['partner']['id']);
        $this->assertSame('billboard', $link['placement']);

        $this->api()->patchJson("/api/v1/partners/{$partner['id']}", ['commission_percent' => 3, 'show_revenue' => true])->assertOk()->assertJsonPath('data.partner.commission_percent', 3);
        $this->api()->postJson('/api/v1/partners', ['name' => 'x', 'type' => 'wizard'])->assertStatus(422);
        $this->api()->postJson('/api/v1/tracking/links', ['name' => 'x', 'placement' => 'moon'])->assertStatus(422);

        $actions = $this->asTenant($this->t['tenant'], fn () => AuditLog::where('action', 'like', 'partner.%')->pluck('action')->all());
        $this->assertSame(['partner.created', 'partner.updated'], $actions);
    }

    public function test_a_lead_is_credited_to_the_partner_whose_link_first_reached_it(): void
    {
        $partner = $this->partner();
        $other = $this->partner(['name' => 'مؤثر', 'type' => 'influencer', 'commission_percent' => null]);
        $mine = $this->link($partner['id']);

        $this->leadVia($mine);
        $this->leadVia($mine, ['status' => 'won', 'deal_value_minor' => 100_000_000]);
        $this->leadVia($this->link($other['id']));

        $rows = collect($this->api()->getJson('/api/v1/partners')->assertOk()->json('data.partners'))->keyBy('id');

        $this->assertSame(2, $rows[$partner['id']]['stats']['leads']);
        $this->assertSame(1, $rows[$partner['id']]['stats']['won']);
        $this->assertEquals(1000000, $rows[$partner['id']]['stats']['revenue']);
        $this->assertEquals(25000, $rows[$partner['id']]['stats']['commission'], '2.5% of 1,000,000');
        $this->assertSame(1, $rows[$other['id']]['stats']['leads']);
        $this->assertNull($rows[$other['id']]['stats']['commission'], 'no percentage agreed: no commission, not a zero');
        $this->assertSame(1, $rows[$partner['id']]['stats']['links']);
    }

    public function test_attribution_stamps_the_partner_on_the_lead(): void
    {
        $partner = $this->partner();
        $link = $this->link($partner['id']);

        $lead = $this->asTenant($this->t['tenant'], function () use ($link) {
            $lead = Lead::create(['full_name' => 'عميل', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'new']);
            $model = TrackingLink::where('token', $link['token'])->first();
            $incoming = IncomingLead::fromArray(IntegrationProvider::GoogleSheets, ['full_name' => 'عميل', 'phone' => '0551234567']);
            app(Attribution::class)->attachLead($lead, $incoming, $model);

            return $lead->fresh();
        });

        $this->assertNotNull($lead->partner_id);
    }

    public function test_a_qr_code_is_an_svg_of_the_short_link(): void
    {
        $link = $this->link($this->partner()['id'], ['kind' => 'qr', 'channel' => 'qr', 'placement' => 'event', 'name' => 'معرض الرياض']);

        $response = $this->api()->get("/api/v1/tracking/links/{$link['id']}/qr")->assertOk();
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('<?xml', $response->getContent());
        $this->assertStringContainsString('<svg', $response->getContent());

        $download = $this->api()->get("/api/v1/tracking/links/{$link['id']}/qr?download=1")->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));

        $this->actingAs($this->makeUser($this->t['tenant'], Role::Finance))->get("/api/v1/tracking/links/{$link['id']}/qr")->assertForbidden();
    }

    public function test_a_qr_code_is_also_a_png_that_scans_and_can_be_downloaded(): void
    {
        $link = $this->link($this->partner()['id'], ['kind' => 'qr', 'channel' => 'qr', 'placement' => 'billboard', 'name' => 'لوحة الدائري']);

        $response = $this->api()->get("/api/v1/tracking/links/{$link['id']}/qr?format=png&size=800")->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $png = $response->getContent();
        $this->assertSame("\x89PNG", substr($png, 0, 4));

        [$w, $h] = getimagesizefromstring($png);
        $this->assertSame($w, $h);
        $this->assertLessThanOrEqual(800, $w);
        $this->assertGreaterThan(400, $w, 'a whole number of pixels per module, as close to the size asked as it allows');

        // The quiet zone around the code is white, and the code itself is a good deal darker than blank paper.
        $image = imagecreatefromstring($png);
        $this->assertSame(0xFFFFFF, imagecolorat($image, 1, 1) & 0xFFFFFF);
        $dark = 0;
        $samples = 0;

        for ($x = 0; $x < $w; $x += 3) {
            for ($y = 0; $y < $h; $y += 3) {
                $samples++;
                $dark += (imagecolorat($image, $x, $y) & 0xFFFFFF) === 0x000000 ? 1 : 0;
            }
        }

        $this->assertGreaterThan(0.15, $dark / $samples);
        $this->assertLessThan(0.7, $dark / $samples);

        $this->assertStringContainsString('.png', $this->api()->get("/api/v1/tracking/links/{$link['id']}/qr?format=png&download=1")->headers->get('Content-Disposition'));

        // An absurd size is held to the limits rather than allocating a gigabyte.
        $big = $this->api()->get("/api/v1/tracking/links/{$link['id']}/qr?format=png&size=999999")->assertOk()->getContent();
        $this->assertLessThanOrEqual(2048, getimagesizefromstring($big)[0]);

        // Anything else asked for is the vector.
        $this->assertSame('image/svg+xml', $this->api()->get("/api/v1/tracking/links/{$link['id']}/qr?format=gif")->headers->get('Content-Type'));
    }

    public function test_what_a_printed_code_has_brought_is_counted_down_the_whole_funnel(): void
    {
        $link = $this->link($this->partner()['id'], ['kind' => 'qr', 'channel' => 'qr', 'placement' => 'event']);

        $this->leadVia($link);
        $seen = $this->leadVia($link);
        $this->asTenant($this->t['tenant'], function () use ($seen) {
            Appointment::create(['lead_id' => $seen->id, 'scheduled_at' => now()->subDay(), 'status' => 'completed']);
        });
        $held = $this->leadVia($link);
        $this->asTenant($this->t['tenant'], fn () => Reservation::create(['lead_id' => $held->id, 'status' => 'pending', 'reserved_at' => now()]));
        $this->leadVia($link, ['status' => 'won', 'deal_value_minor' => 50_000_000]);

        $stats = collect($this->api()->getJson('/api/v1/tracking/links')->assertOk()->json('data.links'))->firstWhere('id', $link['id'])['stats'];

        $this->assertSame(4, $stats['leads']);
        // Each step counts everyone who got at least that far: the won one was interested, saw it and held it.
        $this->assertSame(3, $stats['interested']);
        $this->assertSame(3, $stats['visits_done']);
        $this->assertSame(2, $stats['reservations']);
        $this->assertSame(1, $stats['won']);
    }

    public function test_a_broker_login_sees_only_what_their_partner_brought(): void
    {
        $mine = $this->partner();
        $theirs = $this->partner(['name' => 'منافس']);
        $myLead = $this->leadVia($this->link($mine['id']), ['full_name' => 'عميلي']);
        $this->leadVia($this->link($theirs['id']), ['full_name' => 'ليس لي']);
        $myLink = $this->link($mine['id'], ['name' => 'رابطي']);
        $this->link($theirs['id'], ['name' => 'رابطهم']);

        $broker = $this->makeUser($this->t['tenant'], Role::BrokerViewer);
        $this->asTenant($this->t['tenant'], fn () => $broker->projects()->sync([$this->project->id]));

        $this->api()->patchJson("/api/v1/users/{$broker->uuid}", ['partner_id' => $mine['id']])->assertOk()->assertJsonPath('data.user.partner.name', 'وسيط الرياض');
        $broker = $broker->fresh();

        $names = collect($this->actingAs($broker)->getJson('/api/v1/leads')->assertOk()->json('data'))->pluck('full_name')->all();
        $this->assertSame(['عميلي'], $names);
        $this->actingAs($broker)->getJson("/api/v1/leads/{$myLead->uuid}")->assertOk();

        $links = collect($this->actingAs($broker)->getJson('/api/v1/tracking/links')->assertOk()->json('data.links'))->pluck('name');
        $this->assertTrue($links->contains('رابطي'));
        $this->assertFalse($links->contains('رابطهم'));

        // Their own QR, never anyone else's, and no analysis of the company.
        $this->actingAs($broker)->get("/api/v1/tracking/links/{$myLink['id']}/qr")->assertOk();
        $this->actingAs($broker)->getJson('/api/v1/tracking/health')->assertForbidden();
        $this->actingAs($broker)->getJson('/api/v1/partners')->assertForbidden();

        // Not linked to a partner yet: nothing at all.
        $loose = $this->makeUser($this->t['tenant'], Role::BrokerViewer);
        $this->asTenant($this->t['tenant'], fn () => $loose->projects()->sync([$this->project->id]));
        $this->assertSame([], $this->actingAs($loose)->getJson('/api/v1/leads')->assertOk()->json('data'));
    }

    public function test_a_partner_report_shows_their_leads_and_hides_money_unless_the_partner_may_see_it(): void
    {
        $partner = $this->partner();
        $link = $this->link($partner['id']);
        $this->leadVia($link);
        $this->leadVia($link, ['status' => 'won', 'deal_value_minor' => 100_000_000]);
        $this->leadVia($this->link($this->partner(['name' => 'غيره'])['id']));      // someone else's

        // Everything switched on — and still only what a partner may have.
        $share = $this->api()->postJson('/api/v1/report-shares', [
            'name' => 'تقرير الوسيط', 'template' => 'partner', 'partner_id' => $partner['id'],
            'sections' => ['kpi_volume' => true, 'kpi_cost' => true, 'kpi_revenue' => true, 'funnel' => true, 'trend' => true, 'platforms' => true, 'campaigns' => true, 'team' => true],
        ])->assertCreated()->json('data.share');

        $this->assertSame(['kpi_volume', 'funnel', 'trend'], array_keys(array_filter($share['sections'])), 'the list says what it really shows');
        $open = fn () => $this->getJson('/api/v1/shared/reports/'.basename($share['url']))->assertOk()->json('data');

        $data = $open();
        $this->assertSame(2, $data['kpis']['leads'], 'their leads only');
        $this->assertSame(1, $data['kpis']['won']);
        foreach (['spend', 'cpl', 'cac', 'roas', 'revenue'] as $money) {
            $this->assertArrayNotHasKey($money, $data['kpis']);
        }
        foreach (['campaigns', 'platforms', 'team'] as $other) {
            $this->assertArrayNotHasKey($other, $data);
        }

        // The partner is allowed to see revenue: only that, never the company's cost.
        $this->api()->patchJson("/api/v1/partners/{$partner['id']}", ['show_revenue' => true])->assertOk();
        $data = $open();
        $this->assertEquals(1000000, $data['kpis']['revenue']);
        $this->assertArrayNotHasKey('spend', $data['kpis']);
    }

    public function test_only_those_who_manage_partners_may(): void
    {
        $this->actingAs($this->makeUser($this->t['tenant'], Role::Sales))->getJson('/api/v1/partners')->assertForbidden();
        $this->actingAs($this->makeUser($this->t['tenant'], Role::Manager))->getJson('/api/v1/partners')->assertOk();
        $this->actingAs($this->makeUser($this->t['tenant'], Role::MarketingManager))->postJson('/api/v1/partners', ['name' => 'x', 'type' => 'affiliate'])->assertCreated();
        $this->actingAs($this->makeUser($this->t['tenant'], Role::Finance))->getJson('/api/v1/partners')->assertForbidden();
        $this->assertInstanceOf(User::class, $this->t['admin']);
    }
}
