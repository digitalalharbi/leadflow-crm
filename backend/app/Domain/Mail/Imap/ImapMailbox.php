<?php

namespace App\Domain\Mail\Imap;

use RuntimeException;

/**
 * The few IMAP commands needed to read bounce notices: log in, open the inbox, list unread, fetch one message without
 * marking it read, and mark it read once it has been dealt with. Nothing else, so nothing here can lose a message.
 */
class ImapMailbox
{
    private int $tag = 0;

    public function __construct(private readonly ImapTransport $transport)
    {
        $greeting = $this->transport->readLine();

        if ($greeting === null || ! str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('The IMAP server did not greet us.');
        }
    }

    public function login(string $user, string $password): void
    {
        $this->run('LOGIN '.$this->quote($user).' '.$this->quote($password));
    }

    public function select(string $folder = 'INBOX'): void
    {
        $this->run('SELECT '.$this->quote($folder));
    }

    /** @return list<int> UIDs, oldest first */
    public function unseenUids(): array
    {
        foreach ($this->run('UID SEARCH UNSEEN') as $line) {
            if (is_string($line) && str_starts_with($line, '* SEARCH')) {
                return array_values(array_map('intval', array_filter(explode(' ', trim(substr($line, 8))), 'strlen')));
            }
        }

        return [];
    }

    /** The whole message, without setting \Seen. */
    public function source(int $uid): string
    {
        foreach ($this->run("UID FETCH {$uid} BODY.PEEK[]") as $part) {
            if (is_array($part)) {
                return $part['literal'];
            }
        }

        return '';
    }

    public function markSeen(int $uid): void
    {
        $this->run("UID STORE {$uid} +FLAGS (\\Seen)");
    }

    public function logout(): void
    {
        try {
            $this->run('LOGOUT');
        } finally {
            $this->transport->close();
        }
    }

    /**
     * Sends one tagged command and returns every untagged line (strings), with a literal attached as
     * ['line' => ..., 'literal' => ...], until the tagged answer. Anything but OK is an error.
     *
     * @return list<string|array{line: string, literal: string}>
     */
    private function run(string $command): array
    {
        $tag = 'A'.str_pad((string) ++$this->tag, 3, '0', STR_PAD_LEFT);
        $this->transport->write("{$tag} {$command}\r\n");
        $out = [];

        while (($line = $this->transport->readLine()) !== null) {
            if (str_starts_with($line, $tag.' ')) {
                if (! str_starts_with($line, $tag.' OK')) {
                    throw new RuntimeException('IMAP refused: '.preg_replace('/\s+/', ' ', substr($line, 5)));
                }

                return $out;
            }

            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $literal = $this->transport->read((int) $m[1]);
                $this->transport->readLine(); // the rest of the line after the literal, normally ")"
                $out[] = ['line' => $line, 'literal' => $literal];

                continue;
            }

            $out[] = $line;
        }

        throw new RuntimeException('IMAP connection closed before the server answered.');
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
