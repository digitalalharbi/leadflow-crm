<?php

namespace Tests\Unit;

use App\Domain\Mail\DeliveryReport;
use PHPUnit\Framework\TestCase;

class DeliveryReportTest extends TestCase
{
    public function test_a_gmail_address_not_found_is_a_hard_bounce_for_that_address(): void
    {
        $raw = "Subject: Delivery Status Notification (Failure)\nFrom: Mail Delivery Subsystem <mailer-daemon@googlemail.com>\n\n** Address not found **\n\nYour message wasn't delivered to project1@nasel.co because the address couldn't be found, or is unable to receive mail.\n\nThe response was:\n\nThe email account that you tried to reach does not exist. Please try double-checking the recipient's email address for typos. For more information, go to https://support.google.com/mail/?p=NoSuchUser\n";

        $report = DeliveryReport::parse($raw);

        $this->assertSame('project1@nasel.co', $report['email']);
        $this->assertStringContainsString('does not exist', $report['reason']);
    }

    public function test_an_outlook_mailbox_unavailable_is_a_hard_bounce_taken_from_the_notice_not_the_attached_message(): void
    {
        $raw = "Subject: Undelivered Mail Returned to Sender\nFrom: Mail Delivery System <MAILER-DAEMON@mailchannels.net>\nTo: no-reply@mohtam.pro\n\nThis is the mail system at host relay.mailchannels.net.\n\n<kalbo0oshy@hotmail.com>: host hotmail-com.olc.protection.outlook.com[52.101.41.126] said: 550 5.5.0\n    Requested action not taken: mailbox unavailable (S2017062302).\n\n--- Below this line is a copy of the message.\nTo: someone-else@example.com\n";

        $report = DeliveryReport::parse($raw);

        $this->assertSame('kalbo0oshy@hotmail.com', $report['email']);
    }

    public function test_a_standard_delivery_status_part_names_the_failed_recipient(): void
    {
        $raw = "Subject: Delivery Status Notification (Failure)\n\nFinal-Recipient: rfc822; Lead@Example-Corp.com\nAction: failed\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 5.1.1 user unknown\n";

        $this->assertSame('lead@example-corp.com', DeliveryReport::parse($raw)['email']);
    }

    public function test_a_full_mailbox_a_temporary_error_or_a_refusal_about_us_is_never_taken_for_a_dead_address(): void
    {
        $full = "Subject: Delivery Status Notification (Failure)\n\nFinal-Recipient: rfc822; a@b.com\nStatus: 4.2.2\nDiagnostic-Code: smtp; 452 4.2.2 mailbox full, try again later\n";
        $policy = "Subject: Undelivered Mail Returned to Sender\n\n<a@b.com>: host mx.b.com said: 554 5.7.1 Message rejected as spam by policy\n";
        $quota = "Subject: Delivery Status Notification (Failure)\n\nFinal-Recipient: rfc822; a@b.com\nStatus: 5.2.2\nDiagnostic-Code: smtp; 552 5.2.2 mailbox is full over quota\n";

        $this->assertNull(DeliveryReport::parse($full));
        $this->assertNull(DeliveryReport::parse($policy));
        $this->assertNull(DeliveryReport::parse($quota));
    }

    public function test_an_ordinary_message_is_not_a_bounce(): void
    {
        $this->assertNull(DeliveryReport::parse("Subject: مرحبا\n\nأريد تفاصيل المشروع. رقمي 0551234567\n"));
        $this->assertNull(DeliveryReport::parse("Subject: Confirm your email forwarder request\n\nClick to confirm.\n"));
    }
}
