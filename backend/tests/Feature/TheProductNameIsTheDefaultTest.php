<?php

namespace Tests\Feature;

use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مهتم is the product. A workspace renames it only by choosing to.
 *
 * Provisioning put the client's company name into `system_name` whenever nobody
 * supplied one, and the branding resource fell back to the tenant's name when
 * there was no branding — so a workspace nobody had customised called the product
 * by its client's name on every screen. Neither the default nor the fallback was
 * a decision anybody had made.
 */
class TheProductNameIsTheDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_workspace_nobody_renamed_does_not_carry_its_clients_name_as_the_products(): void
    {
        $result = app(TenantProvisioningService::class)->provision([
            'company_name' => 'شركة رواسي للتطوير العقاري',
            'subdomain' => 'rawasi-unnamed',
            'admin_name' => 'عبدالله',
            'admin_email' => 'admin@unnamed.test',
            'admin_password' => 'password123',
        ]);

        $this->assertNull($result['tenant']->branding->system_name);

        $this->getJson('/api/v1/branding?tenant=rawasi-unnamed')
            ->assertOk()
            ->assertJsonPath('data.system_name', null)
            // The client's own company is still theirs to show.
            ->assertJsonPath('data.company_name', 'شركة رواسي للتطوير العقاري');
    }

    public function test_a_name_the_customer_chose_is_kept(): void
    {
        $t = $this->provisionTenant('Chosen Co');

        $this->getJson('/api/v1/branding?tenant='.$t['tenant']->slug)
            ->assertOk()
            ->assertJsonPath('data.system_name', 'Chosen Co CRM');
    }

    public function test_clearing_the_name_returns_to_the_product(): void
    {
        $t = $this->provisionTenant('Reverting Co');

        $this->actingAs($t['admin'])
            ->patchJson('/api/v1/settings/branding', ['system_name' => ''])
            ->assertOk()
            ->assertJsonPath('data.branding.system_name', null);

        $this->assertNull($t['tenant']->fresh()->branding->system_name);
    }
}
