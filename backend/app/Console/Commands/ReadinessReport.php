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

        rescue(fn () => DB::table('email_deliveries')->where('created_at', '>=', now()->subDays(7))
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day, provider, status, count(*) as total")->groupBy('day', 'provider', 'status')->orderBy('day')->get()
            ->each(fn ($row) => $this->line("evidence email {$row->day} {$row->provider} {$row->status}={$row->total}")), null, false);

        // Why, with any address taken out: a provider's error can quote the recipient.
        rescue(fn () => DB::table('email_deliveries')->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->pluck('last_error')
            ->countBy(fn ($error) => mb_substr((string) preg_replace('/[^\s<>"\']+@[^\s<>"\']+/', '[address]', (string) $error), 0, 90))
            ->each(fn (int $total, string $reason) => $this->line("evidence email_failure {$total}x {$reason}")), null, false);

        $this->failedRecipients();
        $this->acceptanceFacts();
    }

    /**
     * What kind of address each failed email went to, never the address: a rejection by a made-up domain is not a
     * provider fault, and one by a real customer's domain is.
     */
    private function failedRecipients(): void
    {
        $failed = rescue(fn () => DB::table('email_deliveries')->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->get(['to_email', 'kind', 'last_error', 'created_at']), collect(), false);
        $users = rescue(fn () => DB::table('users')->whereIn('email', $failed->pluck('to_email')->unique()->all())->pluck('email')->map(fn ($email) => strtolower($email))->flip(), collect(), false);
        $mx = [];

        $failed->groupBy(function ($row) use ($users, &$mx) {
            $domain = strtolower((string) substr(strrchr((string) $row->to_email, '@') ?: '', 1));
            $class = match (true) {
                $domain === '' => 'malformed',
                (bool) preg_match('/(^|\.)(example\.(com|org|net)|test|invalid|example|localhost|local)$/', $domain) => 'reserved_test_domain',
                ! ($mx[$domain] ??= (checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A'))) => 'domain_does_not_exist',
                default => 'real_domain',
            };

            return $class.' '.($users->has(strtolower((string) $row->to_email)) ? 'account_holder' : 'not_an_account').' kind='.$row->kind;
        })->each(fn ($rows, string $class) => $this->line(sprintf('evidence email_failed_recipient %dx %s distinct=%d', $rows->count(), $class, $rows->pluck('to_email')->unique()->count())));

        $auth = $failed->filter(fn ($row) => str_contains((string) $row->last_error, 'authenticate'));

        if ($auth->isNotEmpty()) {
            $last = (string) $auth->max('created_at');
            $after = rescue(fn () => DB::table('email_deliveries')->where('status', 'sent')->where('created_at', '>', $last)->count(), 'n/a', false);
            $this->line("evidence email_auth_failure count={$auth->count()} last=".substr($last, 0, 16)." sent_after={$after}");
        }
    }

    /** Facts an acceptance decision needs, as names and counts: never a value. */
    private function acceptanceFacts(): void
    {
        // A value holding a space is what the v0.9.0 deploy ran as a command. The name says which credential to rotate.
        $spaced = collect(getenv())->filter(fn ($value, $key) => preg_match('/PASSWORD|SECRET|KEY|TOKEN|DSN/', (string) $key) && preg_match('/\s/', trim((string) $value)))->keys()->sort()->implode(',');
        $this->line('evidence credentials_holding_a_space '.($spaced ?: 'none'));

        $this->line(sprintf(
            'evidence workspaces active=%s named_like_mohtam=%s marketing_workspace_set=%s',
            rescue(fn () => DB::table('tenants')->where('is_active', true)->whereNull('deleted_at')->count(), 'n/a', false),
            rescue(fn () => DB::table('tenants')->where('is_active', true)->whereNull('deleted_at')->where(fn ($q) => $q->where('slug', 'like', '%mohtam%')->orWhere('name', 'like', '%مهتم%'))->count(), 'n/a', false),
            filled(config('platform.marketing_workspace')) ? 'yes' : 'no',
        ));
    }
}
