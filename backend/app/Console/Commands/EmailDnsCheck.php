<?php

namespace App\Console\Commands;

use App\Domain\Mail\EmailDnsReadiness;
use Illuminate\Console\Command;

/**
 * `php artisan mohtam:email-dns` — reads SPF, DKIM, DMARC and MX for the sending domain from live DNS. Exits non-zero
 * until all three authentication records are published, so it can gate a deploy checklist.
 */
class EmailDnsCheck extends Command
{
    protected $signature = 'mohtam:email-dns {domain? : defaults to the domain of MAIL_FROM_ADDRESS} {--selector= : the DKIM selector}';

    protected $description = 'Check SPF, DKIM and DMARC for the sending domain against live DNS';

    public function handle(EmailDnsReadiness $readiness): int
    {
        $result = $readiness->check($this->argument('domain'), $this->option('selector') ?: null);

        $this->line('Domain: '.$result['domain']);

        foreach (['spf', 'dkim', 'dmarc', 'mx'] as $key) {
            $this->line(sprintf('  %-6s %-8s %s', strtoupper($key), $result[$key]['status'], $result[$key]['detail']));
        }

        $this->line($result['ready'] ? 'READY' : 'NOT READY — see docs/EMAIL_RUNBOOK.md');

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
