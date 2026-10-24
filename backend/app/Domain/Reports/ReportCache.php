<?php

namespace App\Domain\Reports;

use App\Models\ReportShare;
use Illuminate\Support\Facades\Cache;

/**
 * A short memory for a report's numbers, so a link that is opened a hundred times a minute does not rebuild them a hundred
 * times — and never a memory that crosses a company, a report, an audience or a configuration.
 *
 * The key carries the workspace, the report and its revision, who is looking (a client and the team see different
 * things), the sections that are on, the projects and filters, and the period's dates; change any of them and it is a
 * different entry. The payload says it came from here (`cached`, and how old it is), so a number from a minute ago is
 * never passed off as this second's.
 */
class ReportCache
{
    /**
     * @param  callable():array<string,mixed>  $build
     * @return array<string,mixed>
     */
    public function remember(ReportShare $share, string $audience, array $sectionKeys, int $seconds, callable $build): array
    {
        if ($seconds <= 0) {
            return $build();
        }

        $key = $this->key($share, $audience, $sectionKeys);
        $held = Cache::get($key);

        if (is_array($held) && isset($held['report'])) {
            $held['report']['cached'] = true;
            $held['report']['cache_age_seconds'] = max(0, now()->diffInSeconds($held['report']['generated_at'] ?? now(), true));

            return $held;
        }

        $fresh = $build();
        $fresh['report']['cached'] = false;
        $fresh['report']['cache_age_seconds'] = 0;
        Cache::put($key, $fresh, $seconds);

        return $fresh;
    }

    public function key(ReportShare $share, string $audience, array $sectionKeys): string
    {
        $window = ReportPeriod::window($share);

        return 'report:v1:t'.$share->tenant_id.':r'.$share->id.':v'.$share->revision.':'.$audience.':'.hash('sha256', json_encode([
            $sectionKeys, $share->projectIds(), $share->filters, $share->attribution_model, $share->currency, $share->partner_id, (bool) $share->compare,
            $window['from']->toDateString(), $window['to']->toDateString(), $window['prev_from']->toDateString(), $window['prev_to']->toDateString(),
            $share->options, $share->branding, $share->updated_at?->timestamp,
        ]));
    }

    /** Forgets nothing by name — a changed report has a new revision and so a new key; this is for tests and tools. */
    public function forget(ReportShare $share, string $audience, array $sectionKeys): void
    {
        Cache::forget($this->key($share, $audience, $sectionKeys));
    }
}
