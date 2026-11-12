<?php

namespace Tests\Unit;

use App\Domain\Mail\Imap\ImapMailbox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ScriptedImapTransport;

class ImapMailboxTest extends TestCase
{
    public function test_it_lists_unread_fetches_without_marking_read_and_marks_read_only_when_told(): void
    {
        $source = "From: MAILER-DAEMON@x.net\r\nSubject: bounce\r\n\r\nbody é\r\n";
        $transport = new ScriptedImapTransport(function (string $tag, string $command) use ($source) {
            return match (true) {
                str_starts_with($command, 'UID SEARCH') => "* SEARCH 4 9\r\n",
                str_starts_with($command, 'UID FETCH 9') => '* 2 FETCH (UID 9 BODY[] {'.strlen($source)."}\r\n".$source.")\r\n",
                default => '',
            };
        });

        $mailbox = new ImapMailbox($transport);
        $mailbox->login('info@mohtam.pro', 'p"ass\\word');
        $mailbox->select('INBOX');

        $this->assertSame([4, 9], $mailbox->unseenUids());
        $this->assertSame($source, $mailbox->source(9));
        $mailbox->markSeen(9);
        $mailbox->logout();

        $sent = implode("\n", $transport->sent);
        $this->assertStringContainsString('LOGIN "info@mohtam.pro" "p\\"ass\\\\word"', $sent, 'the password is quoted, never broken out of its quotes');
        $this->assertStringContainsString('BODY.PEEK[]', $sent, 'reading must not mark a message read');
        $this->assertStringContainsString('UID STORE 9 +FLAGS (\\Seen)', $sent);
        $this->assertStringNotContainsString('DELETE', strtoupper($sent));
        $this->assertStringNotContainsString('EXPUNGE', strtoupper($sent));
    }

    public function test_an_empty_search_is_an_empty_list_and_a_refusal_is_an_error(): void
    {
        $empty = new ImapMailbox(new ScriptedImapTransport(fn ($tag, $cmd) => str_starts_with($cmd, 'UID SEARCH') ? "* SEARCH\r\n" : ''));
        $this->assertSame([], $empty->unseenUids());

        $refused = new ImapMailbox(new ScriptedImapTransport(fn ($tag, $cmd) => "{$tag} NO [AUTHENTICATIONFAILED] Invalid credentials\r\n"));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IMAP refused');
        $refused->login('u', 'wrong');
    }
}
