<?php

namespace App\Domain\Digest;

use App\Enums\LeadStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * What a manager needs to know before opening the product.
 *
 * Every figure in مهتم is already on a screen, which is exactly the problem:
 * somebody has to go and look. A form that stopped posting, eleven leads nobody
 * called, a follow-up two days overdue — none of them announce themselves, and
 * the cost of each is paid quietly while the dashboard sits in a closed tab.
 *
 * So this is deliberately short. A digest that lists everything is a digest
 * people stop reading, and the figures here are the ones somebody can act on
 * within the hour.
 */
class DailyDigest
{
    /**
     * Two kinds of number, on purpose.
     *
     * `new_leads`, `won` and `lost` are what happened yesterday. `uncontacted`,
     * `overdue_follow_ups` and `silent_sources` are where the workspace stands
     * right now. A morning digest is read as "what did I miss, and what is
     * waiting for me" — answering only the first half would describe a day
     * nobody can do anything about any more.
     *
     * @return array{
     *     date: string, new_leads: int, won: int, lost: int,
     *     uncontacted: int, overdue_follow_ups: int,
     *     silent_sources: array<int, array{label: string, silent_hours: int}>,
     * }
     */
    public function for(Tenant $tenant, ?Carbon $now = null): array
    {
        $now ??= now();
        $yesterday = $now->copy()->subDay()->startOfDay();
        $today = $now->copy()->startOfDay();

        $happened = Lead::query()
            ->whereBetween('leads.created_at', [$yesterday, $today])
            ->count();

        $closed = Lead::query()
            ->selectRaw('count(*) filter (where won_at >= ? and won_at < ?) as won', [$yesterday, $today])
            ->selectRaw('count(*) filter (where lost_at >= ? and lost_at < ?) as lost', [$yesterday, $today])
            ->first();

        /*
         * The same definition the dashboard uses, including its cutoff.
         *
         * Without the workspace's own first-contact target this counts leads
         * that arrived four minutes ago as neglected, which is both unfair and
         * the fastest way to teach somebody to ignore the number. A digest that
         * counts differently from the screen it points at is worse than no
         * digest — the reader cannot tell which figure to believe.
         */
        /*
         * `loadMissing`, not a bare `->settings`.
         *
         * Lazy loading is disabled outside production, and the command fetches
         * its tenants fresh — so reading the relation directly threw for every
         * workspace and the digest sent nothing at all. The tests did not catch
         * it because their tenant came from a fixture that had already loaded
         * the relation; only running the command did.
         */
        $tenant->loadMissing('settings');

        $targetMinutes = (int) ($tenant->settings?->first_contact_target_minutes ?? 30);

        $uncontacted = Lead::query()
            ->whereNull('first_contact_at')
            ->where('status', LeadStatus::New->value)
            ->where('leads.created_at', '<=', $now->copy()->subMinutes($targetMinutes))
            ->count();

        $overdue = FollowUp::query()->pending()->where('due_at', '<', $now)->count();

        return [
            'date' => $yesterday->toDateString(),
            'new_leads' => $happened,
            'won' => (int) ($closed->won ?? 0),
            'lost' => (int) ($closed->lost ?? 0),
            'uncontacted' => $uncontacted,
            'overdue_follow_ups' => $overdue,
            'silent_sources' => $this->silentSources($now),
        ];
    }

    /**
     * Sources that have delivered before, and delivered nothing since yesterday.
     *
     * This is the one figure here that nothing else in the product would ever
     * tell anybody. The arrivals chart shows it, but only to someone who opens
     * the integrations page and reads the "last heard from" column — and a form
     * that stopped posting is precisely the failure nobody goes looking for,
     * because the screen it breaks is the one that still looks fine.
     *
     * A source that never delivered is not silent, it is unconfigured, and it
     * is left out: an admin who connected LinkedIn and never ran an ad there
     * does not need telling about it every morning.
     *
     * @return array<int, array{label: string, silent_hours: int}>
     */
    private function silentSources(Carbon $now): array
    {
        $window = $now->copy()->subDays(30);

        return LeadSourceEvent::query()
            ->where('received_at', '>=', $window)
            ->selectRaw('coalesce(platform, provider) as label')
            ->selectRaw('max(received_at) as last_received_at')
            ->groupBy('label')
            ->having(fn ($q) => $q->havingRaw('max(received_at) < ?', [$now->copy()->subDay()]))
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'silent_hours' => (int) Carbon::parse($row->last_received_at)->diffInHours($now),
            ])
            ->sortByDesc('silent_hours')
            ->values()
            ->all();
    }

    /**
     * Is there anything worth sending?
     *
     * A digest that arrives every morning saying nothing happened is a digest
     * people learn to delete unread, and then the one that matters goes with
     * it. Silence here means a quiet workspace, not a broken scheduler — which
     * is why the command logs what it skipped.
     */
    public function worthSending(array $digest): bool
    {
        return $digest['new_leads'] > 0
            || $digest['won'] > 0
            || $digest['lost'] > 0
            || $digest['uncontacted'] > 0
            || $digest['overdue_follow_ups'] > 0
            || $digest['silent_sources'] !== [];
    }
}
