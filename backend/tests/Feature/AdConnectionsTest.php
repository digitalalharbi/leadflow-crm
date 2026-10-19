<?php

namespace Tests\Feature;

use App\Domain\Campaigns\AdPlatformRegistry;
use App\Domain\Campaigns\PlatformVerification;
use App\Enums\Role;
use App\Models\AdConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Connect Co');
    }

    public function test_credentials_are_stored_encrypted_and_never_returned(): void
    {
        $data = $this->actingAs($this->t['admin'])->putJson('/api/v1/ad-connections/tiktok', [
            'credentials' => ['access_token' => 'secret-token', 'unrelated' => 'x'],
            'config' => ['account_ids' => ['700']],
        ])->assertOk()->json('data.connection');

        $this->assertSame(['access_token'], $data['filled']);
        $this->assertSame('code_verified', $data['state']);
        $this->assertStringNotContainsString('secret-token', json_encode($data));

        $raw = \DB::table('ad_connections')->value('credentials');
        $this->assertStringNotContainsString('secret-token', (string) $raw);
        $this->assertArrayNotHasKey('unrelated', $this->asTenant($this->t['tenant'], fn () => AdConnection::first()->credentials));
    }

    public function test_a_successful_connection_test_makes_it_ready_and_a_failed_one_says_why(): void
    {
        $this->actingAs($this->t['admin'])->putJson('/api/v1/ad-connections/tiktok', ['credentials' => ['access_token' => 't'], 'config' => ['account_ids' => ['700']]]);

        Http::fake(['*' => Http::response(['code' => 0, 'data' => ['list' => [['advertiser_id' => '700', 'name' => 'x', 'currency' => 'SAR', 'status' => 'STATUS_ENABLE']]]])]);
        $ok = $this->actingAs($this->t['admin'])->postJson('/api/v1/ad-connections/tiktok/test')->assertOk()->json('data');
        $this->assertTrue($ok['ok']);
        $this->assertSame('ready', $ok['connection']['state']);

        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['code' => 40105, 'message' => 'Access token is incorrect'])]);
        $bad = $this->actingAs($this->t['admin'])->postJson('/api/v1/ad-connections/tiktok/test')->json('data');
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('incorrect', $bad['error']);
    }

    public function test_the_ladder_moves_only_on_platform_evidence(): void
    {
        $v = app(PlatformVerification::class);
        $adapter = app(AdPlatformRegistry::class)->get('meta');

        $c = $this->asTenant($this->t['tenant'], fn () => AdConnection::create(['platform' => 'meta', 'credentials' => ['access_token' => 'x']]));
        $this->assertSame('code_verified', $v->state($adapter, $c));

        $c->forceFill(['mode' => 'sandbox', 'last_sync_ok_at' => now()]);
        $this->assertSame('sandbox_verified', $v->state($adapter, $c), 'A sandbox success counted as live.');

        $c->forceFill(['mode' => 'live', 'last_sync_ok_at' => null, 'last_test_ok_at' => now()]);
        $this->assertSame('ready', $v->state($adapter, $c));

        $c->forceFill(['last_sync_ok_at' => now()]);
        // A real sync from a developer machine proves the account, not the deployment.
        $this->assertSame('ready', $v->state($adapter, $c), 'Live was claimed outside production.');

        $this->asProductionDeployment();
        $this->assertSame('production_verified', $v->state($adapter, $c));
    }

    public function test_only_admins_hold_the_keys(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);

        $this->actingAs($manager)->putJson('/api/v1/ad-connections/meta', ['credentials' => ['access_token' => 'x']])->assertForbidden();
        $this->actingAs($this->t['admin'])->deleteJson('/api/v1/ad-connections/meta')->assertOk();
    }
}
