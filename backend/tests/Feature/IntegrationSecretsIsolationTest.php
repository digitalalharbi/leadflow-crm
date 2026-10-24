<?php

namespace Tests\Feature;

use App\Models\AdConnection;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A company's own keys to Meta, Google and the rest: encrypted at rest, never sent back, its own, and every change on
 * its trail (which keys, never their values).
 */
class IntegrationSecretsIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_credential_is_encrypted_never_returned_scoped_and_audited(): void
    {
        $a = $this->provisionTenant('Alpha Realty');
        $b = $this->provisionTenant('Beta Realty');
        $secret = 'EAAG-super-secret-token-123';

        $this->actingAs($a['admin'])->putJson('/api/v1/ad-connections/meta', ['credentials' => ['access_token' => $secret]])->assertOk();

        $raw = DB::table('ad_connections')->where('tenant_id', $a['tenant']->id)->value('credentials');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($secret, (string) $raw);

        foreach (['/api/v1/ad-connections/meta', '/api/v1/integrations'] as $path) {
            $this->assertStringNotContainsString($secret, (string) $this->actingAs($a['admin'])->getJson($path)->getContent());
            $this->assertStringNotContainsString($secret, (string) $this->actingAs($b['admin'])->getJson($path)->getContent());
        }

        // B has no Meta connection of its own; A's is not B's.
        $this->assertSame(0, AdConnection::withoutGlobalScopes()->where('tenant_id', $b['tenant']->id)->count());

        $log = AuditLog::withoutGlobalScopes()->where('tenant_id', $a['tenant']->id)->where('action', 'ad_connection.saved')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString($secret, json_encode($log->changes));
    }

    public function test_every_export_is_on_the_companys_trail(): void
    {
        $a = $this->provisionTenant('Alpha Realty');

        $this->actingAs($a['admin'])->get('/api/v1/leads/export?status=new')->assertOk();

        $log = AuditLog::withoutGlobalScopes()->where('tenant_id', $a['tenant']->id)->where('action', 'data.exported')->first();
        $this->assertNotNull($log);
        $this->assertSame('leads/export', $log->changes['export']);
    }
}
