<?php

namespace Tests\Feature;

use App\DataTransferObjects\SentMessage;
use App\Enums\MessageStatus;
use App\Jobs\RetryWhatsappMessage;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\Whatsapp\MockWhatsappProvider;
use App\Services\Whatsapp\SendFailure;
use App\Services\Whatsapp\WhatsappManager;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A dropped connection is not a message the customer will never get.
 *
 * Sending was one attempt and nothing more: if the HTTP call threw, or Meta
 * answered 503, the message was marked failed for good — and whether the
 * customer ever heard from the business depended on a rep noticing a red mark
 * and pressing send again. The conversion exporter in this codebase already
 * retried with a backoff; the channel a rep actually talks to customers on did
 * not.
 *
 * The distinction is the whole feature, and it cuts both ways: retrying "the
 * recipient is not on WhatsApp" four times only delays telling the rep the
 * truth, and retrying a lead who blocked the business is how a workspace's
 * number gets reported.
 */
class WhatsappRetryTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Faked for every test here. The test queue runs synchronously, so a
         * dispatched retry would fire inside `send()` — and the tests would
         * pass while asserting nothing about the thing they name, because the
         * work had already happened before they called the job.
         */
        Queue::fake();

        $this->t = $this->provisionTenant('Retry Realty');

        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp',
            'name' => 'WhatsApp Business',
            'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000999'],
            'credentials' => ['verify_token' => 'v', 'app_secret' => 's'],
            'is_active' => true,
        ]));

        $this->lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'سلمان المراسل',
            'phone' => '0551234567',
            'phone_normalized' => '+966551234567',
        ]));
    }

    /**
     * A provider that answers however the test needs it to, in order.
     *
     * Swapped in as the mock provider rather than bound to the interface: the
     * manager takes both providers by constructor injection and picks between
     * them on whether an access token is set, so binding the interface would
     * change nothing. This integration has no token, so the mock is the one it
     * reaches for.
     */
    private function provider(SentMessage ...$results): void
    {
        $this->app->instance(MockWhatsappProvider::class, new class($results) extends MockWhatsappProvider
        {
            /** @param array<int, SentMessage> $results */
            public function __construct(private array $results) {}

            public function sendText(Integration $integration, string $toPhone, string $body): SentMessage
            {
                return array_shift($this->results) ?? SentMessage::accepted('wamid.fallback');
            }
        });
    }

    public function test_a_transport_failure_is_not_the_end_of_the_message(): void
    {
        $this->assertTrue(SendFailure::isTransient('transport_error'));
        $this->assertTrue(SendFailure::isTransient('503'));
        $this->assertTrue(SendFailure::isTransient('130429'));
    }

    /**
     * The two that must never be retried: a number that is not on WhatsApp, and
     * a person who asked to be left alone.
     */
    public function test_a_message_that_will_never_arrive_is_not_retried(): void
    {
        $this->assertFalse(SendFailure::isTransient('131026'));
        $this->assertFalse(SendFailure::isTransient('131047'));
        $this->assertFalse(SendFailure::isTransient(null));
    }

    /** Anything unrecognised is permanent on purpose — see the class docblock. */
    public function test_an_unknown_code_is_treated_as_permanent(): void
    {
        $this->assertFalse(SendFailure::isTransient('some_code_meta_invented_last_tuesday'));
    }

    public function test_a_blip_leaves_the_message_pending_and_queues_another_try(): void
    {
        $this->provider(SentMessage::failed('transport_error', 'Could not reach WhatsApp.'));

        $message = $this->asTenant(
            $this->t['tenant'],
            fn () => app(WhatsappSender::class)->send($this->lead, 'مرحبًا', $this->t['admin']),
        );

        // Pending, not failed: the rep is told "sending", and told "failed" only
        // once it genuinely has.
        $this->assertSame(MessageStatus::Pending, $message->fresh()->status);
        $this->assertSame(1, $message->fresh()->attempts);

        Queue::assertPushed(RetryWhatsappMessage::class);
    }

    public function test_a_permanent_rejection_fails_immediately_and_queues_nothing(): void
    {
        $this->provider(SentMessage::failed('131026', 'Message undeliverable.'));

        $message = $this->asTenant(
            $this->t['tenant'],
            fn () => app(WhatsappSender::class)->send($this->lead, 'مرحبًا', $this->t['admin']),
        );

        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
        Queue::assertNotPushed(RetryWhatsappMessage::class);
    }

    public function test_the_retry_sends_it_and_the_message_becomes_sent(): void
    {
        $this->provider(
            SentMessage::failed('transport_error', 'Could not reach WhatsApp.'),
            SentMessage::accepted('wamid.second.try'),
        );

        $message = $this->asTenant(
            $this->t['tenant'],
            fn () => app(WhatsappSender::class)->send($this->lead, 'مرحبًا', $this->t['admin']),
        );

        (new RetryWhatsappMessage($message->id))->handle(
            app(WhatsappManager::class),
            app(TenantContext::class),
        );

        $fresh = $message->fresh();

        $this->assertSame(MessageStatus::Sent, $fresh->status);
        $this->assertSame('wamid.second.try', $fresh->provider_message_id);
        $this->assertSame(2, $fresh->attempts);
        // The failure it recovered from is cleared, not left on a sent message.
        $this->assertNull($fresh->failure_code);
    }

    /** A message still bouncing after half an hour is a failure, not a blip. */
    public function test_it_gives_up_after_the_third_attempt(): void
    {
        $this->provider(
            SentMessage::failed('transport_error', 'Could not reach WhatsApp.'),
            SentMessage::failed('transport_error', 'Could not reach WhatsApp.'),
            SentMessage::failed('transport_error', 'Could not reach WhatsApp.'),
        );

        $message = $this->asTenant(
            $this->t['tenant'],
            fn () => app(WhatsappSender::class)->send($this->lead, 'مرحبًا', $this->t['admin']),
        );

        foreach ([2, 3] as $expected) {
            (new RetryWhatsappMessage($message->id))->handle(
                app(WhatsappManager::class),
                app(TenantContext::class),
            );
        }

        $fresh = $message->fresh();

        $this->assertSame(MessageStatus::Failed, $fresh->status);
        $this->assertSame(RetryWhatsappMessage::MAX_ATTEMPTS, $fresh->attempts);
        $this->assertNotNull($fresh->failed_at);
    }

    /**
     * Consent is checked again, not assumed from the first attempt. Somebody
     * who replies STOP between the failed send and the retry must not be
     * messaged because a job was already in flight.
     */
    public function test_a_lead_who_opts_out_before_the_retry_is_not_messaged(): void
    {
        $this->provider(
            SentMessage::failed('transport_error', 'Could not reach WhatsApp.'),
            SentMessage::accepted('wamid.should.not.happen'),
        );

        $message = $this->asTenant(
            $this->t['tenant'],
            fn () => app(WhatsappSender::class)->send($this->lead, 'مرحبًا', $this->t['admin']),
        );

        $this->lead->forceFill(['whatsapp_opt_out_at' => now()])->saveQuietly();

        (new RetryWhatsappMessage($message->id))->handle(
            app(WhatsappManager::class),
            app(TenantContext::class),
        );

        $fresh = $message->fresh();

        $this->assertSame(MessageStatus::Failed, $fresh->status);
        $this->assertSame('opted_out', $fresh->failure_code);
        $this->assertNotSame('wamid.should.not.happen', $fresh->provider_message_id);
    }

    /** A message sent in the meantime is left alone. */
    public function test_a_message_already_sent_is_not_sent_twice(): void
    {
        $message = $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $this->lead->id,
            'direction' => 'outbound',
            'channel' => 'whatsapp',
            'body' => 'مرحبًا',
            'status' => MessageStatus::Sent->value,
            'provider_message_id' => 'wamid.already',
        ]));

        $this->provider(SentMessage::accepted('wamid.second'));

        (new RetryWhatsappMessage($message->id))->handle(
            app(WhatsappManager::class),
            app(TenantContext::class),
        );

        $this->assertSame('wamid.already', $message->fresh()->provider_message_id);
    }
}
