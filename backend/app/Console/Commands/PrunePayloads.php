<?php

namespace App\Console\Commands;

use App\Models\DestinationDelivery;
use App\Models\LeadSourceEvent;
use App\Models\VisitorSession;
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

        /*
         * The tracking hub's two memories of a visitor. A delivery is the log of what was sent to a platform, asked about within
         * days ("why did it not get this?"), so it goes whole. A session is the trail that credited a lead, and the lead keeps its
         * credit; the session only loses what could point at a person — the address hash, the browser, the platform click ids —
         * and keeps which source it came from.
         */
        $deliveries = DestinationDelivery::withoutGlobalScopes()->where('created_at', '<', Carbon::now()->subDays((int) config('retention.delivery_days')));
        $sessionCutoff = Carbon::now()->subDays((int) config('retention.session_days'));
        $sessions = VisitorSession::withoutGlobalScopes()->where('last_seen_at', '<', $sessionCutoff)
            ->where(fn ($q) => $q->whereNotNull('ip_hash')->orWhereNotNull('browser')->orWhereNotNull('os')->orWhereRaw("(first_touch->'click_ids') is not null")->orWhereRaw("(last_touch->'click_ids') is not null"));

        $counts = [
            'destination_deliveries' => (clone $deliveries)->count(),
            'visitor_session_identifiers' => (clone $sessions)->count(),
            'webhook_logs' => (clone $logs)->count(),
            'whatsapp_events' => (clone $events)->count(),
            'lead_source_event_payloads' => (clone $sourceEvents)->count(),
        ];

        if (! $dryRun) {
            $logs->delete();
            $events->delete();
            $sourceEvents->update(['payload' => null]);
            $deliveries->delete();
            $sessions->update([
                'ip_hash' => null, 'browser' => null, 'os' => null,
                'first_touch' => \DB::raw("(coalesce(first_touch::jsonb, '{}'::jsonb) - 'click_ids')::json"),
                'last_touch' => \DB::raw("(coalesce(last_touch::jsonb, '{}'::jsonb) - 'click_ids')::json"),
            ]);
        }

        foreach ($counts as $what => $count) {
            $this->line(sprintf('%s %s older than %d days: %d', $dryRun ? 'would prune' : 'pruned', $what, $days, $count));
        }

        return self::SUCCESS;
    }
}
