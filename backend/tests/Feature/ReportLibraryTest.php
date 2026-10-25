<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportTemplates;
use App\Enums\Role;
use App\Models\EmailDelivery;
use App\Models\Partner;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ReportShare;
use App\Models\ShortLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reports library: a report's life, its notes and history, the links it is given, who may open them, and the files
 * it can become — all inside one workspace and the projects a person may open.
 */
class ReportLibraryTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Library Co');
        $this->putOnPlan($this->t['tenant'], 'enterprise');
        // Reports and exports, and none of the extras that come with the bigger plans.
        Plan::create(['key' => 'lean', 'name' => 'Lean', 'included_seats' => 3, 'limits' => ['projects' => null], 'features' => ['advanced_reports', 'exports', 'multiple_projects'], 'is_active' => true, 'sort_order' => 9]);
    }

    private function make(array $data = []): array
    {
        return $this->actingAs($this->t['admin'])->postJson('/api/v1/report-shares', $data + ['name' => 'تقرير العميل', 'template' => 'executive'])->assertCreated()->json('data.share');
    }

    private function open(string $url, array $headers = [])
    {
        return $this->withHeaders($headers)->getJson('/api/v1/public/r/'.basename($url));
    }

    private function project(string $name = 'مشروع'): Project
    {
        return $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => $name, 'status' => 'active']));
    }

    public function test_a_report_carries_its_library_settings_and_the_projects_it_covers(): void
    {
        $a = $this->project('برج الندى');
        $b = $this->project('واحة الرياض');

        $share = $this->make([
            'client_name' => 'مؤسسة الأفق', 'project_ids' => [$a->uuid, $b->uuid], 'period' => 'previous_month', 'compare' => true,
            'compare_mode' => 'previous_month', 'attribution_model' => 'linear', 'currency' => 'sar',
        ]);

        $this->assertSame('live', $share['state']);
        $this->assertSame('مؤسسة الأفق', $share['client_name']);
        $this->assertEqualsCanonicalizing(['برج الندى', 'واحة الرياض'], array_column($share['projects'], 'name'));
        $this->assertSame('linear', $share['attribution_model']);
        $this->assertSame('SAR', $share['currency']);
        $this->assertSame('previous_month', $share['period']);
    }

    public function test_a_draft_does_not_open_until_it_is_published_and_an_archived_report_closes_again(): void
    {
        $share = $this->make(['state' => 'draft']);
        $this->assertSame('draft', $share['state']);
        $this->open($share['url'])->assertNotFound();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/publish")->assertOk();
        $this->open($share['url'])->assertOk();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/archive")->assertOk();
        $this->open($share['url'])->assertNotFound();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/unarchive")->assertOk()->assertJsonPath('data.state', 'draft');
        $this->open($share['url'])->assertNotFound();
    }

    public function test_duplicating_makes_a_draft_with_the_same_setup_and_no_link(): void
    {
        $project = $this->project();
        $share = $this->make(['project_ids' => [$project->uuid], 'client_name' => 'عميل']);

        $copy = $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/duplicate")->assertCreated()->json('data.id');
        $row = ReportShare::where('uuid', $copy)->first();

        $this->assertSame('draft', $row->state);
        $this->assertSame([$project->id], $row->projectIds());
        $this->assertSame(0, $row->links()->count());
        $this->assertNotSame($share['url'], $row->url());
    }

    public function test_notes_keep_every_revision_and_the_setup_keeps_its_history(): void
    {
        $share = $this->make();
        $api = fn () => $this->actingAs($this->t['admin']);

        $api()->putJson("/api/v1/report-shares/{$share['id']}/notes", ['kind' => 'executive', 'body' => 'أول ملاحظة'])->assertOk();
        $api()->putJson("/api/v1/report-shares/{$share['id']}/notes", ['kind' => 'executive', 'body' => '<b>ملاحظة</b> معدلة'])->assertOk();

        $notes = $api()->getJson("/api/v1/report-shares/{$share['id']}/notes")->json('data');
        $this->assertCount(1, $notes['notes']);
        $this->assertSame('ملاحظة معدلة', $notes['notes'][0]['body']);
        $this->assertSame(2, $notes['notes'][0]['revision']);
        $this->assertSame(['أول ملاحظة'], array_column($notes['history'], 'body'));

        $api()->patchJson("/api/v1/report-shares/{$share['id']}", ['attribution_model' => 'first'])->assertOk();
        $revisions = $api()->getJson("/api/v1/report-shares/{$share['id']}/revisions")->json('data.revisions');
        $this->assertContains('attribution_model', $revisions[0]['changed']);
        $this->assertSame('first', $revisions[0]['configuration']['attribution_model']);
        $this->assertGreaterThanOrEqual(3, count($revisions));
    }

    public function test_the_activity_trail_records_what_happened_and_who_did_it(): void
    {
        $share = $this->make(['password' => 'secret1']);
        $api = fn () => $this->actingAs($this->t['admin']);

        $this->open($share['url'], ['X-Share-Password' => 'secret1'])->assertOk();
        $api()->patchJson("/api/v1/report-shares/{$share['id']}", ['password' => 'secret2'])->assertOk();
        $api()->postJson("/api/v1/report-shares/{$share['id']}/regenerate")->assertOk();
        $api()->postJson("/api/v1/report-shares/{$share['id']}/revoke")->assertOk();

        $actions = array_column($api()->getJson("/api/v1/report-shares/{$share['id']}/activity")->json('data.activity'), 'action');
        foreach (['created', 'published', 'viewed', 'password_changed', 'token_regenerated', 'revoked', 'edited'] as $expected) {
            $this->assertContains($expected, $actions);
        }
    }

    public function test_each_client_gets_a_link_of_their_own_with_their_own_protection(): void
    {
        $share = $this->make();
        $api = fn () => $this->actingAs($this->t['admin']);

        $one = $api()->postJson("/api/v1/report-shares/{$share['id']}/links", ['client_name' => 'العميل الأول', 'password' => 'pw-one'])->assertCreated()->json('data.link');
        $two = $api()->postJson("/api/v1/report-shares/{$share['id']}/links", ['client_name' => 'العميل الثاني'])->assertCreated()->json('data.link');

        $this->open($one['url'])->assertStatus(401);
        $this->open($one['url'], ['X-Share-Password' => 'pw-one'])->assertOk();
        $this->open($two['url'])->assertOk();

        $api()->postJson("/api/v1/report-shares/{$share['id']}/links/{$one['id']}/revoke")->assertOk();
        $this->open($one['url'], ['X-Share-Password' => 'pw-one'])->assertNotFound();
        $this->open($two['url'])->assertOk();

        $list = $api()->getJson('/api/v1/report-links')->json('data.links');
        $this->assertGreaterThanOrEqual(3, count($list));
        $this->assertContains('revoked', array_column($list, 'status'));
        $this->assertSame($share['name'], collect($list)->firstWhere('id', $two['id'])['report']['name']);
    }

    public function test_a_link_for_named_people_asks_for_a_code_sent_to_their_email(): void
    {
        $share = $this->make();
        $link = $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/links", ['allowed_emails' => ['client@firm.test'], 'require_otp' => true])->assertCreated()->json('data.link');

        $this->open($link['url'])->assertStatus(401)->assertJsonPath('data.otp_required', true);

        // Same answer whether or not the address is allowed: nothing to enumerate.
        $this->postJson('/api/v1/public/r/'.basename($link['url']).'/code', ['email' => 'stranger@else.test'])->assertOk()->assertJsonPath('data.sent', true);
        $this->assertSame(0, EmailDelivery::withoutGlobalScopes()->where('to_email', 'stranger@else.test')->count());

        $this->postJson('/api/v1/public/r/'.basename($link['url']).'/code', ['email' => 'client@firm.test'])->assertOk();
        preg_match('/: (\d{6})/', (string) EmailDelivery::withoutGlobalScopes()->where('to_email', 'client@firm.test')->latest('id')->value('html'), $m);

        $this->postJson('/api/v1/public/r/'.basename($link['url']).'/verify', ['email' => 'client@firm.test', 'code' => '000000'])->assertStatus(422);
        $pass = $this->postJson('/api/v1/public/r/'.basename($link['url']).'/verify', ['email' => 'client@firm.test', 'code' => $m[1]])->assertOk()->json('data.access');

        $this->open($link['url'], ['X-Share-Access' => $pass])->assertOk();
        // The pass belongs to that link only.
        $other = $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/links", ['require_otp' => true])->assertCreated()->json('data.link');
        $this->open($other['url'], ['X-Share-Access' => $pass])->assertStatus(401);
    }

    public function test_exports_follow_the_plan_the_link_and_the_sections_that_are_on(): void
    {
        $share = $this->make(['template' => 'client']);
        $api = fn () => $this->actingAs($this->t['admin']);

        foreach (['csv' => 'text/csv', 'xlsx' => 'spreadsheetml', 'pdf' => 'application/pdf'] as $format => $type) {
            $response = $api()->get("/api/v1/report-shares/{$share['id']}/export?format={$format}")->assertOk();
            $this->assertStringContainsString($type, (string) $response->headers->get('Content-Type'));
            // A real file of its kind, not a page that was named like one.
            $body = $response->baseResponse->getContent();
            $this->assertTrue(match ($format) {
                'pdf' => str_starts_with($body, '%PDF-'), 'xlsx' => str_starts_with($body, 'PK'), default => str_contains($body, 'name,value')
            }, "{$format} is a real {$format}");
        }

        // The client template carries no money: no export carries it either.
        $csv = $api()->get("/api/v1/report-shares/{$share['id']}/export?format=csv")->getContent();
        $this->assertStringNotContainsString('spend', $csv);
        $this->assertStringNotContainsString('roas', $csv);

        // Every export is on the trail.
        $this->assertContains('exported', array_column($api()->getJson("/api/v1/report-shares/{$share['id']}/activity")->json('data.activity'), 'action'));

        // A client's link downloads only when that link allows it.
        $this->get('/api/v1/public/r/'.basename($share['url']).'/export?format=csv')->assertForbidden();
        $link = $api()->postJson("/api/v1/report-shares/{$share['id']}/links", ['allow_export' => true])->assertCreated()->json('data.link');
        $this->get('/api/v1/public/r/'.basename($link['url']).'/export?format=csv')->assertOk();

        // A plan without Excel or PDF refuses them, and still gives the CSV.
        $this->putOnPlan($this->t['tenant'], 'lean');
        $this->t['admin'] = $this->t['admin']->fresh();
        $api()->get("/api/v1/report-shares/{$share['id']}/export?format=xlsx")->assertForbidden();
        $api()->get("/api/v1/report-shares/{$share['id']}/export?format=pdf")->assertForbidden();
        $api()->get("/api/v1/report-shares/{$share['id']}/export?format=csv")->assertOk();
    }

    public function test_a_plan_without_the_feature_cannot_use_it(): void
    {
        $share = $this->make();
        $this->putOnPlan($this->t['tenant'], 'lean');
        $this->t['admin'] = $this->t['admin']->fresh();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/links", ['require_otp' => true])->assertForbidden();
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/report-shares/{$share['id']}", ['attribution_model' => 'linear'])->assertForbidden();
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/report-shares/{$share['id']}", ['branding' => ['hide_mohtam' => true]])->assertForbidden();
    }

    public function test_branding_is_text_and_https_images_only(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/report-shares', ['name' => 'x', 'template' => 'client', 'branding' => ['logo_url' => 'javascript:alert(1)']])->assertStatus(422);
        $this->actingAs($this->t['admin'])->postJson('/api/v1/report-shares', ['name' => 'x', 'template' => 'client', 'branding' => ['color' => 'red; background:url(x)']])->assertStatus(422);

        $share = $this->make(['branding' => ['name' => '<script>alert(1)</script>الأفق', 'color' => '#112233', 'logo_url' => 'https://cdn.example.test/logo.png', 'footer' => '<img src=x onerror=1>للتواصل']]);

        $brand = $this->open($share['url'])->assertOk()->json('data.workspace');
        $this->assertSame('alert(1)الأفق', $brand['name']);
        $this->assertSame('#112233', $brand['color']);
        $this->assertSame('https://cdn.example.test/logo.png', $brand['logo_url']);
        $this->assertSame('للتواصل', $brand['footer']);
    }

    public function test_a_person_limited_to_some_projects_reaches_only_reports_about_those_projects(): void
    {
        $mine = $this->project('مشروعي');
        $other = $this->project('مشروع آخر');
        $manager = $this->makeUser($this->t['tenant'], Role::Manager, ['all_projects' => false]);
        $manager->projects()->attach($mine->id);

        $inside = $this->make(['name' => 'داخل', 'project_ids' => [$mine->uuid]]);
        $outside = $this->make(['name' => 'خارج', 'project_ids' => [$other->uuid]]);
        $everything = $this->make(['name' => 'الكل']);

        $names = array_column($this->actingAs($manager)->getJson('/api/v1/report-shares')->assertOk()->json('data.shares'), 'name');
        $this->assertSame(['داخل'], $names);

        $this->actingAs($manager)->getJson("/api/v1/report-shares/{$outside['id']}/links")->assertForbidden();
        $this->actingAs($manager)->postJson("/api/v1/report-shares/{$everything['id']}/archive")->assertForbidden();
        $this->actingAs($manager)->postJson('/api/v1/report-shares', ['name' => 'x', 'template' => 'client'])->assertStatus(422);
        $this->actingAs($manager)->postJson('/api/v1/report-shares', ['name' => 'x', 'template' => 'client', 'project_ids' => [$other->uuid]])->assertNotFound();
        $this->actingAs($manager)->postJson("/api/v1/report-shares/{$inside['id']}/links", [])->assertCreated();
    }

    public function test_another_workspaces_token_and_ids_open_nothing_of_ours(): void
    {
        $b = $this->provisionTenant('Other Co');
        $this->putOnPlan($b['tenant'], 'enterprise');
        $mine = $this->make(['name' => 'تقريرنا']);
        $theirs = $this->actingAs($b['admin'])->postJson('/api/v1/report-shares', ['name' => 'تقريرهم', 'template' => 'executive'])->assertCreated()->json('data.share');

        $this->actingAs($b['admin'])->getJson("/api/v1/report-shares/{$mine['id']}/links")->assertNotFound();
        $this->actingAs($b['admin'])->postJson("/api/v1/report-shares/{$mine['id']}/archive")->assertNotFound();
        $this->assertSame('تقريرهم', $this->open($theirs['url'])->assertOk()->json('data.report.name'));
        $this->assertSame('تقريرنا', $this->open($mine['url'])->assertOk()->json('data.report.name'));

        $list = $this->actingAs($b['admin'])->getJson('/api/v1/report-links')->json('data.links');
        $this->assertSame(['تقريرهم'], array_values(array_unique(array_map(fn ($l) => $l['report']['name'], $list))));
    }

    public function test_a_closed_subscription_closes_the_links_too(): void
    {
        $share = $this->make();
        $this->open($share['url'])->assertOk();

        $this->t['tenant']->forceFill(['subscription_status' => 'suspended', 'suspended_at' => now()])->save();
        $this->open($share['url'])->assertNotFound();
    }

    public function test_the_public_address_is_short_and_says_nothing_about_the_workspace(): void
    {
        $project = $this->project('برج سري');
        $share = $this->make(['project_ids' => [$project->uuid]]);

        $this->assertMatchesRegularExpression('#^https?://[^/]+/r/[A-Za-z0-9]{22}$#', $share['url']);
        $this->assertStringNotContainsString('library', strtolower($share['url']));
        $this->assertSame(1, ShortLink::where('type', 'report')->count());
        $this->assertStringNotContainsString((string) $project->uuid, $share['url']);
    }

    public function test_a_partner_report_never_carries_the_companys_money_however_it_was_switched_on(): void
    {
        $partner = $this->asTenant($this->t['tenant'], fn () => Partner::create(['name' => 'وسيط', 'type' => 'broker', 'is_active' => true]));
        $share = $this->make([
            'template' => 'partner', 'partner_id' => $partner->uuid,
            'sections' => ['kpi_volume' => true, 'kpi_cost' => true, 'kpi_revenue' => true, 'funnel' => true, 'marketing' => true, 'revenue' => true, 'sources' => true, 'campaigns' => true, 'team' => true, 'attention' => true],
        ]);

        $data = $this->open($share['url'])->assertOk()->json('data');
        $text = json_encode($data);

        $this->assertSame(['kpi_volume', 'funnel'], array_values(array_intersect($data['sections'], ['kpi_volume', 'kpi_cost', 'kpi_revenue', 'funnel', 'marketing', 'revenue', 'sources', 'campaigns', 'team', 'attention'])));
        foreach (['"spend"', '"cpl"', '"cac"', '"roas"', '"revenue"', '"cost_per_viewing"'] as $money) {
            $this->assertStringNotContainsString($money, $text);
        }
    }

    public function test_the_public_page_reads_the_partner_inside_the_workspace_so_the_limits_hold(): void
    {
        // The restriction rides on the partner row, which only the workspace can read: it must be read there.
        $partner = $this->asTenant($this->t['tenant'], fn () => Partner::create(['name' => 'وسيط', 'type' => 'broker', 'is_active' => true, 'show_revenue' => false]));
        $share = $this->make(['template' => 'partner', 'partner_id' => $partner->uuid, 'sections' => ['kpi_volume' => true, 'kpi_cost' => true, 'kpi_revenue' => true]]);

        $this->assertSame(['kpi_volume'], $this->open($share['url'])->assertOk()->json('data.sections'));

        // And when the partner cannot be read at all, it fails closed rather than open.
        $row = ReportShare::withoutGlobalScopes()->where('uuid', $share['id'])->first();
        $row->setRelation('partner', null);
        $plan = array_column(ReportTemplates::plan($row, 'public'), 'key');
        $this->assertNotContains('kpi_cost', $plan);
        $this->assertNotContains('kpi_revenue', $plan);
    }

    public function test_a_long_report_opens_in_two_halves_and_counts_as_one_view(): void
    {
        $share = $this->make(['template' => 'management']);
        $token = basename($share['url']);

        $whole = $this->getJson("/api/v1/public/r/{$token}")->assertOk()->json('data');
        $first = $this->getJson("/api/v1/public/r/{$token}?part=primary")->assertOk()->json('data');
        $second = $this->getJson("/api/v1/public/r/{$token}?part=secondary")->assertOk()->json('data');

        $this->assertSame($whole['sections'], $first['sections'], 'the page knows every section and its order from the first half');
        $this->assertArrayHasKey('kpis', $first);
        $this->assertArrayHasKey('funnel', $first);
        $this->assertArrayNotHasKey('campaigns', $first);
        $this->assertArrayHasKey('campaigns', $second);
        $this->assertArrayNotHasKey('kpis', $second);
        $this->assertSame('primary', $first['part']);

        // Together they are the whole report, and money stays allowed by the report, not by the half.
        $this->assertEquals(array_keys($whole['kpis']), array_keys($first['kpis']));
        foreach (['marketing', 'revenue', 'sales', 'attention'] as $section) {
            $this->assertArrayHasKey($section, $whole);
            $this->assertEquals($whole[$section], $second[$section]);
        }

        // One opening of the page is one view: the second half is not another.
        $this->assertSame(2, (int) ShortLink::withoutGlobalScopes()->first()->view_count, 'the whole and the first half counted; the second did not');
    }
}
