<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * White label is the commercial promise: two customers, one codebase, zero
 * deploys between them.
 */
class WhiteLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_branding_is_public_so_the_login_screen_can_render_it(): void
    {
        $t = $this->provisionTenant('Rawasi Development', [
            'primary_color' => '#0f766e',
            'logo_url' => 'https://cdn.example.com/rawasi.png',
        ]);

        $response = $this->getJson('/api/v1/branding?tenant='.$t['tenant']->slug);

        $response->assertOk()
            ->assertJsonPath('data.system_name', 'Rawasi Development CRM')
            ->assertJsonPath('data.primary_color', '#0f766e')
            ->assertJsonPath('data.logo_url', 'https://cdn.example.com/rawasi.png');
    }

    public function test_branding_never_leaks_anything_but_presentation(): void
    {
        $t = $this->provisionTenant('Private Co');

        $data = $this->getJson('/api/v1/branding?tenant='.$t['tenant']->slug)->json('data');

        foreach (['plan', 'subscription_status', 'users', 'leads', 'credentials'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    public function test_an_unknown_or_suspended_tenant_returns_null_branding(): void
    {
        $this->getJson('/api/v1/branding?tenant=does-not-exist')->assertOk()->assertJsonPath('data', null);

        $t = $this->provisionTenant('Suspended Co');
        $t['tenant']->update(['is_active' => false]);

        $this->getJson('/api/v1/branding?tenant='.$t['tenant']->slug)->assertOk()->assertJsonPath('data', null);
    }

    public function test_two_tenants_can_run_completely_different_identities(): void
    {
        $a = $this->provisionTenant('Alpha Homes', ['primary_color' => '#111111']);
        $b = $this->provisionTenant('Beta Estates', ['primary_color' => '#eeeeee']);

        $brandA = $this->getJson('/api/v1/branding?tenant='.$a['tenant']->slug)->json('data');
        $brandB = $this->getJson('/api/v1/branding?tenant='.$b['tenant']->slug)->json('data');

        $this->assertNotSame($brandA['system_name'], $brandB['system_name']);
        $this->assertSame('#111111', $brandA['primary_color']);
        $this->assertSame('#eeeeee', $brandB['primary_color']);
    }

    public function test_an_admin_can_rebrand_their_own_workspace(): void
    {
        $t = $this->provisionTenant('Before Co');

        $this->actingAs($t['admin'])->patchJson('/api/v1/settings/branding', [
            'system_name' => 'After CRM',
            'primary_color' => '#ff6600',
        ])->assertOk()->assertJsonPath('data.branding.system_name', 'After CRM');

        $this->assertSame('#ff6600', $this->getJson('/api/v1/branding?tenant='.$t['tenant']->slug)
            ->json('data.primary_color'));
    }

    public function test_a_non_admin_cannot_change_branding(): void
    {
        $t = $this->provisionTenant();
        $manager = $this->makeUser($t['tenant'], Role::Manager);

        $this->actingAs($manager)
            ->patchJson('/api/v1/settings/branding', ['system_name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_an_invalid_colour_is_rejected(): void
    {
        $t = $this->provisionTenant();

        $this->actingAs($t['admin'])
            ->patchJson('/api/v1/settings/branding', ['primary_color' => 'red'])
            ->assertStatus(422);
    }

    public function test_a_new_tenant_is_fully_usable_immediately(): void
    {
        $t = $this->provisionTenant('Instant Co');

        // Default pipeline reasons, settings and an inbound endpoint all exist.
        $settings = $this->actingAs($t['admin'])->getJson('/api/v1/settings')->assertOk()->json('data');

        $this->assertCount(10, $settings['lost_reasons']);
        $this->assertSame('ar', $settings['settings']['locale']);
        $this->assertSame('SAR', $settings['settings']['currency']);
        $this->assertNotEmpty($t['webhook_key']);
        $this->assertNotEmpty($t['webhook_secret']);
    }
}
