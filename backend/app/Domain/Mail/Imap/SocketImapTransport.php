<?php

namespace App\Domain\Mail\Imap;

use RuntimeException;

/** IMAP over TLS. Read-only use only: nothing here sends, moves or deletes a message. */
class SocketImapTransport implements ImapTransport
{
    /** @var resource */
    private $stream;

    public function __construct(string $host, int $port = 993, int $timeoutSeconds = 20)
    {
        $stream = @stream_socket_client("ssl://{$host}:{$port}", $errno, $error, $timeoutSeconds);

        if ($stream === false) {
            throw new RuntimeException("IMAP connection to {$host}:{$port} failed: {$error}");
        }

        stream_set_timeout($stream, $timeoutSeconds);
        $this->stream = $stream;
    }

    public function write(string $data): void
    {
        fwrite($this->stream, $data);
    }

    public function readLine(): ?string
    {
        $line = fgets($this->stream);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    public function read(int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($this->stream, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('IMAP connection ended in the middle of a message.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
