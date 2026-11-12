<?php

namespace App\Console\Commands;

use App\Domain\Mail\BounceRecorder;
use App\Domain\Mail\DeliveryReport;
use App\Domain\Mail\Imap\ImapMailbox;
use App\Domain\Mail\Imap\ImapTransport;
use App\Domain\Mail\Imap\SocketImapTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the notices mail servers send back when an email could not be delivered, and stops the journey writing to a
 * dead address. Hostinger's SMTP has no webhook for this, so the notices arrive in the sending mailbox.
 *
 * Off until BOUNCE_MAILBOX_ENABLED=true. Reads only: a message is fetched without being marked read, and only a notice
 * that was understood and acted on is then marked read. Everything else in the mailbox is left exactly as it was.
 * `--dry-run` shows what it would do and changes nothing at all.
 */
class ReadBounces extends Command
{
    protected $signature = 'leadflow:read-bounces {--dry-run : Show what would be stopped, change nothing} {--limit=100 : Most unread messages to look at}';

    protected $description = 'Read undeliverable-mail notices from the sending mailbox and stop writing to those addresses';

    public function handle(BounceRecorder $recorder): int
    {
        $config = (array) config('services.bounce_mailbox');
        $dry = (bool) $this->option('dry-run');

        if (! ($config['enabled'] ?? false) && ! $dry) {
            $this->line('Bounce reading is off (BOUNCE_MAILBOX_ENABLED is not true). Nothing done.');

            return self::SUCCESS;
        }

        if (blank($config['username'] ?? null) || blank($config['password'] ?? null)) {
            $this->error('No mailbox credentials are set for bounce reading.');

            return self::FAILURE;
        }

        try {
            $mailbox = new ImapMailbox($this->transport((string) $config['host'], (int) $config['port']));
            $mailbox->login((string) $config['username'], (string) $config['password']);
            $mailbox->select('INBOX');
            $result = $this->process($mailbox, $recorder, $dry, max(1, (int) $this->option('limit')));
            $mailbox->logout();
        } catch (Throwable $e) {
            // The reason, never the credentials.
            Log::warning('bounce_reader.failed', ['error' => $e->getMessage()]);
            $this->error('Could not read the mailbox: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dry ? '[dry run] ' : '')."looked at {$result['looked']} unread; stopped {$result['stopped']} dead addresses; {$result['unmatched']} had no lead here; left {$result['left']} alone.");
        Log::info('bounce_reader.done', $result + ['dry' => $dry]);

        return self::SUCCESS;
    }

    /** @return array{looked: int, stopped: int, unmatched: int, left: int} */
    public function process(ImapMailbox $mailbox, BounceRecorder $recorder, bool $dry, int $limit): array
    {
        $result = ['looked' => 0, 'stopped' => 0, 'unmatched' => 0, 'left' => 0];

        foreach (array_slice($mailbox->unseenUids(), -$limit) as $uid) {
            $result['looked']++;
            $source = $mailbox->source($uid);

            // Only a notice from a mail system is even considered; a person's message is never touched.
            if (! preg_match('/^From:.*(mailer-daemon|postmaster)/im', substr($source, 0, 4000))) {
                $result['left']++;

                continue;
            }

            $report = DeliveryReport::parse($source);

            if ($report === null) {
                $result['left']++;

                continue;
            }

            if ($this->output !== null) {
                $this->line(($dry ? '[dry run] ' : '').'dead address: '.$report['email'].' — '.$report['reason']);
            }

            if ($dry) {
                $result['stopped']++;

                continue;
            }

            $recorder->record($report['email'], $report['reason']) === 'bounces' ? $result['stopped']++ : $result['unmatched']++;
            $mailbox->markSeen($uid);
        }

        return $result;
    }

    protected function transport(string $host, int $port): ImapTransport
    {
        return new SocketImapTransport($host, $port);
    }
}
