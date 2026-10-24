<?php

namespace Tests\Feature;

use App\Domain\Access\AccessResolver;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\PermissionRule;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /funnel and the places that carry it: for whoever asks, inside their own workspace and projects. */
class FunnelEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $t, Project $p, string $status = 'new', array $extra = []): void
    {
        $this->asTenant($t['tenant'], function () use ($p, $status, $extra) {
            (new Lead)->forceFill(array_merge(['full_name' => 'x', 'phone' => '0551', 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => $status, 'project_id' => $p->id, 'platform' => 'meta'], $extra))->save();
        });
    }

    public function test_the_funnel_is_the_official_six_and_counts_only_the_callers_workspace(): void
    {
        $a = $this->provisionTenant('A Co');
        $b = $this->provisionTenant('B Co');
        $pa = $this->asTenant($a['tenant'], fn () => Project::create(['name' => 'A']));
        $pb = $this->asTenant($b['tenant'], fn () => Project::create(['name' => 'B']));
        $this->lead($a, $pa);
        $this->lead($a, $pa, 'contacted');
        foreach (range(1, 5) as $_) {
            $this->lead($b, $pb);
        }

        $data = $this->actingAs($a['admin'])->getJson('/api/v1/funnel')->assertOk()->json('data');

        $this->assertSame(['awareness', 'interest', 'engagement', 'booking', 'decision', 'referral'], array_column($data['stages'], 'key'));
        $this->assertSame(['وعي', 'اهتمام', 'تفاعل', 'حجز معاينة', 'قرار شراء', 'إحالة'], array_column($data['stages'], 'label'));
        $this->assertSame(2, $data['stages'][1]['count']);
        $this->assertSame(1, $data['stages'][2]['count']);
    }

    public function test_a_project_the_caller_cannot_see_matches_nothing_rather_than_everything(): void
    {
        $a = $this->provisionTenant('A Co');
        $b = $this->provisionTenant('B Co');
        $pa = $this->asTenant($a['tenant'], fn () => Project::create(['name' => 'A']));
        $pb = $this->asTenant($b['tenant'], fn () => Project::create(['name' => 'B']));
        $this->lead($a, $pa);

        $data = $this->actingAs($a['admin'])->getJson('/api/v1/funnel?project_id[]='.$pb->uuid)->assertOk()->json('data');
        $this->assertSame(0, $data['stages'][1]['count']);

        $data = $this->actingAs($a['admin'])->getJson('/api/v1/funnel?project_id[]='.$pa->uuid)->assertOk()->json('data');
        $this->assertSame(1, $data['stages'][1]['count']);
    }

    public function test_revenue_needs_permission_and_the_dashboard_carries_the_same_funnel(): void
    {
        $t = $this->provisionTenant('R Co');
        $p = $this->asTenant($t['tenant'], fn () => Project::create(['name' => 'P']));
        $this->lead($t, $p, 'won', ['deal_value_minor' => 50_000_00, 'won_at' => now()]);

        $admin = $this->actingAs($t['admin'])->getJson('/api/v1/funnel')->json('data');
        $this->assertEquals(50000.0, $admin['revenue']);

        $manager = $this->makeUser($t['tenant'], Role::Manager);
        $this->asTenant($t['tenant'], fn () => PermissionRule::create(['layer' => 'role', 'role_key' => 'manager', 'permission' => 'revenue.view', 'effect' => 'deny']));
        AccessResolver::flush();
        $this->assertArrayNotHasKey('revenue', $this->actingAs($manager->fresh())->getJson('/api/v1/funnel')->assertOk()->json('data'));

        $dash = $this->actingAs($t['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertSame(array_column($admin['stages'], 'count'), array_column($dash['real_estate_funnel']['stages'], 'count'));
    }

    public function test_the_project_page_carries_the_funnel_for_that_project_only(): void
    {
        $t = $this->provisionTenant('P Co');
        $p1 = $this->asTenant($t['tenant'], fn () => Project::create(['name' => 'One']));
        $p2 = $this->asTenant($t['tenant'], fn () => Project::create(['name' => 'Two']));
        $this->lead($t, $p1);
        $this->lead($t, $p2);
        $this->lead($t, $p2);

        $data = $this->actingAs($t['admin'])->getJson("/api/v1/projects/{$p2->uuid}/acquisition")->assertOk()->json('data');
        $this->assertSame(2, $data['real_estate_funnel']['stages'][1]['count']);
    }
}
