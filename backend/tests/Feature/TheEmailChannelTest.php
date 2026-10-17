<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Jobs\SendEmailMessage;
use App\Mail\LeadEmail;
use App\Models\CommissioningRun;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadEngagement;
use App\Models\LeadMessage;
use App\Models\MessageTemplate;
use App\Services\Email\EmailManager;
use App\Services\Email\TrackedLinks;
use App\Services\Leads\ActivityLogger;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Email is a channel, held to the rules WhatsApp already lives by.
 *
 * Written down before it is sent; never sent to somebody who unsubscribed, even
 * by a job that was already in the queue when they did; retried only for
 * failures that describe the attempt; and honest about what happened to it —
 * "sent" when a server took it, "read" only when a person's software said so.
 */
class TheEmailChannelTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->t = $this->provisionTenant('Mail Co');
        $this->lead = $this->makeLead();
    }

    private function makeLead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'غادة الحربي',
            'email' => 'ghada@example.test',
            'phone_normalized' => '+9665'.random_int(10000000, 99999999),
            'status' => 'new',
            'assigned_to' => $this->t['admin']->id,
        ], $attributes)));
    }

    private function send(array $payload = [], ?Lead $lead = null)
    {
        return $this->actingAs($this->t['admin'])->postJson(
            '/api/v1/leads/'.($lead ?? $this->lead)->uuid.'/emails',
            $payload + ['subject' => 'تفاصيل المشروع', 'body' => 'مرحبًا، هذه التفاصيل.'],
        );
    }

    private function message(): LeadMessage
    {
        return LeadMessage::withoutGlobalScopes()->where('channel', 'email')->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------ sending

    public function test_a_rep_can_email_a_lead(): void
    {
        $this->send()->assertCreated();

        $message = $this->message();

        $this->assertSame('sent', $message->status->value);
        $this->assertSame('تفاصيل المشروع', $message->subject);
        $this->assertSame($this->t['admin']->id, $message->user_id);
        $this->assertNotNull($message->sent_at);
        $this->assertNotNull($message->tracking_token);
        $this->assertNotNull($this->lead->fresh()->first_contact_at);

        Mail::assertSent(LeadEmail::class, fn (LeadEmail $m) => $m->hasTo('ghada@example.test'));
    }

    public function test_it_is_on_the_timeline(): void
    {
        $this->send()->assertCreated();

        $this->assertSame(1, LeadActivity::withoutGlobalScopes()
            ->where('type', 'email_sent')->count());
    }

    public function test_the_message_carries_an_open_pixel_a_tracked_link_and_an_unsubscribe(): void
    {
        $this->send(['body' => 'التفاصيل: https://example.test/brochure.pdf'])->assertCreated();

        $token = $this->message()->tracking_token;

        Mail::assertSent(LeadEmail::class, function (LeadEmail $m) use ($token) {
            return str_contains($m->htmlBody, '/e/o/'.$token)
                && str_contains($m->htmlBody, '/e/c/'.$token)
                // The destination is not in the clear next to our domain.
                && ! str_contains($m->htmlBody, 'href="https://example.test')
                && str_contains($m->unsubscribeUrl, '/e/u/'.$token);
        });
    }

    public function test_the_body_is_escaped(): void
    {
        $this->send(['body' => '<script>alert(1)</script>'])->assertCreated();

        Mail::assertSent(LeadEmail::class, fn (LeadEmail $m) => ! str_contains($m->htmlBody, '<script>'));
    }

    public function test_a_lead_with_no_address_is_refused(): void
    {
        $lead = $this->makeLead(['email' => null]);

        $this->send([], $lead)->assertStatus(422);

        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->where('channel', 'email')->count());
    }

    public function test_an_empty_subject_or_body_is_refused(): void
    {
        $this->send(['subject' => ' '])->assertStatus(422);
        $this->send(['body' => ' '])->assertStatus(422);
    }

    public function test_four_in_ten_minutes_is_a_loop_and_is_stopped(): void
    {
        foreach (range(1, 3) as $i) {
            $this->send(['subject' => "رسالة $i"])->assertCreated();
        }

        $this->send(['subject' => 'الرابعة'])->assertStatus(422);

        Mail::assertSentCount(3);
    }

    // -------------------------------------------------------- unsubscribing

    public function test_unsubscribing_stops_all_further_email(): void
    {
        $this->send()->assertCreated();
        $token = $this->message()->tracking_token;

        $this->get("/e/u/$token")->assertOk()->assertSee('تم إلغاء اشتراكك');

        $this->assertNotNull($this->lead->fresh()->email_opt_out_at);

        $this->send(['subject' => 'بعد الإلغاء'])->assertStatus(422);
    }

    public function test_a_mail_clients_one_click_post_unsubscribes_too(): void
    {
        $this->send()->assertCreated();

        $this->post('/e/u/'.$this->message()->tracking_token)->assertOk();

        $this->assertNotNull($this->lead->fresh()->email_opt_out_at);
    }

    public function test_an_unknown_token_gets_the_same_page_and_changes_nothing(): void
    {
        $this->get('/e/u/not-a-real-token')->assertOk()->assertSee('تم إلغاء اشتراكك');

        $this->assertNull($this->lead->fresh()->email_opt_out_at);
    }

    /** The unsubscribe must beat a job that was already waiting in the queue. */
    public function test_a_job_already_queued_does_not_email_somebody_who_just_unsubscribed(): void
    {
        $this->send()->assertCreated();

        $pending = $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $this->lead->id,
            'direction' => 'outbound', 'channel' => 'email', 'provider' => 'mock',
            'message_type' => 'text', 'subject' => 'x', 'body' => 'y',
            'status' => 'pending', 'attempts' => 0, 'tracking_token' => 'tok-queued',
        ]));

        $this->lead->forceFill(['email_opt_out_at' => now()])->saveQuietly();

        Mail::fake();
        (new SendEmailMessage($pending->id))->handle(...$this->jobDeps());

        Mail::assertNothingSent();
        $this->assertSame('failed', $pending->fresh()->status->value);
        $this->assertSame('opted_out', $pending->fresh()->failure_code);
    }

    public function test_a_job_delivered_twice_sends_once(): void
    {
        $this->send()->assertCreated();
        $id = $this->message()->id;

        (new SendEmailMessage($id))->handle(...$this->jobDeps());
        (new SendEmailMessage($id))->handle(...$this->jobDeps());

        Mail::assertSentCount(1);
    }

    private function jobDeps(): array
    {
        return [
            app(EmailManager::class),
            app(TrackedLinks::class),
            app(ActivityLogger::class),
            app(TenantContext::class),
        ];
    }

    // ------------------------------------------------------------- tracking

    public function test_an_open_is_recorded_once_and_marks_the_message_read(): void
    {
        $this->send()->assertCreated();
        $token = $this->message()->tracking_token;

        $this->get("/e/o/$token")->assertOk()->assertHeader('Content-Type', 'image/gif');
        // A preview pane and the person reading are one event.
        $this->get("/e/o/$token")->assertOk();

        $this->assertSame(1, LeadEngagement::withoutGlobalScopes()->where('type', 'email_opened')->count());
        $this->assertSame('read', $this->message()->status->value);
        $this->assertNotNull($this->message()->read_at);
    }

    public function test_an_open_raises_the_score(): void
    {
        $before = $this->lead->fresh()->score;
        $this->send()->assertCreated();

        $this->get('/e/o/'.$this->message()->tracking_token)->assertOk();

        $this->assertGreaterThan((int) $before, (int) $this->lead->fresh()->score);
    }

    public function test_a_tracked_link_redirects_and_records_the_click(): void
    {
        $this->send(['body' => 'https://example.test/brochure.pdf'])->assertCreated();
        $token = $this->message()->tracking_token;
        $links = app(TrackedLinks::class);

        $this->get($links->clickUrl($token, 'https://example.test/brochure.pdf'))
            ->assertRedirect('https://example.test/brochure.pdf');

        $this->assertSame(1, LeadEngagement::withoutGlobalScopes()->where('type', 'link_clicked')->count());
    }

    /** Without the signature this is an open redirect on our domain. */
    public function test_a_link_we_did_not_write_does_not_redirect(): void
    {
        $this->send()->assertCreated();
        $token = $this->message()->tracking_token;
        $url = rtrim(strtr(base64_encode('https://evil.test'), '+/', '-_'), '=');

        $this->get("/e/c/$token?u=$url&s=".str_repeat('a', 32))->assertNotFound();
        $this->get("/e/c/$token?u=$url")->assertNotFound();
    }

    public function test_a_signature_for_one_message_does_not_work_on_another(): void
    {
        $links = app(TrackedLinks::class);
        $this->send()->assertCreated();
        $token = $this->message()->tracking_token;

        $forged = $links->clickUrl('some-other-token', 'https://example.test');
        parse_str((string) parse_url($forged, PHP_URL_QUERY), $query);

        $this->get("/e/c/$token?".http_build_query($query))->assertNotFound();
    }

    public function test_only_web_addresses_can_be_redirected_to(): void
    {
        $links = app(TrackedLinks::class);

        $this->assertFalse($links->verify('t', 'javascript:alert(1)', $links->sign('t', 'javascript:alert(1)')));
        $this->assertTrue($links->verify('t', 'https://ok.test', $links->sign('t', 'https://ok.test')));
    }

    public function test_an_unknown_open_token_still_returns_the_pixel(): void
    {
        $this->get('/e/o/nothing-here')->assertOk()->assertHeader('Content-Type', 'image/gif');
    }

    // -------------------------------------------------------------- retries

    private function breakTheTransport(string $message): void
    {
        // Undo the fake: it rebinds the manager in the container, so a real one
        // has to be asked for afresh before a transport can be registered on it.
        app()->forgetInstance('mail.manager');
        Facade::clearResolvedInstance('mail.manager');
        Mail::extend('boom', fn () => new class($message) extends AbstractTransport
        {
            public function __construct(private string $why)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                throw new TransportException($this->why);
            }

            public function __toString(): string
            {
                return 'boom://';
            }
        });
        config(['mail.mailers.boom' => ['transport' => 'boom'], 'mail.default' => 'boom']);
        Mail::forgetMailers();
    }

    public function test_a_dropped_connection_is_retried_and_then_given_up_on(): void
    {
        $this->breakTheTransport('Connection timed out');

        $this->send()->assertCreated();

        $message = $this->message();
        $this->assertSame(SendEmailMessage::MAX_ATTEMPTS, $message->attempts);
        $this->assertSame('failed', $message->status->value);
        $this->assertSame('gave_up', $message->failure_code);
    }

    public function test_a_refused_address_is_not_retried(): void
    {
        $this->breakTheTransport('Expected response code "250" but got code "550", with message "550 5.1.1 mailbox unavailable".');

        $this->send()->assertCreated();

        $message = $this->message();
        $this->assertSame(1, $message->attempts);
        $this->assertSame('failed', $message->status->value);
        $this->assertSame('rejected', $message->failure_code);
        $this->assertStringContainsString('550', (string) $message->failure_reason);
    }

    // ------------------------------------------------------------ templates

    public function test_a_workspace_starts_with_email_templates_of_its_own(): void
    {
        $email = $this->actingAs($this->t['admin'])->getJson('/api/v1/message-templates?channel=email')
            ->assertOk()->json('data.templates');

        $this->assertNotEmpty($email);
        $this->assertSame(['email'], array_values(array_unique(array_column($email, 'channel'))));
        $this->assertNotEmpty($email[0]['subject']);
    }

    public function test_the_whatsapp_list_does_not_gain_email_templates(): void
    {
        $channels = array_column(
            $this->actingAs($this->t['admin'])->getJson('/api/v1/message-templates')->json('data.templates'),
            'channel',
        );

        $this->assertSame(['whatsapp'], array_values(array_unique($channels)));
    }

    public function test_a_saved_email_is_filled_in_for_the_lead(): void
    {
        $template = $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('channel', 'email')->firstOrFail());

        $this->send(['subject' => null, 'body' => null, 'template_uuid' => $template->uuid])->assertCreated();

        $message = $this->message();
        $this->assertStringContainsString('غادة', (string) $message->body);
        $this->assertStringNotContainsString('{{', (string) $message->body.$message->subject);
        $this->assertSame($template->name, $message->template_name);
    }

    public function test_a_whatsapp_template_cannot_be_sent_as_an_email(): void
    {
        $wa = $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('channel', 'whatsapp')->firstOrFail());

        $this->send(['template_uuid' => $wa->uuid])->assertStatus(422);
    }

    // ----------------------------------------------------------- automation

    public function test_a_rule_can_send_an_email(): void
    {
        $template = $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('channel', 'email')->firstOrFail());

        $result = $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)
            ->execute($this->lead->fresh(), ['type' => 'send_email', 'template_uuid' => $template->uuid]));

        $this->assertTrue($result['ok'], $result['description']);
        Mail::assertSent(LeadEmail::class);
        // Written as the rule, not as the lead's owner.
        $this->assertNull($this->message()->user_id);
    }

    public function test_a_rule_reports_instead_of_throwing_when_the_lead_unsubscribed(): void
    {
        $template = $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('channel', 'email')->firstOrFail());
        $this->lead->forceFill(['email_opt_out_at' => now()])->saveQuietly();

        $result = $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)
            ->execute($this->lead->fresh(), ['type' => 'send_email', 'template_uuid' => $template->uuid]));

        $this->assertFalse($result['ok']);
        Mail::assertNothingSent();
    }

    public function test_a_rule_cannot_use_a_whatsapp_template_for_email(): void
    {
        $wa = $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('channel', 'whatsapp')->firstOrFail());

        $result = $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)
            ->execute($this->lead->fresh(), ['type' => 'send_email', 'template_uuid' => $wa->uuid]));

        $this->assertFalse($result['ok']);
        Mail::assertNothingSent();
    }

    public function test_the_rule_editor_accepts_the_action(): void
    {
        $template = $this->asTenant($this->t['tenant'], fn () => MessageTemplate::where('channel', 'email')->firstOrFail());

        $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/rules', [
            'name' => 'بريد ترحيب', 'trigger' => 'lead.created',
            'conditions' => [], 'condition_match' => 'all',
            'actions' => [['type' => 'send_email', 'template_uuid' => $template->uuid]],
        ])->assertCreated();
    }

    // ------------------------------------------------------------- settings

    public function test_the_password_is_stored_and_never_returned(): void
    {
        $response = $this->actingAs($this->t['admin'])->patchJson('/api/v1/integrations/email', [
            'host' => 'smtp.example.test', 'port' => 587, 'username' => 'apikey',
            'password' => 'a-very-secret-value', 'from_address' => 'sales@example.test', 'from_name' => 'المبيعات',
        ])->assertOk();

        $this->assertTrue($response->json('data.has_password'));
        $this->assertStringNotContainsString('a-very-secret-value', $response->getContent());
        $this->assertStringNotContainsString('a-very-secret-value',
            $this->actingAs($this->t['admin'])->getJson('/api/v1/integrations/email')->getContent());
    }

    public function test_saving_with_a_blank_password_keeps_the_one_stored(): void
    {
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/integrations/email', [
            'host' => 'smtp.example.test', 'username' => 'apikey', 'password' => 'a-very-secret-value',
            'from_address' => 'sales@example.test',
        ])->assertOk();

        $this->actingAs($this->t['admin'])->patchJson('/api/v1/integrations/email', [
            'from_name' => 'اسم جديد', 'password' => '',
        ])->assertOk()->assertJsonPath('data.has_password', true);
    }

    public function test_a_rep_cannot_read_or_change_the_mail_server(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->patchJson('/api/v1/integrations/email', ['host' => 'x.test'])->assertForbidden();
        $this->actingAs($rep)->getJson('/api/v1/integrations/email')->assertForbidden();
        // But they can learn whether sending works, which is all the screen needs.
        $this->actingAs($rep)->getJson('/api/v1/email/capability')->assertOk();
    }

    public function test_a_failed_test_is_recorded_as_a_failure_and_never_as_ready(): void
    {
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/integrations/email', [
            'host' => '127.0.0.1', 'port' => 1, 'username' => 'u', 'password' => 'p-p-p-p-p',
            'from_address' => 'sales@example.test',
        ])->assertOk();

        $this->actingAs($this->t['admin'])->postJson('/api/v1/integrations/email/test')->assertStatus(422);

        $run = CommissioningRun::latest('id')->first();
        $this->assertSame('email', $run->key);
        $this->assertFalse($run->ok);
        $this->assertSame('error', $this->asTenant($this->t['tenant'], fn () => Integration::where('provider', 'email')->first())->status);
    }

    public function test_testing_before_configuring_is_refused(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/integrations/email/test')->assertStatus(422);
    }

    public function test_one_workspace_never_sees_anothers_mail_server(): void
    {
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/integrations/email', [
            'host' => 'smtp.mine.test', 'username' => 'u', 'password' => 'p-p-p-p-p', 'from_address' => 'a@mine.test',
        ])->assertOk();

        $other = $this->provisionTenant('Elsewhere');

        $this->actingAs($other['admin'])->getJson('/api/v1/integrations/email')
            ->assertOk()->assertJsonPath('data.host', null)->assertJsonPath('data.has_password', false);
    }

    // ---------------------------------------------------------- the ladder

    private function emailState(): string
    {
        $rows = $this->actingAs($this->t['admin'])->getJson('/api/v1/integrations/health')->json('data.integrations');

        return collect($rows)->firstWhere('key', 'email')['state'];
    }

    public function test_the_ladder_does_not_overclaim_email(): void
    {
        // Nothing entered, nothing sent.
        $this->assertSame('not_configured', $this->emailState());

        // The pipeline ran on the log/array transport: sandbox, not live.
        $this->send()->assertCreated();
        $this->assertSame('sandbox_verified', $this->emailState());

        // An open on a message nothing real sent proves nothing.
        $this->get('/e/o/'.$this->message()->tracking_token)->assertOk();
        $this->assertSame('sandbox_verified', $this->emailState());
    }

    public function test_credentials_alone_are_only_code_verified_and_a_server_that_accepted_a_test_is_ready(): void
    {
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/integrations/email', [
            'host' => 'smtp.example.test', 'username' => 'u', 'password' => 'p-p-p-p-p', 'from_address' => 'a@example.test',
        ])->assertOk();

        $this->assertSame('code_verified', $this->emailState());

        CommissioningRun::create(['key' => 'email', 'ok' => true, 'steps' => []]);

        $this->assertSame('ready', $this->emailState());
    }

    public function test_a_customers_own_software_opening_a_real_message_is_what_makes_it_live(): void
    {
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/integrations/email', [
            'host' => 'smtp.example.test', 'username' => 'u', 'password' => 'p-p-p-p-p', 'from_address' => 'a@example.test',
        ])->assertOk();

        $this->send()->assertCreated();
        $message = $this->message();

        // The message went out through the real transport (the test fakes the
        // mailer, so say so on the row, as the sender would have).
        $message->forceFill(['provider' => 'smtp'])->save();
        $this->get('/e/o/'.$message->tracking_token)->assertOk();

        $this->assertSame('live_verified', $this->emailState());
    }

    public function test_the_provider_enum_knows_email_and_it_is_not_a_lead_source(): void
    {
        $this->assertSame('manual', IntegrationProvider::Email->defaultPlatform()->value);
    }
}
