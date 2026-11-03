<?php

namespace App\Domain\Leads;

use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Other records that may be the same person as this one.
 *
 * The matcher that runs on arrival is deliberately strict and stops at the
 * first hit; this runs afterwards, on request, and is allowed to be wider
 * because a human decides. It still never guesses from a name: the evidence is
 * the provider's own submission id, an email, or a phone number written
 * differently (+9665… and 05… are one line, and the index only knows one form).
 */
class DuplicateFinder
{
    /** @return Collection<int,array<string,mixed>> */
    public function for(Lead $lead, int $limit = 5, ?User $viewer = null): Collection
    {
        $found = [];

        // Asked for a person, only what that person may see: a match in another project is not theirs to read.
        $reachable = $viewer === null ? null : fn (int $id) => Lead::query()->visibleTo($viewer)->whereKey($id)->exists();

        $add = function (Lead $other, string $reason) use (&$found, $lead, $reachable) {
            if ($other->id === $lead->id || ($reachable !== null && ! $reachable($other->id))) {
                return;
            }
            $found[$other->id] ??= ['lead' => $other, 'reasons' => []];
            $found[$other->id]['reasons'][] = $reason;
        };

        $providerIds = LeadSourceEvent::withoutGlobalScopes()->where('lead_id', $lead->id)->whereNotNull('provider_lead_id')->pluck('provider_lead_id');

        if ($providerIds->isNotEmpty() || filled($lead->provider_lead_id)) {
            $others = LeadSourceEvent::withoutGlobalScopes()
                ->where('tenant_id', $lead->tenant_id)->where('lead_id', '!=', $lead->id)
                ->whereIn('provider_lead_id', $providerIds->push($lead->provider_lead_id)->filter()->unique()->all())
                ->pluck('lead_id')->unique();

            Lead::withoutGlobalScopes()->whereIn('id', $others)->where('tenant_id', $lead->tenant_id)->whereNull('deleted_at')->get()
                ->each(fn (Lead $other) => $add($other, 'provider_lead_id'));
        }

        if (filled($lead->email)) {
            Lead::withoutGlobalScopes()->where('tenant_id', $lead->tenant_id)->whereNull('deleted_at')->where('id', '!=', $lead->id)
                ->whereRaw('lower(email) = ?', [mb_strtolower(trim($lead->email))])->get()
                ->each(fn (Lead $other) => $add($other, 'email'));
        }

        $tail = $this->tail($lead->phone_normalized ?: $lead->phone);
        if ($tail !== null) {
            Lead::withoutGlobalScopes()->where('tenant_id', $lead->tenant_id)->whereNull('deleted_at')->where('id', '!=', $lead->id)
                ->whereRaw("right(regexp_replace(coalesce(phone_normalized, phone, ''), '[^0-9]', '', 'g'), 9) = ?", [$tail])->get()
                ->each(fn (Lead $other) => $add($other, 'phone'));
        }

        return collect($found)
            ->sortByDesc(fn (array $row) => count($row['reasons']))
            ->take($limit)
            ->map(fn (array $row) => [
                'id' => $row['lead']->uuid,
                'name' => $row['lead']->full_name,
                'phone' => $row['lead']->phone_normalized ?? $row['lead']->phone,
                'email' => $row['lead']->email,
                'status' => $row['lead']->status?->value,
                'platform' => $row['lead']->platform,
                'campaign' => $row['lead']->campaign_name,
                'created_at' => $row['lead']->created_at?->toIso8601String(),
                'reasons' => array_values(array_unique($row['reasons'])),
            ])
            ->values();
    }

    /** The last nine digits: what is left of a Saudi mobile once the country code and trunk zero are gone. */
    private function tail(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }
}
