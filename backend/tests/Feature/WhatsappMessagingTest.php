<?php

namespace Tests\Feature;

use App\Enums\IntegrationProvider;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sending and receiving WhatsApp, with the mock provider standing in for Meta.
 *
 * The mock is not a shortcut around the behaviour under test: messages take the
 * same table, the same statuses and the same webhook path they will take with
 * real credentials.
 */
class WhatsappMessagingTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Lead $lead;

    private Integration $integration;

    private string $phoneNumberId = '1555000111';

    private string $appSecret = 'app-secret-for-signature-checks';

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Whatsapp Realty');
        $this->integration = $this->connectWhatsapp($this->t['tenant'], $this->phoneNumberId);

        $this->lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'سلمان المراسل',
            'phone' => '0551234567',
            'phone_normalized' => '+966551234567',
        ]));
    }

    public function test_a_rep_can_send_a_message_and_it_is_recorded(): void
    {
        $response = $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/messages", ['body' => 'مرحبًا بك']);

        $response->assertStatus(201)
            ->assertJsonPath('data.message.status', 'sent')
            ->assertJsonPath('data.message.direction', 'outbound');

        $this->assertDatabaseHas('lead_messages', [
            'lead_id' => $this->lead->id,
            'tenant_id' => $this->t['tenant']->id,
            'body' => 'مرحبًا بك',
            'status' => 'sent',
        ]);
    }

    public function test_sending_records_the_first_contact_time(): void
    {
        $this->assertNull($this->lead->first_contact_at);

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/messages", ['body' => 'أول تواصل'])
            ->assertStatus(201);

        $this->assertNotNull($this->lead->refresh()->first_contact_at);
    }

    public function test_a_provider_failure_is_recorded_rather_than_swallowed(): void
    {
        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'رقم غير فعال',
            'phone' => '0500000000',
            'phone_normalized' => '+966500000000',
        ]));

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$lead->uuid}/messages", ['body' => 'لن تصل'])
            ->assertStatus(201)
            ->assertJsonPath('data.message.status', 'failed');

        $message = LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->first();

        $this->assertSame('131026', $message->failure_code);
        $this->assertNotNull($message->failed_at);
    }

    public function test_an_opted_out_lead_is_never_messaged(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/whatsapp-opt-out", ['opted_out' => true])
            ->assertSuccessful();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/messages", ['body' => 'رسالة بعد الإيقاف'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('lead_messages', ['body' => 'رسالة بعد الإيقاف']);
    }

    public function test_a_rep_cannot_undo_an_opt_out(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->lead->forceFill(['assigned_to' => $rep->id, 'whatsapp_opt_out_at' => now()])->saveQuietly();

        $this->actingAs($rep)
            ->postJson("/api/v1/leads/{$this->lead->uuid}/whatsapp-opt-out", ['opted_out' => false])
            ->assertStatus(403);

        $this->assertNotNull($this->lead->refresh()->whatsapp_opt_out_at);
    }

    public function test_an_inbound_message_is_attached_to_the_matching_lead(): void
    {
        $this->postWebhook($this->inboundPayload('966551234567', 'نعم مهتم بالمشروع'))
            ->assertSuccessful()
            ->assertJsonPath('data.messages', 1);

        $this->assertDatabaseHas('lead_messages', [
            'lead_id' => $this->lead->id,
            'direction' => 'inbound',
            'status' => 'received',
            'body' => 'نعم مهتم بالمشروع',
        ]);

        $lead = $this->lead->refresh();
        $this->assertNotNull($lead->first_response_at, 'A reply must record the response time.');
        $this->assertNotNull($lead->whatsapp_opt_in_at, 'A reply is the clearest opt-in there is.');
    }

    public function test_the_same_delivery_twice_creates_one_message(): void
    {
        $payload = $this->inboundPayload('966551234567', 'رسالة مكررة', 'wamid.repeat.1');

        $this->postWebhook($payload)->assertSuccessful()->assertJsonPath('data.messages', 1);
        $this->postWebhook($payload)->assertSuccessful()->assertJsonPath('data.duplicates', 1);

        $this->assertSame(1, LeadMessage::withoutGlobalScopes()->where('provider_message_id', 'wamid.repeat.1')->count());
    }

    public function test_a_message_from_an_unknown_number_does_not_invent_a_lead(): void
    {
        $before = Lead::withoutGlobalScopes()->count();

        $this->postWebhook($this->inboundPayload('966500999888', 'من أنتم؟'))
            ->assertSuccessful()
            ->assertJsonPath('data.unmatched', 1);

        $this->assertSame($before, Lead::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('whatsapp_events', [
            'event_type' => WhatsappEvent::TYPE_UNMATCHED,
            'outcome' => 'no_matching_lead',
        ]);
    }

    public function test_delivery_and_read_receipts_move_the_message_forward(): void
    {
        $message = $this->sendAndCapture();

        $this->postWebhook($this->statusPayload($message->provider_message_id, 'delivered'))->assertSuccessful();
        $this->assertSame(MessageStatus::Delivered, $message->refresh()->status);

        $this->postWebhook($this->statusPayload($message->provider_message_id, 'read'))->assertSuccessful();
        $this->assertSame(MessageStatus::Read, $message->refresh()->status);
        $this->assertNotNull($message->delivered_at);
        $this->assertNotNull($message->read_at);
    }

    public function test_a_late_receipt_never_walks_the_status_backwards(): void
    {
        // Meta does not order its callbacks: a retried "sent" can land after a
        // "read". Showing a message the customer has read as merely sent would
        // make a rep chase someone who already answered.
        $message = $this->sendAndCapture();

        $this->postWebhook($this->statusPayload($message->provider_message_id, 'read'));
        $this->postWebhook($this->statusPayload($message->provider_message_id, 'sent'));

        $this->assertSame(MessageStatus::Read, $message->refresh()->status);
    }

    public function test_a_failed_receipt_records_the_reason(): void
    {
        $message = $this->sendAndCapture();

        $this->postWebhook($this->statusPayload($message->provider_message_id, 'failed'))->assertSuccessful();

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame('131047', $message->failure_code);
    }

    public function test_the_conversation_is_readable_on_the_lead(): void
    {
        $this->sendAndCapture();
        $this->postWebhook($this->inboundPayload('966551234567', 'شكرًا'));

        $response = $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$this->lead->uuid}/messages");

        $response->assertSuccessful()->assertJsonCount(2, 'data');
    }

    public function test_the_raw_provider_payload_never_reaches_the_client(): void
    {
        $this->postWebhook($this->inboundPayload('966551234567', 'نص الرسالة'));

        $body = $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$this->lead->uuid}/messages")
            ->getContent();

        $this->assertStringNotContainsString('provider_payload', $body);
        $this->assertStringNotContainsString('wa_id', $body);
    }

    private function sendAndCapture(): LeadMessage
    {
        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/messages", ['body' => 'رسالة صادرة'])
            ->assertStatus(201);

        return LeadMessage::withoutGlobalScopes()->latest('id')->first();
    }

    private function postWebhook(array $payload, ?string $signature = null)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $this->call(
            'POST',
            '/api/v1/integrations/whatsapp/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $raw, $this->appSecret),
            ],
            $raw,
        );
    }

    private function inboundPayload(string $from, string $text, string $id = 'wamid.in.1'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '900900',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '966500000001', 'phone_number_id' => $this->phoneNumberId],
                        'contacts' => [['profile' => ['name' => 'سلمان'], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $id,
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function statusPayload(string $messageId, string $status): array
    {
        $entry = [
            'id' => $messageId,
            'status' => $status,
            'timestamp' => (string) now()->timestamp,
            'recipient_id' => '966551234567',
        ];

        if ($status === 'failed') {
            $entry['errors'] = [['code' => 131047, 'title' => 'Re-engagement message', 'message' => 'Outside the 24 hour window.']];
        }

        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '900900',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '966500000001', 'phone_number_id' => $this->phoneNumberId],
                        'statuses' => [$entry],
                    ],
                ]],
            ]],
        ];
    }

    private function connectWhatsapp($tenant, string $phoneNumberId, ?string $appSecret = null): Integration
    {
        return $this->asTenant($tenant, fn () => Integration::create([
            'provider' => IntegrationProvider::Whatsapp,
            'name' => 'WhatsApp Business',
            'status' => Integration::CONFIGURATION_REQUIRED,
            'config' => ['phone_number_id' => $phoneNumberId, 'business_account_id' => '900900'],
            'credentials' => ['verify_token' => 'verify-'.$phoneNumberId, 'app_secret' => $appSecret ?? $this->appSecret],
            'is_active' => true,
        ]));
    }
}
