<?php

namespace App\Domain\Leads;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one place a lead's tags change, so a rep's chip, a rule's "add tag" and the API all agree on what a tag is.
 *
 * A tag is trimmed, its inner spaces collapsed, at most MAX_LENGTH characters, and compared without case: "VIP" and
 * "vip" are one tag, kept as first written. A lead carries at most MAX_PER_LEAD — a list longer than that is a second
 * pipeline nobody reads.
 */
class LeadTags
{
    public const MAX_PER_LEAD = 10;

    public const MAX_LENGTH = 32;

    public function __construct(private ActivityLogger $activities) {}

    public static function clean(mixed $tag): ?string
    {
        if (! is_scalar($tag)) {
            return null;
        }

        $tag = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $tag)));

        return $tag === '' ? null : mb_substr($tag, 0, self::MAX_LENGTH);
    }

    /**
     * @param  array<int,mixed>  $tags
     * @return array<int,string>
     */
    public static function normalise(array $tags): array
    {
        $kept = [];

        foreach ($tags as $tag) {
            $tag = self::clean($tag);

            if ($tag !== null && ! self::has($kept, $tag)) {
                $kept[] = $tag;
            }
        }

        return array_slice($kept, 0, self::MAX_PER_LEAD);
    }

    /** @param array<int,string> $tags */
    public static function has(array $tags, string $tag): bool
    {
        $needle = mb_strtolower($tag);

        foreach ($tags as $existing) {
            if (mb_strtolower((string) $existing) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    public static function of(Lead $lead): array
    {
        return array_values(array_filter((array) ($lead->tags ?? []), 'is_string'));
    }

    /**
     * Replaces the list and writes one line to the history saying what came and went.
     *
     * @param  array<int,mixed>  $tags
     * @return array{added: array<int,string>, removed: array<int,string>}
     */
    public function set(Lead $lead, array $tags, ?User $by, array $meta = []): array
    {
        $before = self::of($lead);
        $after = self::normalise($tags);

        $added = array_values(array_filter($after, fn ($t) => ! self::has($before, $t)));
        $removed = array_values(array_filter($before, fn ($t) => ! self::has($after, $t)));

        if ($added === [] && $removed === []) {
            return ['added' => [], 'removed' => []];
        }

        $lead->forceFill(['tags' => $after])->save();

        $this->activities->log($lead, ActivityType::Updated, $by, null,
            ['tags' => ['added' => $added, 'removed' => $removed]] + $meta);

        return ['added' => $added, 'removed' => $removed];
    }

    /** @return array{added: array<int,string>, removed: array<int,string>, full: bool} */
    public function add(Lead $lead, string $tag, ?User $by, array $meta = []): array
    {
        $current = self::of($lead);
        $clean = self::clean($tag);

        if ($clean === null || self::has($current, $clean)) {
            return ['added' => [], 'removed' => [], 'full' => false];
        }

        if (count($current) >= self::MAX_PER_LEAD) {
            return ['added' => [], 'removed' => [], 'full' => true];
        }

        return $this->set($lead, [...$current, $clean], $by, $meta) + ['full' => false];
    }

    /**
     * The tags in use across the visible leads, most used first — what the filter and the chip input suggest.
     *
     * @return array<int,array{tag:string,count:int}>
     */
    public static function inUse(Builder $leads, int $limit = 50): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            $counts = [];
            foreach ($leads->pluck('tags') as $tags) {
                foreach ((array) $tags as $tag) {
                    $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                }
            }
            arsort($counts);

            return collect($counts)->take($limit)->map(fn ($c, $t) => ['tag' => (string) $t, 'count' => $c])->values()->all();
        }

        $sub = $leads->toBase()->select(DB::raw('jsonb_array_elements_text(leads.tags) as tag'));

        return DB::query()->fromSub($sub, 't')
            ->select('tag', DB::raw('count(*) as count'))
            ->groupBy('tag')->orderByDesc('count')->orderBy('tag')->limit($limit)
            ->get()->map(fn ($r) => ['tag' => (string) $r->tag, 'count' => (int) $r->count])->all();
    }
}
