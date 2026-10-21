<?php

namespace Tests\Feature;

use App\Domain\Automation\Thresholds;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "The workspace's settings" means this workspace's — not whichever row was written first. */
class TenantSettingsIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_a_workspace_bound_its_own_settings_are_the_ones_read(): void
    {
        $a = $this->provisionTenant('First Co');
        $b = $this->provisionTenant('Second Co');

        $a['tenant']->settings->forceFill(['first_contact_target_minutes' => 11, 'sla_hot_minutes' => 7])->save();
        $b['tenant']->settings->forceFill(['first_contact_target_minutes' => 99, 'sla_hot_minutes' => 33])->save();

        $this->assertSame(11, $this->asTenant($a['tenant'], fn () => (int) TenantSetting::query()->value('first_contact_target_minutes')));
        $this->assertSame(99, $this->asTenant($b['tenant'], fn () => (int) TenantSetting::query()->value('first_contact_target_minutes')));

        Thresholds::flush();
        $this->assertSame(7, $this->asTenant($a['tenant'], fn () => Thresholds::workspace('sla_hot_minutes')));
        Thresholds::flush();
        $this->assertSame(33, $this->asTenant($b['tenant'], fn () => Thresholds::workspace('sla_hot_minutes')));

        // And an API read answers for the person asking.
        $this->actingAs($b['admin'])->getJson('/api/v1/anomaly-settings')->assertOk();
    }

    public function test_with_no_workspace_bound_nothing_changes_for_the_screens_that_read_before_sign_in(): void
    {
        $a = $this->provisionTenant('Only Co');

        $this->assertSame($a['tenant']->id, TenantSetting::query()->first()->tenant_id);
        $this->assertSame($a['tenant']->id, $a['tenant']->settings->tenant_id);
    }
}
