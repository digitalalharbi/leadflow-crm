<?php

namespace App\Domain\Funnel;

use App\Domain\Reports\ReportContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What a funnel is asked about: a period, and optionally one project (or a portfolio), a platform, a campaign, a source,
 * and the people who worked the leads. The comparison period defaults to the one just before, the same length.
 */
final class FunnelFilters
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public CarbonImmutable $compareFrom;

    public CarbonImmutable $compareTo;

    /**
     * @param  list<int>  $projectIds  empty = every project the viewer may see
     * @param  list<int>  $assignedTo  user ids; empty = the whole sales team
     */
    public function __construct(
        CarbonInterface $from,
        CarbonInterface $to,
        public array $projectIds = [],
        public ?string $platform = null,
        public ?int $campaignId = null,
        public ?string $source = null,
        public array $assignedTo = [],
        ?CarbonInterface $compareFrom = null,
        ?CarbonInterface $compareTo = null,
        public array $platforms = [],
        public array $campaignIds = [],
        public array $sources = [],
        public ?int $partnerId = null,
    ) {
        // The single filters and the lists are one: a list is what the queries read.
        $this->platforms = array_values(array_unique(array_filter([...$this->platforms, $platform])));
        $this->campaignIds = array_values(array_unique(array_filter([...$this->campaignIds, $campaignId])));
        $this->sources = array_values(array_unique(array_filter([...$this->sources, $source])));

        $this->from = CarbonImmutable::instance($from)->startOfDay();
        $this->to = CarbonImmutable::instance($to)->endOfDay();

        $days = max(1, (int) $this->from->diffInDays($this->to->startOfDay()) + 1);
        $this->compareTo = $compareTo ? CarbonImmutable::instance($compareTo)->endOfDay() : $this->from->subDay()->endOfDay();
        $this->compareFrom = $compareFrom ? CarbonImmutable::instance($compareFrom)->startOfDay() : $this->compareTo->subDays($days - 1)->startOfDay();
    }

    /** @return list<string> the platforms as the leads table spells them (Meta is also facebook and instagram there) */
    public function leadPlatforms(): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (string $p) => ReportContext::PLATFORM_ALIASES[$p] ?? [$p], $this->platforms))));
    }

    public function days(): int
    {
        return max(1, (int) $this->from->diffInDays($this->to->startOfDay()) + 1);
    }

    public function previous(): self
    {
        return new self($this->compareFrom, $this->compareTo, $this->projectIds, null, null, null, $this->assignedTo, platforms: $this->platforms, campaignIds: $this->campaignIds, sources: $this->sources, partnerId: $this->partnerId);
    }

    /** A stable string for cache keys: everything that changes the answer, nothing that does not. */
    public function fingerprint(): string
    {
        $ids = $this->projectIds;
        sort($ids);
        $reps = $this->assignedTo;
        sort($reps);

        return hash('sha256', json_encode([
            $this->from->toDateString(), $this->to->toDateString(), $this->compareFrom->toDateString(), $this->compareTo->toDateString(),
            $ids, $this->platforms, $this->campaignIds, $this->sources, $reps, $this->partnerId,
        ]));
    }
}
