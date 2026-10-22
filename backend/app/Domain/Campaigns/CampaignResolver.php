<?php

namespace App\Domain\Campaigns;

use App\Models\Ad;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\Lead;

/**
 * Finds the campaign a lead belongs to, or makes the row that says it did.
 *
 * The lead already carries the strings the platform sent — campaign id and
 * name, ad set, ad. This turns them into rows once, so everything downstream
 * can join on a key instead of grouping on text, and so a campaign that has only
 * ever been seen through its leads is still a campaign on the screen.
 *
 * It matches before it creates, and prefers the platform's id to the name: a
 * campaign renamed on the platform is the same campaign, and a name is only
 * ever a fallback for the many lead sources that never send an id. When a later
 * sync brings the id for a campaign first seen by name, the row is promoted
 * rather than duplicated.
 */
class CampaignResolver
{
    /** Links a lead to its campaign, ad set and ad. Returns whether anything was linked. */
    public function linkLead(Lead $lead, bool $force = false): bool
    {
        if ($lead->campaign_ref_id !== null && ! $force) {
            return false;
        }

        $platform = AdPlatform::fromLeadPlatform($lead->platform);
        $name = $this->clean($lead->campaign_name);
        $externalId = $this->clean($lead->campaign_id);

        if ($platform === null || ($name === null && $externalId === null)) {
            return false;
        }

        $campaign = $this->campaign($platform->value, $externalId, $name, $lead->project_id);
        $adSet = $this->adSet($campaign, $this->clean($lead->adset_id), $this->clean($lead->adset_name));
        $ad = $this->ad($campaign, $adSet, $this->clean($lead->ad_id), $this->clean($lead->ad_name));

        // A query-builder update: linking is bookkeeping, and must not run the
        // model's events (scoring, activity log) a second time for a lead that
        // has just been through them.
        Lead::query()->whereKey($lead->id)->update([
            'campaign_ref_id' => $campaign->id,
            'ad_set_ref_id' => $adSet?->id,
            'ad_ref_id' => $ad?->id,
        ]);

        $lead->forceFill([
            'campaign_ref_id' => $campaign->id,
            'ad_set_ref_id' => $adSet?->id,
            'ad_ref_id' => $ad?->id,
        ])->syncOriginal();

        return true;
    }

    public function campaign(string $platform, ?string $externalId, ?string $name, ?int $projectId = null): Campaign
    {
        $found = null;

        if ($externalId !== null) {
            $found = Campaign::query()->where('platform', $platform)->where('external_id', $externalId)->first();
        }

        if ($found === null && $name !== null) {
            $found = Campaign::query()->where('platform', $platform)->where('name', $name)
                ->where(fn ($q) => $externalId === null
                    ? $q
                    : $q->whereNull('external_id')->orWhere('external_id', $externalId))
                ->first();
        }

        if ($found === null) {
            return Campaign::create([
                'platform' => $platform,
                'external_id' => $externalId,
                'name' => $name ?? $externalId,
                'origin' => Campaign::ORIGIN_ATTRIBUTION,
                'project_id' => $projectId,
            ]);
        }

        $dirty = [];

        if ($found->external_id === null && $externalId !== null) {
            $dirty['external_id'] = $externalId;
        }

        if ($found->project_id === null && $projectId !== null) {
            $dirty['project_id'] = $projectId;
        }

        if ($dirty !== []) {
            $found->update($dirty);
        }

        return $found;
    }

    public function adSet(Campaign $campaign, ?string $externalId, ?string $name): ?AdSet
    {
        if ($externalId === null && $name === null) {
            return null;
        }

        $query = AdSet::query()->where('campaign_id', $campaign->id);
        $found = $externalId !== null ? (clone $query)->where('external_id', $externalId)->first() : null;
        $found ??= $name !== null ? (clone $query)->where('name', $name)->first() : null;

        if ($found === null) {
            return AdSet::create(['campaign_id' => $campaign->id, 'external_id' => $externalId, 'name' => $name ?? $externalId]);
        }

        if ($found->external_id === null && $externalId !== null) {
            $found->update(['external_id' => $externalId]);
        }

        return $found;
    }

    public function ad(Campaign $campaign, ?AdSet $adSet, ?string $externalId, ?string $name): ?Ad
    {
        if ($externalId === null && $name === null) {
            return null;
        }

        $query = Ad::query()->where('campaign_id', $campaign->id);
        $found = $externalId !== null ? (clone $query)->where('external_id', $externalId)->first() : null;
        $found ??= $name !== null ? (clone $query)->where('name', $name)->where('ad_set_id', $adSet?->id)->first() : null;

        if ($found === null) {
            return Ad::create([
                'campaign_id' => $campaign->id,
                'ad_set_id' => $adSet?->id,
                'external_id' => $externalId,
                'name' => $name ?? $externalId,
            ]);
        }

        if ($found->external_id === null && $externalId !== null) {
            $found->update(['external_id' => $externalId]);
        }

        return $found;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '—' ? null : $value;
    }
}
