<?php

namespace App\Domain\Reports;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\ReportShare;
use App\Models\User;
use App\Support\ProjectIds;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything a report was configured with, resolved once: which projects, which period (and the one it is compared with),
 * which attribution model, which currency, which platforms / campaigns / sources / salespeople, and what the viewer
 * may see. Every section asks the context for its leads, so a filter is applied in one place and cannot be forgotten by
 * one of them.
 *
 * It is built inside the report's workspace (the tenant context is bound before it is made), so every query it makes is
 * the workspace's own.
 */
final class ReportContext
{
    /** A lead's platform as the leads table spells it, and the ad platform it belongs to. */
    public const PLATFORM_ALIASES = ['meta' => ['meta', 'facebook', 'instagram'], 'google_ads' => ['google_ads'], 'tiktok' => ['tiktok'], 'snapchat' => ['snapchat'], 'linkedin' => ['linkedin']];

    /** @var list<int> */
    public array $projectIds;

    /** @var list<string> */
    public array $platforms;

    /** @var list<string> */
    public array $sources;

    /** @var list<int> */
    public array $campaignIds;

    /** @var list<int> */
    public array $salesUserIds;

    public string $model;

    public string $currency;

    /**
     * @param  list<string>  $sectionKeys  the sections that are on, in order
     */
    public function __construct(
        public ReportShare $share,
        public string $audience,
        public array $sectionKeys,
        public bool $money,
        public bool $earnings,
        string $defaultCurrency,
    ) {
        $filters = (array) $share->filters;
        $this->projectIds = $share->projectIds();
        $this->platforms = array_values(array_filter((array) ($filters['platforms'] ?? [])));
        $this->sources = array_values(array_filter((array) ($filters['sources'] ?? [])));
        $this->campaignIds = ! empty($filters['campaigns']) ? Campaign::query()->whereIn('uuid', (array) $filters['campaigns'])->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
        $this->salesUserIds = ! empty($filters['sales_users']) ? User::query()->whereIn('uuid', (array) $filters['sales_users'])->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
        $this->model = in_array($share->attribution_model, ReportShare::MODELS, true) ? $share->attribution_model : 'last';
        $this->currency = $share->currency ?: $defaultCurrency;
    }

    public function has(string $section): bool
    {
        return in_array($section, $this->sectionKeys, true);
    }

    public function partnerId(): ?int
    {
        return $this->share->partner_id ? (int) $this->share->partner_id : null;
    }

    public function compares(): bool
    {
        return (bool) $this->share->compare;
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, days: int, prev_from: CarbonImmutable, prev_to: CarbonImmutable} */
    public function window(?int $days = null): array
    {
        return ReportPeriod::window($this->share, $days);
    }

    /** Whether a filter beyond the project narrows the report — then the campaign-linked figures are narrowed with it. */
    public function filtered(): bool
    {
        return $this->platforms !== [] || $this->campaignIds !== [] || $this->sources !== [] || $this->salesUserIds !== [];
    }

    /** @return list<string> every spelling of the chosen platforms on the leads table */
    public function leadPlatforms(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (string $p) => self::PLATFORM_ALIASES[$p] ?? [$p], $this->platforms) ?: [[]])));
    }

    /** @return array{platforms?: list<string>, campaign_ids?: list<int>} what the campaign engine is narrowed to */
    public function campaignFilter(): array
    {
        return array_filter([
            'platforms' => $this->platforms,
            'campaign_ids' => $this->campaignIds,
        ]);
    }

    /**
     * Applies the report's project, partner and filters to a query on `leads`.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function narrow(Builder $query): Builder
    {
        ProjectIds::apply($query, 'leads.project_id', $this->projectIds);

        return $query
            ->when($this->partnerId(), fn ($q, $id) => $q->where('leads.partner_id', $id))
            ->when($this->platforms !== [], fn ($q) => $q->whereIn('leads.platform', $this->leadPlatforms()))
            ->when($this->sources !== [], fn ($q) => $q->whereIn('leads.source', $this->sources))
            ->when($this->campaignIds !== [], fn ($q) => $q->whereIn('leads.campaign_ref_id', $this->campaignIds))
            ->when($this->salesUserIds !== [], fn ($q) => $q->whereIn('leads.assigned_to', $this->salesUserIds));
    }

    /** @return Builder<Lead> every lead that arrived in the window (or the one before it), within the report */
    public function leads(array $window, bool $previous = false): Builder
    {
        [$from, $to] = $previous ? [$window['prev_from'], $window['prev_to']] : [$window['from'], $window['to']];

        return $this->narrow(Lead::query()->reorder()->whereBetween('leads.created_at', [$from, $to]));
    }
}
