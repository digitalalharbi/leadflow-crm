<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportCache;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\ReportShare;
use App\Models\ShortLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §49 of the reporting specification, one test per line of it: tenant and project isolation, ownership, token entropy,
 * password, expiry, revocation, cache isolation, export security, section permissions, public PII and rate limiting.
 */
class ReportSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha Co');
        $this->b = $this->provisionTenant('Beta Co');
        $this->putOnPlan($this->a['tenant'], 'enterprise');
        $this->putOnPlan($this->b['tenant'], 'enterprise');
    }

    private function plant(array $t, string $name, string $phone = '0551110000'): void
    {
        $this->asTenant($t['tenant'], function () use ($name, $phone) {
            $p = Project::create(['name' => $name]);
            $c = Campaign::create(['platform' => 'meta', 'name' => 'حملة '.$name, 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR', 'project_id' => $p->id]);
            MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $c->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 55_500, 'currency' => 'SAR']);
            (new Lead)->forceFill(['full_name' => 'سري '.$name, 'phone' => $phone, 'email' => strtolower($name).'@pii.test', 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => 'won', 'deal_value_minor' => 1_000_000, 'won_at' => now()->subDay(), 'platform' => 'meta', 'project_id' => $p->id, 'campaign_ref_id' => $c->id, 'created_at' => now()->subDays(2)])->save();
        });
    }

    private function share(array $t, array $extra = []): array
    {
        return $this->actingAs($t['admin'])->postJson('/api/v1/report-shares', $extra + ['name' => 'تقرير '.$t['tenant']->name, 'template' => 'management'])->assertCreated()->json('data.share');
    }

    private function open(string $url, array $headers = [])
    {
        return $this->withHeaders($headers)->getJson('/api/v1/public/r/'.basename($url));
    }

    public function test_a_company_token_never_opens_another_companys_numbers(): void
    {
        $this->plant($this->a, 'Alpha');
        $this->plant($this->b, 'Beta');
        $a = $this->share($this->a);
        $b = $this->share($this->b);

        $textA = json_encode($this->open($a['url'])->assertOk()->json('data'), JSON_UNESCAPED_UNICODE);
        $textB = json_encode($this->open($b['url'])->assertOk()->json('data'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('حملة Alpha', $textA);
        $this->assertStringNotContainsString('Beta', $textA);
        $this->assertStringContainsString('حملة Beta', $textB);
        $this->assertStringNotContainsString('Alpha', $textB);
    }

    public function test_a_report_never_reads_a_project_it_does_not_cover(): void
    {
        $this->plant($this->a, 'Alpha');
        $second = $this->asTenant($this->a['tenant'], fn () => Project::create(['name' => 'Secret Tower']));
        $this->asTenant($this->a['tenant'], fn () => (new Lead)->forceFill(['full_name' => 'x', 'phone' => '0551', 'phone_normalized' => '+966511111111', 'status' => 'new', 'project_id' => $second->id, 'platform' => 'meta', 'created_at' => now()->subDay()])->save());
        $alpha = $this->asTenant($this->a['tenant'], fn () => Project::where('name', 'Alpha')->first());

        $data = $this->open($this->share($this->a, ['project_ids' => [$alpha->uuid], 'template' => 'project'])['url'])->assertOk()->json('data');

        $this->assertSame(1, $data['kpis']['leads']);
        $this->assertStringNotContainsString('Secret Tower', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_token_is_long_random_hashed_at_rest_and_says_nothing_about_the_workspace(): void
    {
        $this->plant($this->a, 'Alpha');
        $share = $this->share($this->a);
        $token = basename($share['url']);
        $row = DB::table('short_links')->first();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{22}$/', $token);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode($row), 'the clear token is not stored in the clear');
        $this->assertStringNotContainsStringIgnoringCase('alpha', $share['url']);
        $this->assertDoesNotMatchRegularExpression('#/\d+(/|$)#', parse_url($share['url'], PHP_URL_PATH), 'no sequential id in the address');

        $tokens = collect(range(1, 12))->map(fn () => basename($this->share($this->a)['url']))->push($token);
        $this->assertCount(13, $tokens->unique());
    }

    public function test_a_guess_is_a_plain_404_and_a_token_of_the_wrong_kind_is_too(): void
    {
        $this->getJson('/api/v1/public/r/'.str_repeat('A', 22))->assertNotFound();
        $this->getJson('/api/v1/public/r/short')->assertNotFound();
        $this->getJson('/api/v1/public/r/'.str_repeat('a', 22).'/export?format=csv')->assertNotFound();

        // A tracking token is not a report token.
        [, $other] = $this->asTenant($this->a['tenant'], fn () => app(\App\Domain\Links\ShortLinks::class)->issue('form', null, null, null));
        $this->getJson('/api/v1/public/r/'.$other)->assertNotFound();
    }

    public function test_a_wrong_password_is_refused_and_throttled_and_expiry_and_revocation_close_the_link(): void
    {
        $this->plant($this->a, 'Alpha');
        $share = $this->share($this->a, ['password' => 'right-pass', 'expires_at' => now()->addDay()->toIso8601String()]);

        $this->open($share['url'], ['X-Share-Password' => 'wrong'])->assertStatus(401)->assertJsonMissingPath('data.kpis');
        $this->open($share['url'], ['X-Share-Password' => 'right-pass'])->assertOk();

        ShortLink::withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);
        $this->open($share['url'], ['X-Share-Password' => 'right-pass'])->assertStatus(410);

        ShortLink::withoutGlobalScopes()->update(['expires_at' => null]);
        $this->actingAs($this->a['admin'])->postJson("/api/v1/report-shares/{$share['id']}/revoke")->assertOk();
        $this->open($share['url'], ['X-Share-Password' => 'right-pass'])->assertNotFound();
    }

    public function test_the_public_endpoints_are_throttled(): void
    {
        $token = str_repeat('Z', 22);
        $codes = collect(range(1, 12))->map(fn () => $this->postJson("/api/v1/public/r/{$token}/code", ['email' => 'a@b.test'])->status());
        $this->assertContains(429, $codes->all(), 'asking for codes is limited');

        $verifies = collect(range(1, 15))->map(fn () => $this->postJson("/api/v1/public/r/{$token}/verify", ['email' => 'a@b.test', 'code' => '000000'])->status());
        $this->assertContains(429, $verifies->all(), 'guessing codes is limited');
    }

    public function test_a_public_report_carries_no_contact_detail_or_internal_id_whatever_is_on(): void
    {
        $this->plant($this->a, 'Alpha', '0559998877');
        $share = $this->share($this->a, ['options' => ['expose_team' => true, 'show_unit_prices' => true]]);

        $data = $this->open($share['url'])->assertOk()->json('data');
        $text = json_encode($data, JSON_UNESCAPED_UNICODE);

        foreach (['0559998877', 'alpha@pii.test', '@pii.test', 'سري Alpha', '+9665'] as $pii) {
            $this->assertStringNotContainsString($pii, $text, "{$pii} leaked into a public report");
        }

        $project = $this->asTenant($this->a['tenant'], fn () => Project::first());
        $this->assertStringNotContainsString($project->uuid, $text, 'a project uuid leaked');
        $this->assertStringNotContainsString($this->a['tenant']->uuid ?? 'no-uuid', $text);
        // Names of campaigns and projects are the report's own words; row ids are not.
        $this->assertDoesNotMatchRegularExpression('/"(id|tenant_id|project_id|lead_id|campaign_id)":\s*\d+/', $text);
    }

    public function test_the_cache_is_keyed_by_workspace_report_revision_audience_sections_and_period(): void
    {
        $cache = app(ReportCache::class);
        $this->plant($this->a, 'Alpha');
        $one = ReportShare::withoutGlobalScopes()->find($this->share($this->a)['id'] ? ReportShare::withoutGlobalScopes()->where('tenant_id', $this->a['tenant']->id)->value('id') : 0);
        $keys = [
            'base' => $cache->key($one, 'public', ['kpi_volume']),
            'audience' => $cache->key($one, 'team', ['kpi_volume']),
            'sections' => $cache->key($one, 'public', ['kpi_volume', 'kpi_revenue']),
        ];

        $changed = clone $one;
        $changed->revision = $one->revision + 1;
        $keys['revision'] = $cache->key($changed, 'public', ['kpi_volume']);
        $moved = clone $one;
        $moved->tenant_id = $this->b['tenant']->id;
        $keys['tenant'] = $cache->key($moved, 'public', ['kpi_volume']);
        $filtered = clone $one;
        $filtered->filters = ['platforms' => ['meta']];
        $keys['filters'] = $cache->key($filtered, 'public', ['kpi_volume']);

        $this->assertCount(count($keys), array_unique($keys), 'every one of these must be a different cache entry');
        $this->assertStringContainsString('t'.$this->a['tenant']->id, $keys['base']);
    }

    public function test_cached_numbers_say_so_and_never_cross_workspaces_and_never_serve_a_revoked_link(): void
    {
        config(['links.report_cache_seconds' => 30]);
        $this->plant($this->a, 'Alpha');
        $this->plant($this->b, 'Beta');
        $a = $this->share($this->a);
        $b = $this->share($this->b);

        $first = $this->open($a['url'])->assertOk()->json('data.report');
        $second = $this->open($a['url'])->assertOk()->json('data.report');
        $this->assertFalse($first['cached']);
        $this->assertTrue($second['cached']);
        $this->assertArrayHasKey('cache_age_seconds', $second);

        // The other workspace's identical report is its own entry with its own numbers.
        $theirs = $this->open($b['url'])->assertOk();
        $this->assertFalse($theirs->json('data.report.cached'));
        $this->assertStringNotContainsString('Alpha', json_encode($theirs->json('data'), JSON_UNESCAPED_UNICODE));

        // A revoked link answers 404 even though its numbers are still warm.
        $this->actingAs($this->a['admin'])->postJson("/api/v1/report-shares/{$a['id']}/revoke")->assertOk();
        $this->open($a['url'])->assertNotFound();

        // A change of setup is a new revision, hence a new entry: the old numbers are not served for it.
        $c = $this->share($this->a);
        $this->open($c['url'])->assertOk();
        $this->actingAs($this->a['admin'])->patchJson("/api/v1/report-shares/{$c['id']}", ['sections' => ['kpi_volume' => true]])->assertOk();
        $this->assertFalse($this->open($c['url'])->json('data.report.cached'));
        Cache::flush();
    }

    public function test_exports_never_carry_a_section_the_report_has_off_and_are_never_another_workspaces(): void
    {
        $this->plant($this->a, 'Alpha');
        $this->plant($this->b, 'Beta');
        $client = $this->share($this->a, ['template' => 'client']);
        $theirs = $this->share($this->b, ['template' => 'management']);

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->actingAs($this->a['admin'])->get("/api/v1/report-shares/{$theirs['id']}/export?format={$format}")->assertNotFound();
        }

        $csv = $this->actingAs($this->a['admin'])->get("/api/v1/report-shares/{$client['id']}/export?format=csv")->assertOk()->getContent();
        foreach (['spend', 'roas', 'cpl', 'revenue', 'campaigns', 'team', 'Beta'] as $absent) {
            $this->assertStringNotContainsString($absent, $csv, "{$absent} must not be in a client export");
        }

        // A csv cell that starts like a formula is text, not a formula.
        $this->actingAs($this->a['admin'])->patchJson("/api/v1/report-shares/{$client['id']}", ['name' => '=cmd|calc'])->assertOk();
        $this->assertStringContainsString("'=cmd|calc", $this->actingAs($this->a['admin'])->get("/api/v1/report-shares/{$client['id']}/export?format=csv")->getContent());

        // Every export is on the trail, with its format.
        $actions = $this->actingAs($this->a['admin'])->getJson("/api/v1/report-shares/{$client['id']}/activity")->json('data.activity');
        $this->assertGreaterThanOrEqual(2, collect($actions)->where('action', 'exported')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'report_share.exported']);
    }

    public function test_a_person_without_a_permission_cannot_put_the_section_into_a_report_or_read_it_in_the_preview(): void
    {
        $this->plant($this->a, 'Alpha');
        $manager = $this->makeUser($this->a['tenant'], \App\Enums\Role::Manager);
        $this->asTenant($this->a['tenant'], fn () => \App\Models\PermissionRule::create(['layer' => 'role', 'role_key' => 'manager', 'permission' => 'revenue.view', 'effect' => 'deny']));
        \App\Domain\Access\AccessResolver::flush();
        $manager = $manager->fresh();

        $this->actingAs($manager)->postJson('/api/v1/report-shares', ['name' => 'x', 'template' => 'client', 'sections' => ['kpi_volume' => true, 'revenue' => true]])->assertStatus(422);

        $full = $this->share($this->a);
        $preview = $this->actingAs($manager)->getJson("/api/v1/report-shares/{$full['id']}/preview")->assertOk()->json('data');
        $this->assertContains('revenue', $preview['withheld']);
        $this->assertArrayNotHasKey('revenue', $preview);
        $this->assertArrayNotHasKey('kpis_revenue', $preview);
        $this->assertArrayNotHasKey('revenue', $preview['kpis'] ?? []);
    }

    public function test_only_people_who_may_share_reports_can_reach_the_library_at_all(): void
    {
        $rep = $this->makeUser($this->a['tenant'], \App\Enums\Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/report-shares')->assertForbidden();
        $this->actingAs($rep)->getJson('/api/v1/report-links')->assertForbidden();
        $this->actingAs($rep)->postJson('/api/v1/report-shares', ['name' => 'x', 'template' => 'client'])->assertForbidden();
    }

    public function test_nothing_in_the_library_answers_without_a_session(): void
    {
        $this->getJson('/api/v1/report-shares')->assertUnauthorized();
        $this->getJson('/api/v1/report-links')->assertUnauthorized();
        $this->postJson('/api/v1/report-shares', ['name' => 'x', 'template' => 'client'])->assertUnauthorized();
        $this->getJson('/api/v1/funnel')->assertUnauthorized();
    }
}
