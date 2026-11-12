<?php

namespace Tests\Support;

use App\Domain\Mail\Imap\ImapTransport;

/** A scripted IMAP server: a handler answers each command, and every command is kept so a test can see what was sent. */
class ScriptedImapTransport implements ImapTransport
{
    public array $sent = [];

    private string $buffer = "* OK ready\r\n";

    /** @param  callable(string $tag, string $command): string  $answer  the server's reply to one command, without the tagged OK */
    public function __construct(private $answer) {}

    public function write(string $data): void
    {
        $line = trim($data);
        $this->sent[] = $line;
        [$tag, $command] = explode(' ', $line, 2);
        $reply = ($this->answer)($tag, $command);
        $this->buffer .= $reply.(str_contains($reply, "{$tag} NO") ? '' : "{$tag} OK done\r\n");
    }

    public function readLine(): ?string
    {
        if ($this->buffer === '') {
            return null;
        }

        $pos = strpos($this->buffer, "\r\n");
        $line = $pos === false ? $this->buffer : substr($this->buffer, 0, $pos);
        $this->buffer = $pos === false ? '' : substr($this->buffer, $pos + 2);

        return $line;
    }

    public function read(int $length): string
    {
        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $data;
    }

    public function close(): void {}
}
