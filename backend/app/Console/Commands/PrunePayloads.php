<?php

namespace App\Console\Commands;

use App\Models\LeadSourceEvent;
use App\Models\WebhookLog;
use App\Models\WhatsappEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Forgets the envelopes, keeps the letters.
 *
 * Three tables store what a platform actually sent — names, phone numbers, the
 * text of a customer's WhatsApp message — so that somebody can answer "why did
 * this lead arrive wrong". That question is asked within days. Nothing pruned
 * them, so a workspace a year old was holding a year of customers' messages
 * against a question nobody was going to ask.
 *
 * The lead is never touched. What a customer told the business is the
 * business's record; this is only the envelope it came in.
 */
class PrunePayloads extends Command
{
    protected $signature = 'leadflow:prune-payloads
        {--days= : Override the configured retention}
        {--dry-run : Report what would go without removing it}';

    protected $description = 'Removes raw inbound payloads past their retention.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('retention.payload_days'));

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        /*
         * No tenant scope: this runs from the scheduler, where there is no
         * request to resolve a tenant from, and the scope fails closed — it
         * would silently prune nothing at all and report success.
         */
        $logs = WebhookLog::withoutGlobalScopes()->where('created_at', '<', $cutoff);

        /*
         * Safe to delete whole rows: this table is the idempotency record for
         * Meta's retries, and Meta gives up in hours. A row a month old is
         * protecting against a duplicate that cannot still arrive.
         */
        $events = WhatsappEvent::withoutGlobalScopes()->where('created_at', '<', $cutoff);

        /*
         * The row stays and only the payload goes. The arrivals chart and the
         * digest's "this source has gone quiet" both count these rows, so
         * deleting them would quietly rewrite a workspace's own history of
         * where its leads came from.
         */
        $sourceEvents = LeadSourceEvent::withoutGlobalScopes()
            ->where('received_at', '<', $cutoff)
            ->whereNotNull('payload');

        $counts = [
            'webhook_logs' => (clone $logs)->count(),
            'whatsapp_events' => (clone $events)->count(),
            'lead_source_event_payloads' => (clone $sourceEvents)->count(),
        ];

        if (! $dryRun) {
            $logs->delete();
            $events->delete();
            $sourceEvents->update(['payload' => null]);
        }

        foreach ($counts as $what => $count) {
            $this->line(sprintf('%s %s older than %d days: %d', $dryRun ? 'would prune' : 'pruned', $what, $days, $count));
        }

        return self::SUCCESS;
    }
}
