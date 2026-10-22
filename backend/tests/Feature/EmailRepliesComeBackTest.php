<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\ScheduledAction;
use App\Services\Email\EmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FakeMailer;
use Tests\TestCase;

/**
 * An email conversation is a conversation: the customer's answer comes back to
 * the lead it answers, in the thread it answers, and moves the lead the way a
 * WhatsApp reply does. A dead address is remembered.
 */
class EmailRepliesComeBackTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Lead $lead;

    private FakeMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = $this->fakeMailer();
        config(['services.email_inbound.domain' => 'reply.muhtam.test', 'services.email_inbound.secret' => 'inbound-secret']);

        $this->t = $this->provisionTenant('Inbox Co');

        $this->lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'نوف العتيبي', 'email' => 'nouf@example.com',
            'phone' => '+966551110000', 'phone_normalized' => '+966551110000', 'status' => 'contacted',
            'assigned_to' => $this->t['admin']->id,
        ]));
    }

    private function sendOne(string $subject = 'تفاصيل المشروع'): LeadMessage
    {
        return $this->asTenant($this->t['tenant'], fn () => app(EmailSender::class)->send($this->lead, $subject, 'مرحبًا نوف'));
    }

    public function test_each_email_carries_its_own_reply_address_and_threads_under_the_last(): void
    {
        $first = $this->sendOne();
        $second = $this->sendOne('متابعة');

        $sent = $this->mailer->sent;
        $this->assertCount(2, $sent);

        $this->assertSame("reply+{$first->tracking_token}@reply.muhtam.test", $sent[0]->replyTo);
        $this->assertArrayNotHasKey('In-Reply-To', $sent[0]->headers);
        $this->assertSame($first->fresh()->provider_message_id, $sent[1]->headers['In-Reply-To']);
        $this->assertSame($sent[0]->headers['Message-ID'], $sent[1]->headers['References'], 'the thread names the first message');
        $this->assertSame("reply+{$second->tracking_token}@reply.muhtam.test", $sent[1]->replyTo);
    }

    public function test_a_postmark_reply_lands_in_the_thread_and_hands_the_lead_to_a_person(): void
    {
        $sent = $this->sendOne();
        $waiting = $this->asTenant($this->t['tenant'], fn () => ScheduledAction::create([
            'lead_id' => $this->lead->id, 'rule_name' => 'رحلة', 'action' => ['type' => 'send_email', 'purpose' => 'nurture', 'unless' => 'replied'],
            'run_at' => now()->addDay(), 'status' => ScheduledAction::PENDING,
        ]));

        $this->postJson('/api/v1/integrations/email/inbound/inbound-secret', [
            'FromFull' => ['Email' => 'nouf@example.com'],
            'OriginalRecipient' => "reply+{$sent->tracking_token}@reply.muhtam.test",
            'Subject' => 'Re: تفاصيل المشروع',
            'StrippedTextReply' => 'نعم أرغب بزيارة الموقع',
            'MessageID' => 'postmark-1',
            'Headers' => [['Name' => 'Message-ID', 'Value' => '<abc@mail.example.com>']],
        ])->assertOk()->assertJsonPath('data.replies', 1);

        $reply = $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $this->lead->id)->where('direction', MessageDirection::Inbound->value)->firstOrFail());
        $this->assertSame(MessageChannel::Email, $reply->channel);
        $this->assertSame('نعم أرغب بزيارة الموقع', $reply->body);

        $lead = $this->asTenant($this->t['tenant'], fn () => $this->lead->fresh());
        $this->assertNotNull($lead->first_response_at);
        $this->assertSame('replied', $lead->needs_human_reason);
        $this->assertSame(ScheduledAction::CANCELLED, $waiting->fresh()->status);

        // The same delivery again is the same reply.
        $this->postJson('/api/v1/integrations/email/inbound/inbound-secret', [
            'OriginalRecipient' => "reply+{$sent->tracking_token}@reply.muhtam.test",
            'Headers' => [['Name' => 'Message-ID', 'Value' => '<abc@mail.example.com>']],
            'StrippedTextReply' => 'نعم أرغب بزيارة الموقع',
        ])->assertOk();
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('direction', MessageDirection::Inbound->value)->count()));
    }

    public function test_a_mailgun_reply_is_matched_by_the_email_it_answers(): void
    {
        $sent = $this->sendOne();

        $this->post('/api/v1/integrations/email/inbound/inbound-secret', [
            'sender' => 'nouf@example.com',
            'recipient' => 'sales@company.example',
            'subject' => 'Re: تفاصيل المشروع',
            'stripped-text' => 'متى يمكنني الزيارة؟',
            'Message-Id' => '<mg-1@example.com>',
            'In-Reply-To' => '<'.$sent->fresh()->provider_message_id.'>',
        ])->assertOk()->assertJsonPath('data.replies', 1);
    }

    public function test_a_reply_from_a_known_address_to_nothing_we_sent_is_not_guessed_at(): void
    {
        $this->postJson('/api/v1/integrations/email/inbound/inbound-secret', [
            'From' => 'nouf@example.com', 'To' => 'someone@else.example', 'TextBody' => 'hi',
        ])->assertOk()->assertJsonPath('data.unmatched', 1);

        $this->assertSame(0, $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('direction', MessageDirection::Inbound->value)->count()));
    }

    public function test_a_bounce_marks_the_address_dead_and_nothing_is_sent_to_it_again(): void
    {
        $sent = $this->sendOne();

        $this->postJson('/api/v1/integrations/email/inbound/inbound-secret', [
            'RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'NOUF@example.com', 'Description' => 'The mailbox does not exist',
        ])->assertOk()->assertJsonPath('data.bounces', 1);

        $this->assertSame(MessageStatus::Failed, $sent->fresh()->status);
        $this->assertSame('bounced', $sent->fresh()->failure_code);
        $this->assertNotNull($this->asTenant($this->t['tenant'], fn () => $this->lead->fresh()->email_bounced_at));

        $this->expectException(RuntimeException::class);
        $this->asTenant($this->t['tenant'], fn () => app(EmailSender::class)->send($this->lead->fresh(), 'مرة أخرى', 'x'));
    }

    public function test_sendgrid_and_mailgun_bounce_events_are_understood(): void
    {
        $this->sendOne();

        $this->postJson('/api/v1/integrations/email/inbound/inbound-secret', [
            ['event' => 'delivered', 'email' => 'nouf@example.com'],
            ['event' => 'bounce', 'email' => 'nouf@example.com', 'reason' => '550 no such user'],
        ])->assertOk()->assertJsonPath('data.bounces', 1);

        $this->postJson('/api/v1/integrations/email/inbound/inbound-secret', [
            'event-data' => ['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'nouf@example.com', 'delivery-status' => ['message' => 'No mailbox']],
        ])->assertOk()->assertJsonPath('data.bounces', 1);
    }

    public function test_without_the_platform_secret_nothing_is_accepted(): void
    {
        $this->postJson('/api/v1/integrations/email/inbound/wrong', ['RecordType' => 'Bounce', 'Email' => 'nouf@example.com'])->assertNotFound();

        config(['services.email_inbound.secret' => null]);
        $this->postJson('/api/v1/integrations/email/inbound/inbound-secret', ['RecordType' => 'Bounce', 'Email' => 'nouf@example.com'])->assertNotFound();

        $this->assertNull($this->asTenant($this->t['tenant'], fn () => $this->lead->fresh()->email_bounced_at));
    }

    public function test_the_screen_is_told_whether_replies_come_back(): void
    {
        $this->actingAs($this->t['admin'])->getJson('/api/v1/email/capability')->assertJsonPath('data.replies_tracked', true);

        config(['services.email_inbound.domain' => null]);
        $this->actingAs($this->t['admin'])->getJson('/api/v1/email/capability')->assertJsonPath('data.replies_tracked', false);
    }
}
