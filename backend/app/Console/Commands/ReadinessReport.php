<?php

namespace App\Console\Commands;

use App\Domain\Readiness\ProductionReadiness;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The platform's readiness, from a shell.
 *
 * For the moment between "the environment file has been filled in" and "somebody
 * has opened a browser": it answers from the server itself. It cannot say the
 * domain is live — that needs a request to have arrived on it — and it does not
 * pretend to: the row reads `ready` until the screen has been opened over HTTPS.
 *
 * Exits non-zero while anything platform-level is still external, so a deploy
 * script can gate on it without parsing.
 */
class ReadinessReport extends Command
{
    protected $signature = 'leadflow:readiness {--json : Print the report as JSON}';

    protected $description = 'Show what the platform is still waiting on before it is live.';

    public function handle(ProductionReadiness $readiness): int
    {
        // A request for nothing in particular. Deliberately not built from the
        // configured URL: a request we made up on the right host would make the
        // domain row claim that something had arrived there.
        $report = $readiness->report(Request::create('/'), false);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($report['items'] as $item) {
                $this->line(sprintf(
                    '%-9s %-32s %s',
                    $item['key'],
                    $item['status'],
                    $item['blocker'] ?? '',
                ));
            }
        }

        if (! $this->option('json')) {
            $this->failedJobs();
            $this->evidence();
        }

        $platform = array_filter(
            $report['items'],
            fn (array $item) => in_array($item['key'], ['vps', 'domain', 'google'], true),
        );

        $waiting = array_filter(
            $platform,
            fn (array $item) => $item['status'] === ProductionReadiness::EXTERNAL,
        );

        return $waiting === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Which jobs failed in the last day: class names and counts only. A payload or a message can hold a customer's data. */
    private function failedJobs(): void
    {
        DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->get(['payload', 'exception'])
            ->countBy(fn ($job) => sprintf(
                '%s / %s',
                json_decode((string) $job->payload, true)['displayName'] ?? 'unknown',
                preg_match('/No query results for model \[([^\]]+)\]/', (string) $job->exception, $model) ? $model[1].' not found' : (strtok((string) $job->exception, ':') ?: 'unknown'),
            ))
            ->each(fn (int $count, string $name) => $this->line(sprintf('failed job %dx %s', $count, mb_substr($name, 0, 160))));
    }

    /** What has really happened on this server, across every workspace: counts only, naming nobody. */
    private function evidence(): void
    {
        $count = fn (string $table, callable $query) => rescue(fn () => $query(DB::table($table))->count(), 'n/a', false);
        $emails = rescue(fn () => DB::table('email_deliveries')->where('created_at', '>=', now()->subDays(7))->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(), [], false);

        $this->line(sprintf(
            'evidence sheets sources=%s runs_completed=%s runs_failed=%s runs_with_rows=%s leads=%s',
            $count('sheet_sources', fn ($q) => $q),
            $count('sheet_sync_runs', fn ($q) => $q->where('status', 'completed')),
            $count('sheet_sync_runs', fn ($q) => $q->where('status', 'failed')),
            $count('sheet_sync_runs', fn ($q) => $q->where('status', 'completed')->where('rows_seen', '>', 0)),
            $count('leads', fn ($q) => $q->where('provider', 'google_sheets')->whereNull('deleted_at')),
        ));
        $this->line('evidence email_7d '.(collect($emails)->map(fn ($total, $status) => "{$status}={$total}")->implode(' ') ?: 'none'));
    }
}
