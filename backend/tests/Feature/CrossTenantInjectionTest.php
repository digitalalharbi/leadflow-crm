<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Project;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foreign keys arriving from outside must never be trusted.
 *
 * `leads.project_id` points at a global `projects.id`. If an untrusted payload
 * can set it, a lead in tenant A can reference tenant B's project — and the
 * reports join would then render B's project name to A. These tests close that
 * door at every entry point: webhook, integration config, and CSV import.
 */
class CrossTenantInjectionTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    private Project $foreignProject;

    private string $secret = 'injection-test-secret-0123456789';

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->provisionTenant('Alpha Realty');
        $this->b = $this->provisionTenant('Beta Realty');

        $this->foreignProject = $this->asTenant(
            $this->b['tenant'],
            fn () => Project::create(['name' => 'SECRET-BETA-PROJECT'])
        );

        $endpoint = $this->asTenant($this->a['tenant'], fn () => WebhookEndpoint::first());
        $endpoint->forceFill(['secret_hash' => Hash::make($this->secret)])->saveQuietly();
        $this->key = $endpoint->key;
    }

    private function ingest(array $payload)
    {
        return $this->withHeader('X-Webhook-Secret', $this->secret)
            ->postJson("/api/v1/integrations/webhooks/{$this->key}/leads", $payload);
    }

    public function test_a_webhook_cannot_attach_a_lead_to_another_tenants_project(): void
    {
        $this->ingest([
            'name' => 'Probe',
            'phone' => '0512345678',
            'project_id' => (string) $this->foreignProject->id,
        ])->assertOk();

        $lead = Lead::withoutGlobalScopes()->first();

        $this->assertSame($this->a['tenant']->id, $lead->tenant_id);
        $this->assertNull($lead->project_id, 'A foreign project id must be discarded, not stored.');
    }

    public function test_the_project_report_never_renders_another_tenants_project(): void
    {
        // Force the hostile value in directly, so the report is tested even if a
        // future write path regresses.
        $this->ingest(['name' => 'Probe', 'phone' => '0512345678'])->assertOk();

        Lead::withoutGlobalScopes()->first()
            ->forceFill(['project_id' => $this->foreignProject->id])->saveQuietly();

        $rows = $this->actingAs($this->a['admin'])
            ->getJson('/api/v1/reports?type=project')
            ->assertOk()
            ->json('data.rows');

        $this->assertNotContains('SECRET-BETA-PROJECT', array_column($rows, 'label'));
    }

    public function test_the_sales_rep_report_never_renders_another_tenants_user(): void
    {
        $foreignUser = $this->b['admin'];

        $this->ingest(['name' => 'Probe', 'phone' => '0512345678'])->assertOk();

        Lead::withoutGlobalScopes()->first()
            ->forceFill(['assigned_to' => $foreignUser->id])->saveQuietly();

        $rows = $this->actingAs($this->a['admin'])
            ->getJson('/api/v1/reports?type=sales_rep')
            ->json('data.rows');

        $this->assertNotContains($foreignUser->name, array_column($rows, 'label'));
    }

    public function test_the_lost_reason_report_never_renders_another_tenants_reason(): void
    {
        $foreignReason = $this->asTenant(
            $this->b['tenant'],
            fn () => LostReason::create(['label' => 'SECRET-BETA-REASON'])
        );

        $this->ingest(['name' => 'Probe', 'phone' => '0512345678'])->assertOk();

        Lead::withoutGlobalScopes()->first()->forceFill([
            'status' => 'lost',
            'lost_at' => now(),
            'lost_reason_id' => $foreignReason->id,
        ])->saveQuietly();

        $rows = $this->actingAs($this->a['admin'])
            ->getJson('/api/v1/reports?type=lost_reason')
            ->json('data.rows');

        $this->assertNotContains('SECRET-BETA-REASON', array_column($rows, 'label'));
    }

    public function test_an_integration_default_cannot_point_at_another_tenants_project(): void
    {
        $this->actingAs($this->a['admin'])->postJson('/api/v1/integrations', [
            'provider' => 'generic_webhook',
            'defaults' => ['project_id' => (string) $this->foreignProject->id],
        ])->assertStatus(422);
    }

    public function test_an_integration_field_mapping_cannot_target_an_internal_key(): void
    {
        $this->actingAs($this->a['admin'])->postJson('/api/v1/integrations', [
            'provider' => 'generic_webhook',
            'field_mapping' => ['ref' => 'project_id'],
        ])->assertStatus(422);

        $this->actingAs($this->a['admin'])->postJson('/api/v1/integrations', [
            'provider' => 'generic_webhook',
            'field_mapping' => ['ref' => 'tenant_id'],
        ])->assertStatus(422);
    }

    public function test_a_csv_import_cannot_map_a_column_to_an_internal_key(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()
            ->createWithContent('leads.csv', "name,ref\nProbe,{$this->foreignProject->id}\n");

        $importId = $this->actingAs($this->a['admin'])
            ->post('/api/v1/imports', ['file' => $file])
            ->json('data.import.id');

        $this->actingAs($this->a['admin'])->postJson("/api/v1/imports/{$importId}/process", [
            'mapping' => ['name' => 'full_name', 'ref' => 'project_id'],
        ])->assertStatus(422);

        $this->actingAs($this->a['admin'])->postJson("/api/v1/imports/{$importId}/process", [
            'mapping' => ['name' => 'full_name'],
            'defaults' => ['tenant_id' => $this->b['tenant']->id],
        ])->assertStatus(422);
    }

    public function test_a_lead_update_cannot_point_at_another_tenants_project(): void
    {
        $this->ingest(['name' => 'Probe', 'phone' => '0512345678'])->assertOk();
        $lead = Lead::withoutGlobalScopes()->first();

        $this->actingAs($this->a['admin'])
            ->patchJson("/api/v1/leads/{$lead->uuid}", ['project_id' => $this->foreignProject->uuid])
            ->assertNotFound();

        $this->assertNull($lead->fresh()->project_id);
    }
}
