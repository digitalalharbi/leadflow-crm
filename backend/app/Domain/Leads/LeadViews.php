<?php

namespace App\Domain\Leads;

use App\Domain\Automation\Thresholds;
use Illuminate\Database\Eloquent\Builder;

/**
 * The lists a rep works from, each defined once.
 *
 * A count beside a chip and the rows behind it must be the same predicate, or
 * pressing a chip lands on a different number. So each view is one SQL fragment
 * and both the segment counts and the list filter read it from here.
 */
class LeadViews
{
    /** What an open lead is likely worth, in whole currency units. */
    private const VALUE = '(coalesce(leads.budget_max, (select projects.starting_price from projects where projects.id = leads.project_id), 0))';

    public const KEYS = ['needs_human', 'no_reply', 'high_value', 'new_today', 'qualified', 'stalled'];

    /**
     * @param  string  $timezone  The workspace's, because "today" is a local day.
     * @return array<string,string>
     */
    public static function sql(string $timezone = 'UTC'): array
    {
        $open = PipelineOverview::openSql();
        // Timestamps are stored as UTC wall-clock, so "now" has to be too — the
        // database's own session zone is not something to depend on.
        $now = "(now() at time zone 'UTC')";
        $today = now()->setTimezone($timezone)->startOfDay()->utc()->format('Y-m-d H:i:s');

        return [
            // Automation handed it over and a person has not picked it up.
            'needs_human' => "({$open} and leads.automation_state = 'handed_off' and (leads.last_contact_at is null or leads.last_contact_at <= leads.handed_off_at))",
            // We wrote, they did not answer, and a day has passed.
            'no_reply' => "({$open} and leads.first_contact_at is not null and leads.first_response_at is null and coalesce(leads.last_contact_at, leads.first_contact_at) < {$now} - interval '1 day')",
            'high_value' => sprintf('(%s and %s >= %s)', $open, self::VALUE, Thresholds::sql('high_value_from', Thresholds::workspace('high_value_from'))),
            'new_today' => "(leads.created_at >= '{$today}')",
            'qualified' => "({$open} and leads.qualification_status = 'qualified')",
            'stalled' => PipelineOverview::stalledSql(),
        ];
    }

    public static function apply(Builder $query, ?string $key, string $timezone = 'UTC'): void
    {
        $sql = self::sql($timezone);

        if ($key !== null && isset($sql[$key])) {
            $query->whereRaw($sql[$key]);
        }
    }
}
