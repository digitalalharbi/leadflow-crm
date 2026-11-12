<?php

namespace App\Domain\Mail\Imap;

/** The wire under an IMAP session: lines in, lines and counted bytes out. A real socket in production, a script in tests. */
interface ImapTransport
{
    public function write(string $data): void;

    /** One line, without its line ending; null when the server closed the connection. */
    public function readLine(): ?string;

    /** Exactly $length bytes (the body of an IMAP literal). */
    public function read(int $length): string;

    public function close(): void;
}
