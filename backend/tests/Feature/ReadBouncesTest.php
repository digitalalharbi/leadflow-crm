<?php

namespace Tests\Feature;

use App\Console\Commands\ReadBounces;
use App\Domain\Mail\BounceRecorder;
use App\Domain\Mail\Imap\ImapMailbox;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\Suppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScriptedImapTransport;
use Tests\TestCase;

class ReadBouncesTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Bounce Co');
        $this->lead = $this->asTenant($this->t['tenant'], function () {
            $lead = Lead::create(['full_name' => 'عميل', 'phone' => '0551112222', 'phone_normalized' => '+966551112222', 'email' => 'project1@nasel.co']);
            LeadMessage::create(['lead_id' => $lead->id, 'channel' => MessageChannel::Email->value, 'direction' => MessageDirection::Outbound->value, 'subject' => 's', 'body' => 'مرحبا', 'status' => 'sent']);

            return $lead;
        });
    }

    private function mailbox(array $messages, ?ScriptedImapTransport &$transport = null): ImapMailbox
    {
        $transport = new ScriptedImapTransport(function (string $tag, string $command) use ($messages) {
            if (str_starts_with($command, 'UID SEARCH')) {
                return '* SEARCH '.implode(' ', array_keys($messages))."\r\n";
            }

            if (preg_match('/^UID FETCH (\d+)/', $command, $m)) {
                $src = $messages[(int) $m[1]];

                return "* 1 FETCH (UID {$m[1]} BODY[] {".strlen($src)."}\r\n".$src.")\r\n";
            }

            return '';
        });

        return new ImapMailbox($transport);
    }

    private const BOUNCE = "From: Mail Delivery Subsystem <mailer-daemon@googlemail.com>\r\nSubject: Delivery Status Notification (Failure)\r\n\r\nYour message wasn't delivered to project1@nasel.co because the address couldn't be found, or is unable to receive mail.\r\nThe response was: The email account that you tried to reach does not exist.\r\n";

    private const PERSON = "From: Sultan <sultan@example.org>\r\nSubject: Re: النرجس\r\n\r\nأريد زيارة. mailer-daemon is mentioned here but I am a person.\r\n";

    public function test_a_dead_address_is_suppressed_the_notice_marked_read_and_a_persons_message_left_alone(): void
    {
        $mailbox = $this->mailbox([5 => self::BOUNCE, 6 => self::PERSON], $transport);

        $result = (new ReadBounces)->process($mailbox, app(BounceRecorder::class), false, 100);

        $this->assertSame(['looked' => 2, 'stopped' => 1, 'unmatched' => 0, 'left' => 1], $result);
        $this->assertNotNull($this->lead->fresh()->email_bounced_at);
        $this->assertTrue(Suppression::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->where('value', 'project1@nasel.co')->where('reason', 'bounced')->exists());
        $sent = implode("\n", $transport->sent);
        $this->assertStringContainsString('UID STORE 5 +FLAGS', $sent, 'the notice that was acted on is marked read');
        $this->assertStringNotContainsString('UID STORE 6', $sent, 'a person\'s message is never touched');
    }

    public function test_a_dry_run_changes_nothing_anywhere(): void
    {
        $mailbox = $this->mailbox([5 => self::BOUNCE], $transport);

        $result = (new ReadBounces)->process($mailbox, app(BounceRecorder::class), true, 100);

        $this->assertSame(1, $result['stopped']);
        $this->assertNull($this->lead->fresh()->email_bounced_at);
        $this->assertSame(0, Suppression::withoutGlobalScopes()->where('value', 'project1@nasel.co')->count());
        $this->assertStringNotContainsString('STORE', implode("\n", $transport->sent));
    }

    public function test_a_bounce_for_an_address_we_never_wrote_to_is_reported_as_unmatched_and_still_marked_read(): void
    {
        $mailbox = $this->mailbox([7 => str_replace('project1@nasel.co', 'stranger@elsewhere.com', self::BOUNCE)], $transport);

        $result = (new ReadBounces)->process($mailbox, app(BounceRecorder::class), false, 100);

        $this->assertSame(1, $result['unmatched']);
        $this->assertNull($this->lead->fresh()->email_bounced_at);
    }

    public function test_the_command_does_nothing_until_it_is_switched_on(): void
    {
        config(['services.bounce_mailbox.enabled' => false]);

        $this->artisan('leadflow:read-bounces')->expectsOutputToContain('Bounce reading is off')->assertSuccessful();
    }
}
