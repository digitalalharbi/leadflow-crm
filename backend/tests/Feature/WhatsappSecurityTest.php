<?php

namespace Tests\Feature;

use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Database\QueryException;
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

    public function test_an_unverifiable_delivery_is_refused_outside_development(): void
    {
        /*
         * The guard was an exact-string check for "production", so APP_ENV of
         * "prod", "staging" or anything misspelled accepted unsigned writes.
         * A phone number id is a public Meta identifier, so that is the only
         * thing an attacker needs to pick a victim.
         */
        $this->alphaIntegration->forceFill(['credentials' => ['verify_token' => 'v']])->save();

        $payload = $this->payload('1000000001', '966551110001', 'بدون سر');

        foreach (['production', 'prod', 'staging', 'uat', ''] as $environment) {
            LeadMessage::withoutGlobalScopes()->delete();
            $this->app->detectEnvironment(fn () => $environment);

            $this->postWebhook($payload, signature: null)->assertStatus(401);

            $this->assertSame(
                0,
                LeadMessage::withoutGlobalScopes()->count(),
                "An unsigned delivery was accepted with APP_ENV=[{$environment}].",
            );
        }
    }

    public function test_a_workspace_cannot_send_unverified_outside_development(): void
    {
        // The same deny-list decided whether messages could be sent at all, so
        // a staging-named environment would tell reps "Message sent." for
        // messages the mock swallowed.
        $this->alphaIntegration->forceFill(['credentials' => []])->save();
        $this->app->detectEnvironment(fn () => 'staging');

        $this->actingAs($this->alpha['admin'])
            ->postJson("/api/v1/leads/{$this->alphaLead->uuid}/messages", ['body' => 'لن تصل'])
            ->assertStatus(422);

        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->count());
    }

    public function test_a_negative_page_size_cannot_dump_the_whole_conversation(): void
    {
        // Laravel's query builder silently ignores a negative limit, so no LIMIT
        // reaches Postgres and the entire history comes back in one response.
        $this->postWebhook($this->payload('1000000001', '966551110001', 'رسالة'))->assertSuccessful();

        $response = $this->actingAs($this->alpha['admin'])
            ->getJson("/api/v1/leads/{$this->alphaLead->uuid}/messages?per_page=-1")
            ->assertSuccessful();

        $this->assertGreaterThanOrEqual(1, $response->json('meta.per_page'));
    }

    public function test_a_flood_of_rejected_deliveries_does_not_grow_the_ledger(): void
    {
        /*
         * The rejection rows are the only trace that someone is probing the
         * endpoint, so they are kept — but they were keyed on a fresh UUID,
         * which means one row per request from an unauthenticated caller and
         * no way for the unique index to collapse a repeat. That is a disk
         * exhaustion primitive with no login required.
         */
        $payload = $this->payload('9999999999', '966551110001', 'رقم مجهول');

        for ($i = 0; $i < 12; $i++) {
            $this->postWebhook($payload)->assertSuccessful();
        }

        $rows = DB::table('whatsapp_events')
            ->where('event_type', 'rejected')
            ->where('outcome', 'unknown_phone_number_id')
            ->count();

        $this->assertLessThanOrEqual(2, $rows, "12 probes wrote {$rows} ledger rows.");
    }

    public function test_repeated_bad_signatures_are_recorded_without_unbounded_growth(): void
    {
        $payload = $this->payload('1000000001', '966551110001', 'توقيع خاطئ');

        for ($i = 0; $i < 12; $i++) {
            $this->postWebhook($payload, secret: 'not-the-right-secret')->assertStatus(401);
        }

        $rows = DB::table('whatsapp_events')->where('outcome', 'invalid_signature')->count();

        // Still recorded — a workspace must be able to see it is being probed.
        $this->assertGreaterThan(0, $rows);
        $this->assertLessThanOrEqual(2, $rows, "12 bad signatures wrote {$rows} ledger rows.");
    }

    public function test_a_rejected_first_save_leaves_nothing_behind(): void
    {
        // The row was created before the number was validated, so a workspace
        // whose first save was refused was left with an integration that
        // reported itself connectable and told reps their messages had sent.
        $fresh = $this->provisionTenant('Fresh Co');

        $this->actingAs($fresh['admin'])
            ->patchJson('/api/v1/integrations/whatsapp', ['phone_number_id' => '1000000001'])
            ->assertStatus(422);

        $this->assertSame(
            0,
            Integration::withoutGlobalScopes()
                ->where('tenant_id', $fresh['tenant']->id)
                ->where('provider', IntegrationProvider::Whatsapp)
                ->count(),
        );

        $this->actingAs($fresh['admin'])
            ->getJson('/api/v1/whatsapp/capability')
            ->assertSuccessful()
            ->assertJsonPath('data.can_send', false);
    }

    public function test_two_workspaces_cannot_hold_the_same_number_even_in_a_race(): void
    {
        // The application check is a time-of-check/time-of-use gap on its own;
        // the database is what actually decides.
        $other = $this->provisionTenant('Racing Co');

        $this->expectException(QueryException::class);

        $this->asTenant($other['tenant'], fn () => Integration::create([
            'provider' => IntegrationProvider::Whatsapp,
            'name' => 'WhatsApp Business',
            'status' => Integration::CONFIGURATION_REQUIRED,
            'config' => ['phone_number_id' => '1000000001'],
            'credentials' => [],
            'is_active' => true,
        ]));
    }

    public function test_the_generic_integration_endpoint_leaves_whatsapp_alone(): void
    {
        /*
         * The generic endpoints knew nothing about this provider: PATCH threw
         * on an unknown mapping and answered 500, and DELETE succeeded —
         * bypassing disconnect(), leaving the credentials in place and writing
         * the wrong thing to the audit log.
         */
        $uuid = $this->alphaIntegration->uuid;

        $this->actingAs($this->alpha['admin'])
            ->patchJson("/api/v1/integrations/{$uuid}", ['name' => 'Renamed'])
            ->assertStatus(404);

        $this->actingAs($this->alpha['admin'])
            ->deleteJson("/api/v1/integrations/{$uuid}")
            ->assertStatus(404);

        $this->assertNotNull(
            $this->asTenant($this->alpha['tenant'], fn () => Integration::find($this->alphaIntegration->id)),
        );
    }

    public function test_a_status_callback_cannot_stamp_text_onto_an_inbound_message(): void
    {
        // The failure columns were written before the terminality check and the
        // lookup did not filter on direction, so a crafted `statuses` entry
        // could put arbitrary text on a message the customer had sent us — and
        // that text is rendered back to the tenant.
        $this->postWebhook($this->payload('1000000001', '966551110001', 'رسالة العميل'))->assertSuccessful();

        $inbound = LeadMessage::withoutGlobalScopes()->where('direction', 'inbound')->firstOrFail();

        $this->postWebhook($this->failedStatus($inbound->provider_message_id))->assertSuccessful();

        $inbound->refresh();
        $this->assertSame('received', $inbound->status->value);
        $this->assertNull($inbound->failure_reason);
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

    private function failedStatus(string $messageId): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => '1000000001'],
                        'statuses' => [[
                            'id' => $messageId,
                            'status' => 'failed',
                            'timestamp' => (string) now()->timestamp,
                            'errors' => [['code' => 1, 'title' => 'نص يتحكم به المهاجم']],
                        ]],
                    ],
                ]],
            ]],
        ];
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
