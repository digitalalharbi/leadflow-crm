<?php

namespace Tests\Feature;

use App\Domain\Mail\DeliveryResult;
use App\Domain\Mail\EmailDeliveries;
use App\Domain\Mail\MailerProvider;
use App\Domain\Mail\MailerRegistry;
use App\Domain\Mail\OutboundEmail;
use App\Domain\Mail\Providers\LogMailer;
use App\Domain\Mail\Providers\ResendMailer;
use App\Domain\Mail\Providers\SesMailer;
use App\Domain\Mail\Providers\SmtpMailer;
use App\Enums\Role;
use App\Jobs\SendQueuedEmail;
use App\Models\EmailDelivery;
use App\Models\EmailDeliveryAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The product's own email behind one door: providers that say honestly what they are, a queue that
 * writes the email down first, retries that wait and give up, a failure log, and a status a person
 * can read. Nothing here connects a real mailer.
 */
class ReportEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Mail Co');
        config(['leadflow_mail.provider' => 'log', 'leadflow_mail.backoff' => [60, 300, 1800], 'leadflow_mail.from' => ['address' => 'reports@mohtam.test', 'name' => 'مهتم']]);
    }

    private function email(string $to = 'client@example.test'): OutboundEmail
    {
        return new OutboundEmail($to, 'عميل', 'تقرير', '<p>مرحبا</p>', 'مرحبا', 'reports@mohtam.test', 'مهتم', 'key-'.$to, ['kind' => 'report']);
    }

    private function share(array $extra = []): array
    {
        return $this->actingAs($this->t['admin'])->postJson('/api/v1/report-shares', $extra + ['name' => 'تقرير الإدارة', 'template' => 'client'])->assertCreated()->json('data.share');
    }

    /** A provider whose answers a test decides. */
    private function scripted(array $results): MailerProvider
    {
        return new class($results) implements MailerProvider
        {
            public array $sent = [];

            public function __construct(private array $results) {}

            public function key(): string
            {
                return 'resend';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function requires(): array
            {
                return [];
            }

            public function delivers(): bool
            {
                return true;
            }

            public function send(OutboundEmail $email): DeliveryResult
            {
                $this->sent[] = $email;

                return array_shift($this->results) ?? DeliveryResult::sent('id-last');
            }
        };
    }

    // ------------------------------------------------------------------ providers

    public function test_the_log_provider_writes_it_down_and_says_logged_not_sent(): void
    {
        Log::spy();
        $result = (new LogMailer)->send($this->email());

        $this->assertTrue($result->ok);
        $this->assertTrue($result->simulated);
        $this->assertStringStartsWith('log-', $result->messageId);
        $this->assertFalse((new LogMailer)->delivers());
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'email.logged' && $context['to'] === 'client@example.test');
    }

    public function test_resend_builds_the_request_the_api_documents_and_reads_its_answer(): void
    {
        config(['leadflow_mail.resend.key' => 're_test_123', 'leadflow_mail.resend.endpoint' => 'https://api.resend.test/emails']);
        Http::fake(['api.resend.test/*' => Http::response(['id' => 'msg_abc'], 200)]);

        $result = (new ResendMailer)->send($this->email());

        $this->assertTrue($result->ok);
        $this->assertSame('msg_abc', $result->messageId);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.resend.test/emails'
            && $request->hasHeader('Authorization', 'Bearer re_test_123')
            && $request->hasHeader('Idempotency-Key', 'key-client@example.test')
            && $request['to'] === ['عميل <client@example.test>']
            && $request['subject'] === 'تقرير'
            && $request['from'] === 'مهتم <reports@mohtam.test>'
            && $request['html'] === '<p>مرحبا</p>');
    }

    public function test_resend_says_which_failures_are_worth_another_try(): void
    {
        config(['leadflow_mail.resend.key' => 're_test_123', 'leadflow_mail.resend.endpoint' => 'https://api.resend.test/emails']);

        $cases = [[429, true], [500, true], [503, true], [422, false], [401, false], [403, false]];
        $sequence = Http::sequence();
        foreach ($cases as [$status]) {
            $sequence->push(['message' => 'nope'], $status);
        }
        Http::fake(['api.resend.test/*' => $sequence]);

        foreach ($cases as [$status, $retryable]) {
            $result = (new ResendMailer)->send($this->email());

            $this->assertFalse($result->ok);
            $this->assertSame($retryable, $result->retryable, "HTTP {$status}");
            $this->assertStringContainsString((string) $status, $result->error);
        }

        // The network itself failing is worth another try.
        Http::fake(['api.resend.test/*' => fn () => throw new ConnectionException('timeout')]);
        $this->assertTrue((new ResendMailer)->send($this->email())->retryable);
    }

    public function test_a_provider_without_its_keys_says_what_is_missing_and_does_not_try(): void
    {
        config(['leadflow_mail.resend.key' => null, 'leadflow_mail.ses.key' => null, 'leadflow_mail.ses.secret' => null, 'leadflow_mail.ses.region' => null, 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.username' => null]);
        Http::fake();

        $resend = (new ResendMailer)->send($this->email());
        $this->assertFalse($resend->ok);
        $this->assertFalse($resend->retryable, 'a retry cannot supply a missing key');
        $this->assertSame(['RESEND_API_KEY'], (new ResendMailer)->requires());
        Http::assertNothingSent();

        $ses = new SesMailer;
        $this->assertFalse($ses->isConfigured());
        $this->assertContains('AWS_ACCESS_KEY_ID', $ses->requires());
        $this->assertFalse($ses->send($this->email())->retryable);

        $smtp = new SmtpMailer;
        $this->assertFalse($smtp->isConfigured(), 'the stock 127.0.0.1 is not a mail server');
        $this->assertContains('MAIL_HOST', $smtp->requires());
        $this->assertFalse($smtp->send($this->email())->ok);

        config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.username' => 'user']);
        $this->assertTrue($smtp->isConfigured());
    }

    public function test_the_registry_never_falls_back_to_something_that_sends(): void
    {
        $registry = app(MailerRegistry::class);

        foreach (['log' => LogMailer::class, 'smtp' => SmtpMailer::class, 'ses' => SesMailer::class, 'resend' => ResendMailer::class] as $key => $class) {
            config(['leadflow_mail.provider' => $key]);
            $this->assertInstanceOf($class, $registry->current());
            $this->assertSame($key, $registry->current()->key());
        }

        config(['leadflow_mail.provider' => 'carrier-pigeon']);
        $this->assertInstanceOf(LogMailer::class, $registry->current());
        $this->assertCount(4, $registry->all());
    }

    // ------------------------------------------------------------------ queue, retry, failure log

    public function test_an_email_is_written_down_before_anything_is_sent_and_asking_twice_is_one_email(): void
    {
        Bus::fake();

        $a = $this->asTenant($this->t['tenant'], fn () => app(EmailDeliveries::class)->queue($this->email()));
        $b = $this->asTenant($this->t['tenant'], fn () => app(EmailDeliveries::class)->queue($this->email()));

        $this->assertSame($a->id, $b->id);
        $a = $a->fresh();
        $this->assertSame('queued', $a->status);
        $this->assertSame('log', $a->provider);
        $this->assertSame(0, $a->attempts);
        $this->assertSame(4, $a->max_attempts);
        Bus::assertDispatchedTimes(SendQueuedEmail::class, 1);
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => EmailDelivery::count()));
    }

    public function test_the_log_provider_ends_as_logged_with_one_attempt_on_record(): void
    {
        // The queue runs synchronously in tests: queueing is sending.
        $delivery = $this->asTenant($this->t['tenant'], fn () => app(EmailDeliveries::class)->queue($this->email())->fresh());

        $this->assertSame('logged', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->provider_message_id);
        $this->assertSame([true], $this->asTenant($this->t['tenant'], fn () => EmailDeliveryAttempt::pluck('ok')->all()));
    }

    public function test_a_failure_worth_retrying_waits_on_the_backoff_and_then_succeeds(): void
    {
        Bus::fake();
        $provider = $this->scripted([DeliveryResult::failed('timeout', true), DeliveryResult::failed('timeout again', true), DeliveryResult::sent('msg-9')]);
        $this->app->instance(MailerRegistry::class, tap(\Mockery::mock(MailerRegistry::class), fn ($m) => $m->shouldReceive('current')->andReturn($provider)->shouldReceive('get')->andReturn($provider)));
        $service = app(EmailDeliveries::class);

        $delivery = $this->asTenant($this->t['tenant'], fn () => $service->queue($this->email()));

        $one = $this->asTenant($this->t['tenant'], fn () => $service->attempt($delivery->id));
        $this->assertSame('queued', $one->status, 'it waits to be tried again');
        $this->assertSame(1, $one->attempts);
        $this->assertSame('timeout', $one->last_error);
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($one->next_attempt_at, false), 3, 'the first wait is a minute');

        $two = $this->asTenant($this->t['tenant'], fn () => $service->attempt($delivery->id));
        $this->assertEqualsWithDelta(300, now()->diffInSeconds($two->next_attempt_at, false), 3, 'then five');

        $three = $this->asTenant($this->t['tenant'], fn () => $service->attempt($delivery->id));
        $this->assertSame('sent', $three->status);
        $this->assertSame('msg-9', $three->provider_message_id);
        $this->assertNull($three->last_error);
        $this->assertSame([false, false, true], $this->asTenant($this->t['tenant'], fn () => EmailDeliveryAttempt::orderBy('attempt')->pluck('ok')->all()));
        // Dispatched once to start, and once for each wait.
        Bus::assertDispatchedTimes(SendQueuedEmail::class, 3);
    }

    public function test_a_failure_that_cannot_be_cured_by_waiting_fails_at_once_and_the_log_says_why(): void
    {
        Bus::fake();
        $provider = $this->scripted([DeliveryResult::failed('mailbox does not exist', false)]);
        $this->app->instance(MailerRegistry::class, tap(\Mockery::mock(MailerRegistry::class), fn ($m) => $m->shouldReceive('current')->andReturn($provider)->shouldReceive('get')->andReturn($provider)));
        $service = app(EmailDeliveries::class);

        $delivery = $this->asTenant($this->t['tenant'], fn () => $service->queue($this->email()));
        $after = $this->asTenant($this->t['tenant'], fn () => $service->attempt($delivery->id));

        $this->assertSame('failed', $after->status);
        $this->assertNotNull($after->failed_at);
        $this->assertSame('mailbox does not exist', $after->last_error);
        $attempt = $this->asTenant($this->t['tenant'], fn () => EmailDeliveryAttempt::first());
        $this->assertFalse($attempt->ok);
        $this->assertFalse($attempt->retryable);
        Bus::assertDispatchedTimes(SendQueuedEmail::class, 1, 'nothing is scheduled for a hopeless one');
    }

    public function test_a_failure_that_keeps_failing_gives_up_after_the_last_attempt(): void
    {
        Bus::fake();
        $provider = $this->scripted(array_fill(0, 10, DeliveryResult::failed('503 from provider', true)));
        $this->app->instance(MailerRegistry::class, tap(\Mockery::mock(MailerRegistry::class), fn ($m) => $m->shouldReceive('current')->andReturn($provider)->shouldReceive('get')->andReturn($provider)));
        $service = app(EmailDeliveries::class);
        $delivery = $this->asTenant($this->t['tenant'], fn () => $service->queue($this->email()));

        foreach (range(1, 6) as $_) {
            $this->asTenant($this->t['tenant'], fn () => $service->attempt($delivery->id));
        }

        $final = $this->asTenant($this->t['tenant'], fn () => $delivery->fresh());
        $this->assertSame('failed', $final->status);
        $this->assertSame(4, $final->attempts, 'three waits and the last try: four attempts, then it stops');
        $this->assertCount(4, $provider->sent, 'once failed, a duplicate job does nothing');
        $this->assertSame(4, $this->asTenant($this->t['tenant'], fn () => EmailDeliveryAttempt::where('ok', false)->count()));
    }

    public function test_a_delivery_that_is_not_waiting_is_not_sent_again(): void
    {
        $provider = $this->scripted([]);
        $this->app->instance(MailerRegistry::class, tap(\Mockery::mock(MailerRegistry::class), fn ($m) => $m->shouldReceive('current')->andReturn($provider)->shouldReceive('get')->andReturn($provider)));
        $service = app(EmailDeliveries::class);
        $delivery = $this->asTenant($this->t['tenant'], fn () => $service->queue($this->email()));

        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNull($this->asTenant($this->t['tenant'], fn () => $service->attempt($delivery->id)));
        $this->assertCount(1, $provider->sent);
    }

    // ------------------------------------------------------------------ the report, the status, the screen's API

    public function test_a_report_is_emailed_as_a_link_never_as_numbers_and_never_with_its_password(): void
    {
        $share = $this->share(['password' => 'secret-pass']);

        $response = $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/email", ['recipients' => ['A@example.test', 'a@example.test', 'b@example.test'], 'message' => "مرفق تقرير هذا الأسبوع\nمع التحية"])->assertCreated();

        $this->assertCount(2, $response->json('data.deliveries'), 'duplicates are one recipient');
        $deliveries = $this->asTenant($this->t['tenant'], fn () => EmailDelivery::withoutGlobalScopes()->orderBy('id')->get()->each->makeVisible('html'));

        $html = $deliveries[0]->html;
        $this->assertStringContainsString($share['url'], $html);
        $this->assertStringContainsString('مرفق تقرير هذا الأسبوع', $html);
        $this->assertStringNotContainsString('secret-pass', $html);
        $this->assertStringContainsString('محميّ بكلمة مرور', $html, 'the reader is told a password is needed');
        $this->assertStringNotContainsString('kpis', $html);
        $this->assertSame('تقرير الإدارة', $deliveries[0]->subject);
        $this->assertSame('report_share', $deliveries[0]->subject_type);
        $this->assertSame('logged', $deliveries[0]->status);

        // The same person, the same report, the same hour: not twice.
        $again = $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/email", ['recipients' => ['a@example.test']])->assertCreated();
        $this->assertSame($response->json('data.deliveries.0.id'), $again->json('data.deliveries.0.id'));
        $this->assertSame(2, $this->asTenant($this->t['tenant'], fn () => EmailDelivery::count()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'report_share.emailed']);
    }

    public function test_only_good_addresses_an_active_link_and_the_right_people_are_accepted(): void
    {
        $share = $this->share();
        $url = "/api/v1/report-shares/{$share['id']}/email";

        $this->actingAs($this->t['admin'])->postJson($url, ['recipients' => ['not-an-email']])->assertStatus(422);
        $this->actingAs($this->t['admin'])->postJson($url, ['recipients' => []])->assertStatus(422);
        $this->actingAs($this->t['admin'])->postJson($url, ['recipients' => array_map(fn ($i) => "x{$i}@example.test", range(1, 21))])->assertStatus(422);

        $this->actingAs($this->makeUser($this->t['tenant'], Role::Sales))->postJson($url, ['recipients' => ['a@example.test']])->assertForbidden();
        $this->actingAs($this->makeUser($this->t['tenant'], Role::MarketingManager))->postJson($url, ['recipients' => ['a@example.test']])->assertCreated();

        // A revoked link is a door that is shut: nobody is sent to it.
        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/revoke")->assertOk();
        $this->actingAs($this->t['admin'])->postJson($url, ['recipients' => ['a@example.test']])->assertStatus(422);

        $other = $this->provisionTenant('Else');
        $this->actingAs($other['admin'])->postJson($url, ['recipients' => ['a@example.test']])->assertNotFound();
    }

    public function test_the_status_says_which_provider_is_in_force_what_it_needs_and_how_each_email_went(): void
    {
        config(['leadflow_mail.provider' => 'resend', 'leadflow_mail.resend.key' => null]);
        $share = $this->share();
        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/email", ['recipients' => ['a@example.test']])->assertCreated();

        $status = $this->actingAs($this->t['admin'])->getJson('/api/v1/email-deliveries')->assertOk()->json('data');

        $this->assertSame('resend', $status['provider']['key']);
        $this->assertFalse($status['provider']['configured']);
        $this->assertSame(['RESEND_API_KEY'], $status['provider']['requires']);
        $this->assertEqualsCanonicalizing(['log', 'smtp', 'ses', 'resend'], array_column($status['providers'], 'key'));
        $this->assertSame(1, $status['counts']['failed']);

        $row = $status['deliveries'][0];
        $this->assertSame('failed', $row['status']);
        $this->assertSame('a@example.test', $row['to']);
        $this->assertStringContainsString('not configured', $row['error']);
        $this->assertFalse($row['log'][0]['ok']);
        $this->assertFalse($row['log'][0]['retryable']);
        $this->assertArrayNotHasKey('html', $row, 'the body of an email is not in a status list');

        $this->actingAs($this->makeUser($this->t['tenant'], Role::Sales))->getJson('/api/v1/email-deliveries')->assertForbidden();
        $other = $this->provisionTenant('Else');
        $this->assertSame([], $this->actingAs($other['admin'])->getJson('/api/v1/email-deliveries')->json('data.deliveries'));
    }

    public function test_a_failed_email_can_be_tried_again_on_the_provider_in_force_now(): void
    {
        config(['leadflow_mail.provider' => 'resend', 'leadflow_mail.resend.key' => null]);
        $share = $this->share();
        $id = $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/email", ['recipients' => ['a@example.test']])->json('data.deliveries.0.id');
        $this->assertSame('failed', $this->actingAs($this->t['admin'])->getJson('/api/v1/email-deliveries')->json('data.deliveries.0.status'));

        // The operator fixes the setup (here: back to the log) and presses retry.
        config(['leadflow_mail.provider' => 'log']);
        $retried = $this->actingAs($this->t['admin'])->postJson("/api/v1/email-deliveries/{$id}/retry")->assertOk()->json('data.delivery');

        $this->assertSame('logged', $retried['status']);
        $this->assertSame('log', $retried['provider']);
        $this->assertCount(2, $retried['log'], 'the failure stays on the log beside the new attempt');
        $this->assertSame([false, true], array_column($retried['log'], 'ok'));

        // Only a failed one can be retried.
        $this->actingAs($this->t['admin'])->postJson("/api/v1/email-deliveries/{$id}/retry")->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'email_delivery.retried']);
    }
}
