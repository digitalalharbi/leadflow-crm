<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The single most important guarantee in a multi-tenant SaaS: a user of tenant A
 * must not be able to read, change or even confirm the existence of tenant B's
 * data — not through a list, not through a guessed UUID, not through a filter.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    private Lead $leadA;

    private Lead $leadB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->provisionTenant('Alpha Realty');
        $this->b = $this->provisionTenant('Beta Realty');

        $this->leadA = $this->asTenant($this->a['tenant'], fn () => Lead::create([
            'full_name' => 'Alpha Lead', 'phone' => '0511111111',
            'phone_normalized' => '+966511111111',
        ]));

        $this->leadB = $this->asTenant($this->b['tenant'], fn () => Lead::create([
            'full_name' => 'Beta Lead', 'phone' => '0522222222',
            'phone_normalized' => '+966522222222',
        ]));
    }

    public function test_a_lead_list_only_ever_contains_the_callers_tenant(): void
    {
        $response = $this->actingAs($this->a['admin'])->getJson('/api/v1/leads');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('full_name');

        $this->assertContains('Alpha Lead', $names);
        $this->assertNotContains('Beta Lead', $names);
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_reading_another_tenants_lead_by_uuid_returns_404(): void
    {
        // The attacker knows the exact UUID. It must still be invisible.
        $this->actingAs($this->a['admin'])
            ->getJson("/api/v1/leads/{$this->leadB->uuid}")
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonMissing(['full_name' => 'Beta Lead']);
    }

    public function test_updating_another_tenants_lead_returns_404(): void
    {
        $this->actingAs($this->a['admin'])
            ->patchJson("/api/v1/leads/{$this->leadB->uuid}", ['full_name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Beta Lead', $this->leadB->fresh()->full_name);
    }

    public function test_deleting_another_tenants_lead_returns_404(): void
    {
        $this->actingAs($this->a['admin'])
            ->deleteJson("/api/v1/leads/{$this->leadB->uuid}")
            ->assertNotFound();

        $this->assertNotSoftDeleted('leads', ['id' => $this->leadB->id]);
    }

    public function test_changing_the_status_of_another_tenants_lead_returns_404(): void
    {
        $this->actingAs($this->a['admin'])
            ->postJson("/api/v1/leads/{$this->leadB->uuid}/status", ['status' => 'won'])
            ->assertNotFound();

        $this->assertNull($this->leadB->fresh()->won_at);
    }

    public function test_a_lead_cannot_be_assigned_to_a_user_from_another_tenant(): void
    {
        $outsider = $this->b['admin'];

        $this->actingAs($this->a['admin'])
            ->postJson("/api/v1/leads/{$this->leadA->uuid}/assign", ['assigned_to' => $outsider->uuid])
            ->assertStatus(422);

        $this->assertNull($this->leadA->fresh()->assigned_to);
    }

    public function test_projects_are_isolated(): void
    {
        $projectB = $this->asTenant($this->b['tenant'], fn () => Project::create(['name' => 'Beta Towers']));

        $response = $this->actingAs($this->a['admin'])->getJson('/api/v1/projects');
        $this->assertNotContains('Beta Towers', collect($response->json('data.projects'))->pluck('name'));

        $this->actingAs($this->a['admin'])
            ->getJson("/api/v1/projects/{$projectB->uuid}")
            ->assertNotFound();
    }

    public function test_users_are_isolated(): void
    {
        $response = $this->actingAs($this->a['admin'])->getJson('/api/v1/users');

        $emails = collect($response->json('data.users'))->pluck('email');
        $this->assertContains($this->a['admin']->email, $emails);
        $this->assertNotContains($this->b['admin']->email, $emails);
    }

    public function test_a_user_from_another_tenant_cannot_be_edited(): void
    {
        $this->actingAs($this->a['admin'])
            ->patchJson("/api/v1/users/{$this->b['admin']->uuid}", ['name' => 'Hijacked'])
            ->assertNotFound();
    }

    public function test_follow_ups_are_isolated(): void
    {
        $this->asTenant($this->b['tenant'], fn () => FollowUp::create([
            'lead_id' => $this->leadB->id,
            'assigned_to' => $this->b['admin']->id,
            'due_at' => now(),
            'note' => 'Beta follow up',
        ]));

        $response = $this->actingAs($this->a['admin'])->getJson('/api/v1/follow-ups?bucket=all');

        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_dashboard_totals_never_include_another_tenant(): void
    {
        $this->asTenant($this->b['tenant'], function () {
            for ($i = 0; $i < 5; $i++) {
                Lead::create([
                    'full_name' => "Beta {$i}",
                    'phone_normalized' => '+96653000000'.$i,
                ]);
            }
        });

        $response = $this->actingAs($this->a['admin'])->getJson('/api/v1/dashboard');

        $this->assertSame(1, $response->json('data.summary.total_leads'));
    }

    public function test_reports_never_aggregate_across_tenants(): void
    {
        $response = $this->actingAs($this->a['admin'])->getJson('/api/v1/reports?type=platform');

        $this->assertSame(1, $response->json('data.totals.total'));
    }

    public function test_the_same_phone_number_can_exist_in_two_different_tenants(): void
    {
        // Duplicate detection is per tenant, not global — two agencies may well
        // both be talking to the same buyer.
        $leadInB = $this->asTenant($this->b['tenant'], fn () => Lead::create([
            'full_name' => 'Shared Buyer',
            'phone' => '0511111111',
            'phone_normalized' => '+966511111111',
        ]));

        $this->assertNotSame($this->leadA->id, $leadInB->id);
        $this->assertSame('+966511111111', $leadInB->phone_normalized);
    }

    public function test_a_tenant_user_cannot_reach_the_super_admin_console(): void
    {
        foreach ([Role::Admin, Role::Manager, Role::Sales] as $role) {
            $user = $role === Role::Admin
                ? $this->a['admin']
                : $this->makeUser($this->a['tenant'], $role);

            $this->actingAs($user)->getJson('/api/v1/super-admin/tenants')->assertNotFound();
            $this->actingAs($user)->postJson('/api/v1/super-admin/tenants', [])->assertNotFound();
        }
    }

    public function test_a_model_cannot_be_created_without_a_tenant_context(): void
    {
        app(TenantContext::class)->clear();

        $this->expectException(\RuntimeException::class);

        Lead::create(['full_name' => 'Orphan', 'phone_normalized' => '+966599999999']);
    }

    public function test_only_a_super_admin_may_exist_without_a_tenant(): void
    {
        // An orphan user can neither sign in nor be governed by a policy, so the
        // model refuses to create one. This guards seeders and admin tooling.
        $this->expectException(\RuntimeException::class);

        User::create([
            'tenant_id' => null,
            'name' => 'Orphan Rep',
            'email' => 'orphan@example.test',
            'password' => 'password123',
            'role' => Role::Sales,
        ]);
    }

    public function test_a_super_admin_may_exist_without_a_tenant(): void
    {
        $owner = User::create([
            'tenant_id' => null,
            'name' => 'Owner',
            'email' => 'owner2@example.test',
            'password' => 'password123',
            'role' => Role::SuperAdmin,
        ]);

        $this->assertNull($owner->tenant_id);
        $this->assertTrue($owner->isSuperAdmin());
    }

    public function test_a_lead_cannot_be_moved_to_another_tenant(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->asTenant($this->a['tenant'], function () {
            $lead = Lead::first();
            $lead->tenant_id = $this->b['tenant']->id;
            $lead->save();
        });
    }
}
