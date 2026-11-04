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
                strtok((string) $job->exception, ':') ?: 'unknown',
            ))
            ->each(fn (int $count, string $name) => $this->line(sprintf('failed job %dx %s', $count, mb_substr($name, 0, 160))));
    }
}
