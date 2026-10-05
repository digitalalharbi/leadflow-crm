<?php

namespace Tests\Feature;

use App\Enums\MessageStatus;
use App\Jobs\RetryWhatsappMessage;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\Whatsapp\FailureClassifier;
use App\Services\Whatsapp\WhatsappManager;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Trying again, but only when trying again could work.
 *
 * The distinction is the whole feature. Retrying a permanent failure is how a
 * customer receives the same message five times — an invalid number does not
 * become valid, and a template Meta rejected is not approved by asking again.
 * Retrying a transient one is the difference between a message that arrives a
 * minute late and one the rep has to notice and retype.
 */
class WhatsappRetryTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Retry Co');

        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp',
            'name' => 'WhatsApp',
            'status' => Integration::CONNECTED,
            'is_active' => true,
            'config' => ['phone_number_id' => '123', 'waba_id' => '456'],
            'credentials' => ['access_token' => 'tok', 'app_secret' => 'secret'],
        ]));
    }

    private function lead(): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'عميل واتساب',
            'phone' => '0512345678',
            'phone_normalized' => '+966512345678',
            'status' => 'new',
            'whatsapp_opt_in_at' => now(),
        ]));
    }

    private function send(Lead $lead): LeadMessage
    {
        return $this->asTenant(
            $this->t['tenant'],
            fn () => app(WhatsappSender::class)->send($lead, 'مرحبًا', $this->t['admin']),
        );
    }

    /** @return array<string, array{string, bool}> */
    public static function failures(): array
    {
        return [
            'we never reached Meta' => ['transport_error', true],
            'Meta had a bad moment' => ['500', true],
            'rate limited' => ['80007', true],
            'invalid phone number' => ['131026', false],
            'template rejected' => ['132001', false],
            'unknown code' => ['999999', false],
        ];
    }

    /**
     * An unrecognised code is treated as permanent on purpose. Four copies of
     * a message is a complaint; one failed message sitting visibly in the
     * conversation is something a rep can act on.
     */
    #[DataProvider('failures')]
    public function test_it_knows_which_failures_are_worth_retrying(string $code, bool $transient): void
    {
        $this->assertSame($transient, app(FailureClassifier::class)->isTransient($code));
    }

    public function test_a_transient_failure_is_queued_for_another_try(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 500]], 500)]);

        $message = $this->send($this->lead());

        $this->assertSame(MessageStatus::Failed, $message->status);
        Queue::assertPushed(RetryWhatsappMessage::class);
    }

    /** The customer must not receive the same message again. */
    public function test_a_permanent_failure_is_not_retried(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(
            ['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400,
        )]);

        $message = $this->send($this->lead());

        $this->assertSame(MessageStatus::Failed, $message->status);
        Queue::assertNotPushed(RetryWhatsappMessage::class);
    }

    /** A retry that works updates the message already on record. */
    public function test_a_successful_retry_marks_the_original_sent(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response([], 500)]);
        $message = $this->send($this->lead());

        Http::fake(['graph.facebook.com/*' => Http::response(
            ['messages' => [['id' => 'wamid.retry.1']]], 200,
        )]);

        $this->runRetry($message);

        $message->refresh();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('wamid.retry.1', $message->provider_message_id);
        $this->assertNull($message->failure_code, 'a message that arrived should not still show an error');
    }

    /** One message that eventually arrived, not four attempts to read past. */
    public function test_a_retry_never_creates_a_second_message(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response([], 500)]);
        $message = $this->send($this->lead());

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);
        $this->runRetry($message);

        $this->asTenant($this->t['tenant'], fn () => $this->assertSame(1, LeadMessage::count()));
    }

    /** A rep who retyped it by hand must not have it sent twice. */
    public function test_a_message_that_is_no_longer_failed_is_left_alone(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response([], 500)]);
        $message = $this->send($this->lead());

        $message->forceFill(['status' => MessageStatus::Sent])->save();

        Http::fake();
        $this->runRetry($message);

        Http::assertNothingSent();
    }

    private function runRetry(LeadMessage $message): void
    {
        (new RetryWhatsappMessage($message->id))->handle(
            app(WhatsappManager::class),
            app(TenantContext::class),
            app(FailureClassifier::class),
        );
    }
}
