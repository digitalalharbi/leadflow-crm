<?php

namespace Tests\Feature;

use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The WhatsApp integration adds two things this product did not have before: a
 * public endpoint anyone can POST to, and long-lived secrets belonging to each
 * customer. These tests are about both.
 */
class WhatsappSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    private array $beta;

    private Integration $alphaIntegration;

    private Lead $alphaLead;

    private Lead $betaLead;

    private string $alphaSecret = 'alpha-app-secret-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->provisionTenant('Alpha Towers');
        $this->beta = $this->provisionTenant('Beta Residences');

        $this->alphaIntegration = $this->connect($this->alpha['tenant'], '1000000001', $this->alphaSecret);
        $this->connect($this->beta['tenant'], '1000000002', 'beta-app-secret-0123456789');

        $this->alphaLead = $this->asTenant($this->alpha['tenant'], fn () => Lead::create([
            'full_name' => 'عميل ألفا', 'phone' => '0551110001', 'phone_normalized' => '+966551110001',
        ]));

        // Deliberately the same number in both workspaces: two developers can
        // genuinely be talking to the same buyer.
        $this->betaLead = $this->asTenant($this->beta['tenant'], fn () => Lead::create([
            'full_name' => 'عميل بيتا', 'phone' => '0551110001', 'phone_normalized' => '+966551110001',
        ]));
    }

    public function test_an_unsigned_delivery_is_refused(): void
    {
        $this->postWebhook($this->payload('1000000001', '966551110001', 'بدون توقيع'), signature: null)
            ->assertStatus(401);

        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->count());
    }

    public function test_a_delivery_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->postWebhook(
            $this->payload('1000000001', '966551110001', 'توقيع خاطئ'),
            secret: 'beta-app-secret-0123456789',
        )->assertStatus(401);

        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('whatsapp_events', ['outcome' => 'invalid_signature']);
    }

    public function test_a_delivery_lands_in_the_workspace_that_owns_the_number(): void
    {
        // Both workspaces have a lead with this phone number. The number the
        // message arrived *on* is what decides, not the number it came from.
        $this->postWebhook($this->payload('1000000001', '966551110001', 'رسالة لألفا'))->assertSuccessful();

        $messages = LeadMessage::withoutGlobalScopes()->get();

        $this->assertCount(1, $messages);
        $this->assertSame($this->alphaLead->id, $messages->first()->lead_id);
        $this->assertSame($this->alpha['tenant']->id, $messages->first()->tenant_id);
    }

    public function test_a_delivery_for_an_unknown_number_touches_nothing(): void
    {
        $this->postWebhook($this->payload('9999999999', '966551110001', 'لا أحد يملك هذا الرقم'))
            ->assertSuccessful();

        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('whatsapp_events', ['outcome' => 'unknown_phone_number_id', 'tenant_id' => null]);
    }

    public function test_an_entry_cannot_smuggle_another_workspaces_number(): void
    {
        // Signed correctly for alpha, but the inner metadata names beta's
        // number. A batched payload must not become a cross-tenant write.
        $payload = $this->payload('1000000002', '966551110001', 'تهريب');
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Routed by the first phone_number_id found, so this is delivered as
        // beta's — and beta's secret is not the one it is signed with.
        $this->postWebhook($payload, secret: $this->alphaSecret)->assertStatus(401);

        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->count());
        $this->assertNotSame('', $raw);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $stored = DB::table('integrations')->where('id', $this->alphaIntegration->id)->value('credentials');

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString($this->alphaSecret, $stored);
        $this->assertStringNotContainsString('verify-1000000001', $stored);
    }

    public function test_the_settings_endpoint_never_returns_a_secret(): void
    {
        $this->actingAs($this->alpha['admin'])
            ->patchJson('/api/v1/integrations/whatsapp', [
                'phone_number_id' => '1000000001',
                'access_token' => 'EAAG-super-secret-access-token-value',
                'app_secret' => $this->alphaSecret,
            ])->assertSuccessful();

        $body = $this->actingAs($this->alpha['admin'])
            ->getJson('/api/v1/integrations/whatsapp')
            ->assertSuccessful()
            ->assertJsonPath('data.has_access_token', true)
            ->getContent();

        $this->assertStringNotContainsString('EAAG-super-secret-access-token-value', $body);
        $this->assertStringNotContainsString($this->alphaSecret, $body);
        $this->assertStringNotContainsString('verify_token', str_replace('has_verify_token', '', $body));
    }

    public function test_only_an_admin_can_read_or_change_the_connection(): void
    {
        $rep = $this->makeUser($this->alpha['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/integrations/whatsapp')->assertStatus(403);
        $this->actingAs($rep)->patchJson('/api/v1/integrations/whatsapp', ['phone_number_id' => '5'])->assertStatus(403);
        $this->actingAs($rep)->deleteJson('/api/v1/integrations/whatsapp')->assertStatus(403);
    }

    public function test_one_workspace_cannot_claim_another_workspaces_number(): void
    {
        $this->actingAs($this->beta['admin'])
            ->patchJson('/api/v1/integrations/whatsapp', ['phone_number_id' => '1000000001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_number_id');
    }

    public function test_a_rep_cannot_read_another_workspaces_conversation(): void
    {
        $this->postWebhook($this->payload('1000000001', '966551110001', 'سري'))->assertSuccessful();

        $this->actingAs($this->beta['admin'])
            ->getJson("/api/v1/leads/{$this->alphaLead->uuid}/messages")
            ->assertStatus(404);
    }

    public function test_a_rep_cannot_send_into_another_workspace(): void
    {
        $this->actingAs($this->beta['admin'])
            ->postJson("/api/v1/leads/{$this->alphaLead->uuid}/messages", ['body' => 'اختراق'])
            ->assertStatus(404);

        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->count());
    }

    public function test_the_verify_handshake_does_not_leak_whether_a_number_exists(): void
    {
        // Wrong token for a real number, and any token for a number nobody owns,
        // must be indistinguishable.
        $real = $this->getJson('/api/v1/integrations/whatsapp/webhook?hub_verify_token=wrong&phone_number_id=1000000001&hub_challenge=abc');
        $fake = $this->getJson('/api/v1/integrations/whatsapp/webhook?hub_verify_token=wrong&phone_number_id=7777777777&hub_challenge=abc');

        $real->assertStatus(403);
        $fake->assertStatus(403);
        $this->assertSame($real->getContent(), $fake->getContent());
    }

    public function test_the_verify_handshake_succeeds_with_the_right_token(): void
    {
        $this->get('/api/v1/integrations/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=verify-1000000001&phone_number_id=1000000001&hub_challenge=challenge-123')
            ->assertSuccessful()
            ->assertSee('challenge-123');
    }

    public function test_disconnecting_destroys_the_credentials(): void
    {
        $this->actingAs($this->alpha['admin'])
            ->deleteJson('/api/v1/integrations/whatsapp')
            ->assertSuccessful();

        $integration = $this->asTenant($this->alpha['tenant'], fn () => Integration::find($this->alphaIntegration->id));

        $this->assertSame([], $integration->credentials);
        $this->assertSame(Integration::NOT_CONNECTED, $integration->status);
    }

    public function test_an_unverifiable_delivery_is_refused_in_production(): void
    {
        // Development accepts an unsigned delivery so the webhook can be
        // demonstrated before a Meta app exists. Production must not: a
        // workspace that has a phone number but no app secret would otherwise
        // accept messages from anyone who guessed the number.
        $this->alphaIntegration->forceFill([
            'credentials' => ['verify_token' => 'verify-1000000001'],
        ])->save();

        $payload = $this->payload('1000000001', '966551110001', 'بدون سر');

        // Development: accepted, because there is nothing to verify against.
        $this->postWebhook($payload, signature: null)->assertSuccessful();

        LeadMessage::withoutGlobalScopes()->delete();
        DB::table('whatsapp_events')->delete();

        $this->app->detectEnvironment(fn () => 'production');

        $this->postWebhook($payload, signature: null)->assertStatus(401);
        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->count());
    }

    private function postWebhook(array $payload, ?string $secret = null, bool|string|null $signature = false)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $header = $signature === false
            ? 'sha256='.hash_hmac('sha256', $raw, $secret ?? $this->alphaSecret)
            : $signature;

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($header !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $header;
        }

        return $this->call('POST', '/api/v1/integrations/whatsapp/webhook', [], [], [], $server, $raw);
    }

    private function payload(string $phoneNumberId, string $from, string $text): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'from' => $from,
                            'id' => 'wamid.'.md5($phoneNumberId.$text),
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function connect($tenant, string $phoneNumberId, string $appSecret): Integration
    {
        return $this->asTenant($tenant, fn () => Integration::create([
            'provider' => IntegrationProvider::Whatsapp,
            'name' => 'WhatsApp Business',
            'status' => Integration::CONFIGURATION_REQUIRED,
            'config' => ['phone_number_id' => $phoneNumberId],
            'credentials' => ['verify_token' => 'verify-'.$phoneNumberId, 'app_secret' => $appSecret],
            'is_active' => true,
        ]));
    }
}
