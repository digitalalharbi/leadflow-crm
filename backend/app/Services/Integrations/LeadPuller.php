<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\LeadSourceEvent;
use App\Models\Tenant;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Support\Str;
use Throwable;

/**
 * Going and asking a source what it has.
 *
 * Five providers implement `fetch()` — Meta, TikTok, Snapchat, LinkedIn and
 * Google Sheets — and until this existed nothing in the product ever called
 * any of them. A workspace could paste a valid TikTok access token, watch the
 * integration go green, and wait forever: there was no command, no job and no
 * schedule on the other side of that token. The push providers worked because
 * the source does the calling. The pull providers did not work at all, and
 * nothing said so.
 */
class LeadPuller
{
    public function __construct(
        private ProviderRegistry $registry,
        private LeadIngestionService $ingestion,
    ) {}

    /**
     * @return array{fetched:int, created:int, duplicates:int, skipped:int, rejected:int, failed:int, error:?string}
     */
    public function pull(Tenant $tenant, Integration $integration): array
    {
        $result = [
            'fetched' => 0, 'created' => 0, 'duplicates' => 0,
            'skipped' => 0, 'rejected' => 0, 'failed' => 0, 'error' => null,
        ];

        // WhatsApp shares this table and is not a lead source, so ask before
        // reaching for a provider class that does not exist.
        if (! $this->registry->has($integration->provider)) {
            return $result;
        }

        $provider = $this->registry->get($integration->provider);

        if (! $provider->pullsLeads() || ! $provider->isConfigured($integration)) {
            return $result;
        }

        try {
            $rows = $provider->fetch($integration);
        } catch (Throwable $e) {
            /*
             * `last_synced_at` is deliberately not touched on failure.
             *
             * It means "the last time leads actually came through". A source
             * that has been refusing our token for a week would otherwise read
             * as synced a minute ago, which is the same lie the arrivals chart
             * was telling before its timestamps carried a timezone.
             */
            $integration->forceFill(['last_error' => Str::limit($e->getMessage(), 500)])->save();
            report($e);

            return [...$result, 'error' => $e->getMessage()];
        }

        $result['fetched'] = count($rows);

        foreach ($rows as $row) {
            try {
                $incoming = $provider->toIncomingLead((array) $row, $integration);

                /*
                 * A row we have already taken is skipped outright — not
                 * ingested and recorded as a duplicate.
                 *
                 * A poll every quarter hour re-reads the same window, so
                 * without this every lead would log a fresh duplicate event on
                 * every run. The duplicate rate on the arrivals chart exists to
                 * expose a source posting the same payload twice; it would
                 * instead have been dominated by our own polling.
                 */
                if ($this->alreadyTaken($tenant, $integration, $incoming->providerLeadId)) {
                    $result['skipped']++;

                    continue;
                }

                $outcome = $this->ingestion->ingest($tenant, $incoming);

                if (! $outcome->wasAccepted()) {
                    $result['rejected']++;
                } elseif ($outcome->isDuplicate()) {
                    $result['duplicates']++;
                } else {
                    $result['created']++;
                }
            } catch (Throwable $e) {
                // One malformed row must not cost the rest of the batch.
                $result['failed']++;
                report($e);
            }
        }

        $integration->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

        return $result;
    }

    /**
     * Have we seen this exact row from this source before?
     *
     * Matched on the id the provider gave it, which is the only identifier
     * that survives the lead being edited, merged or re-engaged on our side.
     */
    private function alreadyTaken(Tenant $tenant, Integration $integration, ?string $providerLeadId): bool
    {
        if (blank($providerLeadId)) {
            // Nothing to match on. The ingestion service still dedupes by phone,
            // so the worst case is a duplicate event rather than a second lead.
            return false;
        }

        return LeadSourceEvent::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $integration->provider->value)
            ->where('provider_lead_id', $providerLeadId)
            ->exists();
    }
}
