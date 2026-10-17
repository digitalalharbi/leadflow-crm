<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationHealthReport;
use App\Domain\Integrations\IntegrationState;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\CommissioningRun;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadSourceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Six words for how far an integration has got, none of which overclaims.
 *
 * "Connected" is not one of them. It stood for "a row exists", "a token was
 * typed" and "the provider said yes" at once, and it appeared beside the demo
 * provider on a screen where somebody was deciding whether customers were being
 * messaged.
 */
class IntegrationStateLadderTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Ladder Co');
    }

    private function state(string $key): string
    {
        $rows = $this->asTenant($this->t['tenant'], fn () => app(IntegrationHealthReport::class)->all());

        return collect($rows)->firstWhere('key', $key)['state'];
    }

    private function whatsapp(array $credentials = ['access_token' => 'EAAB', 'verify_token' => 'v', 'app_secret' => 's']): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp',
            'name' => 'WhatsApp Business',
            'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000999'],
            'credentials' => $credentials,
            'is_active' => true,
        ]));
    }

    private function message(string $provider, MessageStatus $status): void
    {
        $this->asTenant($this->t['tenant'], function () use ($provider, $status) {
            $lead = Lead::create(['full_name' => 'x', 'phone_normalized' => '+966551234567']);

            LeadMessage::create([
                'lead_id' => $lead->id,
                'direction' => MessageDirection::Outbound,
                'channel' => MessageChannel::Whatsapp,
                'provider' => $provider,
                'provider_message_id' => 'wamid.'.uniqid(),
                'message_type' => 'text',
                'body' => 'x',
                'status' => $status,
            ]);
        });
    }

    private function commissioned(string $key, bool $ok): void
    {
        $this->asTenant($this->t['tenant'], fn () => CommissioningRun::create([
            'key' => $key, 'ok' => $ok, 'steps' => [],
        ]));
    }

    public function test_nothing_is_entered_so_nothing_is_claimed(): void
    {
        foreach (['meta', 'tiktok', 'google_ads', 'snapchat', 'linkedin', 'google_sheets', 'whatsapp', 'calls', 'github'] as $key) {
            $this->assertSame(IntegrationState::NOT_CONFIGURED, $this->state($key), $key);
        }

        // The workspace's own systems posting to an endpoint we provisioned.
        $this->assertSame(IntegrationState::READY, $this->state('generic_webhook'));
    }

    public function test_credentials_nobody_has_proven_are_only_code_verified(): void
    {
        $this->whatsapp();

        $this->assertSame(IntegrationState::CODE_VERIFIED, $this->state('whatsapp'));
    }

    public function test_a_provider_that_accepted_the_credentials_is_ready_and_no_more(): void
    {
        $this->whatsapp();
        $this->commissioned('whatsapp', true);

        $this->assertSame(IntegrationState::READY, $this->state('whatsapp'));
    }

    public function test_a_failed_check_does_not_make_credentials_ready(): void
    {
        $this->whatsapp();
        $this->commissioned('whatsapp', false);

        $this->assertSame(IntegrationState::CODE_VERIFIED, $this->state('whatsapp'));
    }

    /**
     * The demo provider is real in shape and not real.
     *
     * A message it "sent" proves the pipeline runs, and says nothing about Meta.
     * It earns a word of its own, which is not any of the ones that mean a
     * customer was reached.
     */
    public function test_a_message_the_demo_provider_sent_is_sandbox_not_live(): void
    {
        $this->message('mock', MessageStatus::Delivered);

        $this->assertSame(IntegrationState::SANDBOX_VERIFIED, $this->state('whatsapp'));
    }

    public function test_a_real_receipt_makes_it_live(): void
    {
        $this->whatsapp();
        $this->message('whatsapp_cloud', MessageStatus::Delivered);

        $this->assertSame(IntegrationState::LIVE_VERIFIED, $this->state('whatsapp'));
    }

    public function test_an_ad_platform_event_with_no_credentials_is_a_simulation(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            $lead = Lead::create(['full_name' => 'x', 'phone_normalized' => '+966551234567']);

            LeadSourceEvent::create([
                'lead_id' => $lead->id, 'provider' => 'meta', 'platform' => 'meta',
                'was_duplicate' => false, 'received_at' => now(),
            ]);
        });

        $this->assertSame(IntegrationState::SANDBOX_VERIFIED, $this->state('meta'));
    }

    // --------------------------------------------------- production

    private function liveWhatsapp(?\DateTimeInterface $at = null): void
    {
        $this->whatsapp();
        $this->message('whatsapp_cloud', MessageStatus::Delivered);

        if ($at) {
            LeadMessage::withoutGlobalScopes()->where('provider', 'whatsapp_cloud')->update(['created_at' => $at]);
        }
    }

    public function test_production_verified_needs_production_and_a_recent_event(): void
    {
        $this->liveWhatsapp();

        // Live on a developer machine is live, and not production.
        $this->assertSame(IntegrationState::LIVE_VERIFIED, $this->state('whatsapp'));

        $this->app['env'] = 'production';

        $this->assertSame(IntegrationState::PRODUCTION_VERIFIED, $this->state('whatsapp'));
    }

    public function test_a_deployment_that_has_gone_quiet_has_earned_live_and_no_more(): void
    {
        $this->app['env'] = 'production';
        $this->liveWhatsapp(now()->subDays(20));

        $this->assertSame(IntegrationState::LIVE_VERIFIED, $this->state('whatsapp'));
    }

    public function test_a_current_error_withholds_production(): void
    {
        $this->app['env'] = 'production';
        $this->liveWhatsapp();

        Integration::withoutGlobalScopes()->where('provider', 'whatsapp')->update(['last_error' => 'token expired']);

        $this->assertSame(IntegrationState::LIVE_VERIFIED, $this->state('whatsapp'));
    }

    // -------------------------------------------------------- shape

    public function test_the_word_connected_is_not_a_state(): void
    {
        $this->assertNotContains('connected', IntegrationState::LADDER);
        $this->assertSame(6, count(IntegrationState::LADDER));
    }

    public function test_the_ladder_orders_from_nothing_to_proven(): void
    {
        $this->assertTrue(IntegrationState::atLeast(IntegrationState::LIVE_VERIFIED, IntegrationState::READY));
        $this->assertFalse(IntegrationState::atLeast(IntegrationState::SANDBOX_VERIFIED, IntegrationState::READY));
    }

    public function test_every_row_the_screen_asks_for_carries_a_state(): void
    {
        $rows = $this->actingAs($this->t['admin'])->getJson('/api/v1/integrations/health')->assertOk()->json('data.integrations')
            ?? $this->actingAs($this->t['admin'])->getJson('/api/v1/integrations/health')->json('data');

        foreach (collect($rows)->pluck('state') as $state) {
            $this->assertContains($state, IntegrationState::LADDER);
        }
    }
}
