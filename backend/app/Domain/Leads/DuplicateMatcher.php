<?php

namespace App\Domain\Leads;

use App\DataTransferObjects\IncomingLead;
use App\Models\Lead;
use App\Models\LeadSourceEvent;

/**
 * Decides whether an arriving lead is someone the workspace already knows.
 *
 * Ordered most to least certain, and it stops at the first hit. The order is
 * the whole design: matching on a weak signal before a strong one merges two
 * different people, and merging is far harder to undo than splitting — the
 * second rep has already called the first rep's customer.
 *
 *   1. The provider's own id for this submission. Exact, and the only signal
 *      that survives a person using a different phone number.
 *   2. Normalised phone. The strongest identifier this market has.
 *   3. WhatsApp number, which is often a second line.
 *   4. Email, lowercased — weakest, because families and companies share them,
 *      so it only matches when it is the *only* thing we have to go on.
 *
 * Deliberately no fuzzy name matching. "محمد العتيبي" is not a person, it is a
 * few thousand of them, and a near-miss merge silently loses a real customer.
 */
class DuplicateMatcher
{
    public function find(IncomingLead $incoming, ?string $normalizedPhone): ?Lead
    {
        return $this->byProviderLeadId($incoming)
            ?? $this->byPhone($normalizedPhone)
            ?? $this->byEmail($incoming, $normalizedPhone);
    }

    /**
     * The same submission arriving twice — a provider retrying a webhook it
     * thinks failed. This is idempotency, not deduplication, and it is the one
     * case where re-engagement would be wrong.
     */
    public function byProviderLeadId(IncomingLead $incoming): ?Lead
    {
        if (blank($incoming->providerLeadId)) {
            return null;
        }

        $event = LeadSourceEvent::query()
            ->where('provider_lead_id', $incoming->providerLeadId)
            ->where('provider', $incoming->provider->value)
            ->latest('id')
            ->first();

        return $event?->lead()->withTrashed()->lockForUpdate()->first();
    }

    private function byPhone(?string $normalizedPhone): ?Lead
    {
        if (blank($normalizedPhone)) {
            return null;
        }

        return Lead::withTrashed()
            ->where('phone_normalized', $normalizedPhone)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Only when there is no phone at all. An email shared by a household or a
     * company would otherwise merge two genuinely different enquiries, and the
     * second person's lead would vanish into the first one's timeline.
     */
    private function byEmail(IncomingLead $incoming, ?string $normalizedPhone): ?Lead
    {
        if (filled($normalizedPhone) || blank($incoming->email)) {
            return null;
        }

        return Lead::withTrashed()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($incoming->email))])
            ->lockForUpdate()
            ->first();
    }
}
