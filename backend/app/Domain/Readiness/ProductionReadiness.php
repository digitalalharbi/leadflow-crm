<?php

namespace App\Domain\Readiness;

use App\Console\Commands\Heartbeat;
use App\Domain\Integrations\IntegrationHealthReport;
use App\Domain\Integrations\IntegrationState;
use App\Domain\Mail\EmailDnsReadiness;
use App\Domain\Mail\MailerRegistry;
use App\Domain\Sheets\GoogleOAuth;
use App\Jobs\QueueHeartbeat;
use App\Models\AgentMessage;
use App\Models\CommissioningRun;
use App\Models\SheetSyncRun;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Email\EmailManager;
use App\Services\Whatsapp\WhatsappManager;
use App\Support\Deployment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What stands between this deployment and being live, said once.
 *
 * Three words, and each is a claim with a different burden of proof:
 *
 *   external_verification_required — something outside the application is
 *       missing or unproven: a server, a domain, a credential, an approval.
 *       Nothing here can fix it, and the row names what it is waiting for.
 *   ready — everything the application needs has been supplied, so
 *       commissioning can run. It is not a claim that anything works.
 *   live_verified — a real connection or event has been observed. Not a
 *       credential, not a successful test, not a green badge: something the
 *       other end did.
 *
 * The distinction between the last two is the whole feature. A screen that
 * turned green when a token was typed would be right every time it was wrong
 * only on the day it mattered.
 */
class ProductionReadiness
{
    public const READY = 'ready';

    public const EXTERNAL = 'external_verification_required';

    public const LIVE = 'live_verified';

    /** A pulse older than this is no pulse; the scheduler runs every minute. */
    private const PULSE_SECONDS = 180;

    public function __construct(
        private readonly IntegrationHealthReport $health,
        private readonly GoogleOAuth $google,
        private readonly WhatsappManager $whatsapp,
        private readonly InfrastructureReadiness $infrastructure,
    ) {}

    /**
     * @param  bool  $workspace  false for the platform console, which has no
     *                           workspace and so cannot say anything about one
     * @return array{scope:string, items:array<int,array<string,mixed>>, summary:array<string,int>}
     */
    public function report(Request $request, bool $workspace): array
    {
        $rows = $workspace ? collect($this->health->all())->keyBy('key') : collect();
        $last = $workspace ? $this->lastCommissioning() : [];

        $items = [
            $this->vps(),
            $this->domain($request),
            $this->googleOAuth($rows, $workspace, $last),
            $this->mailProvider(),
            $this->googleLogin(),
            $this->automationEngine(),
            $workspace ? $this->whatsapp($rows, $last) : $this->perWorkspace('whatsapp'),
            $workspace ? $this->twilio($rows, $last) : $this->perWorkspace('twilio'),
            $workspace ? $this->ads($rows, $last) : $this->perWorkspace('ads'),
            $workspace ? $this->email($rows, $last) : $this->perWorkspace('email'),
            $workspace ? $this->claude() : $this->perWorkspace('claude'),
            $workspace ? $this->calendar() : $this->perWorkspace('calendar'),
        ];

        $items = $this->withLadder($items, $rows, $last, $workspace);

        $summary = array_count_values(array_column($items, 'status'));

        // Infrastructure rows already carry the older `state`; the ladder goes
        // under its own name so neither can overwrite the other.
        $infrastructure = array_map(function (array $row) {
            $result = app(ReadinessLadder::class)->infrastructure($row);

            return $row + ['ladder' => $result['state'], 'evidence' => $result['evidence']];
        }, $this->infrastructure->all());

        return [
            'scope' => $workspace ? 'workspace' : 'platform',
            // What this process is: Local, Staging or Production — from what is
            // observable, so the screen can say it before it says anything else.
            'deployment' => Deployment::describe(),
            'items' => $items,
            /*
             * What the platform stands on. Apart from `items` on purpose: those
             * are things a customer connects, these are things the deployment
             * either has or does not, and the two have different people to ask.
             */
            'infrastructure' => $infrastructure,
            'infrastructure_summary' => array_count_values(array_column($infrastructure, 'state')),
            // The six rungs, counted across everything on the screen.
            'ladder' => $this->ladderCounts(array_merge($items, $infrastructure)),
            'summary' => [
                self::LIVE => $summary[self::LIVE] ?? 0,
                self::READY => $summary[self::READY] ?? 0,
                self::EXTERNAL => $summary[self::EXTERNAL] ?? 0,
            ],
        ];
    }

    /**
     * Each row on the six-rung ladder, with its evidence.
     *
     * @param  array<int,array<string,mixed>>  $items
     */
    private function withLadder(array $items, $rows, array $last, bool $workspace): array
    {
        $ladder = app(ReadinessLadder::class);
        $health = fn (string $key) => $workspace ? $rows->get($key) : null;

        return array_map(function (array $item) use ($ladder, $health, $last, $workspace) {
            $key = $item['key'];

            $result = match ($key) {
                'vps', 'domain', 'mail', 'google_login', 'automation' => $ladder->server($item['status'], $item['checks']),
                'google' => $ladder->integration($health('google_sheets'), $last['google'] ?? null),
                'whatsapp' => $ladder->integration($health('whatsapp'), $last['whatsapp'] ?? null),
                'twilio' => $ladder->integration($health('calls'), $last['twilio'] ?? null),
                'email' => $ladder->integration($health('email'), $last['email'] ?? null),
                'ads' => $ladder->best(
                    array_values(array_filter(array_map($health, ['meta', 'tiktok', 'google_ads', 'snapchat', 'linkedin']))),
                    $last['ads'] ?? null,
                ),
                // Built in, so "configured" from the start; the same honesty as the agents' row for whether it is live.
                'calendar' => $workspace
                    ? $ladder->claude(true, User::query()->max('calendar_feed_fetched_at') ? Carbon::parse(User::query()->max('calendar_feed_fetched_at'))->toIso8601String() : null)
                    : ['state' => 'not_configured', 'evidence' => []],
                'claude' => $workspace
                    ? $ladder->claude(
                        filled(config('services.anthropic.key')) || filled(TenantSetting::query()->value('ai_api_key')),
                        AgentMessage::query()->where('data->mode', 'ai')->latest('id')->first()?->created_at?->toIso8601String(),
                    )
                    : ['state' => 'not_configured', 'evidence' => []],
                default => ['state' => 'not_configured', 'evidence' => []],
            };

            /*
             * The older three-word status must not say more than the ladder:
             * "live" off a production deployment is "ready, and waiting to be
             * seen live in production".
             */
            if ($item['status'] === self::LIVE && ! in_array($result['state'], [IntegrationState::LIVE_VERIFIED, IntegrationState::PRODUCTION_VERIFIED], true)) {
                $item['status'] = self::READY;
                $item['blocker'] = 'not_recorded_in_production';
            }

            return $item + $result;
        }, $items);
    }

    /** @param  array<int,array<string,mixed>>  $rows */
    private function ladderCounts(array $rows): array
    {
        $counts = array_fill_keys(IntegrationState::LADDER, 0);

        foreach ($rows as $row) {
            $state = $row['ladder'] ?? $row['state'] ?? '';

            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }

        return $counts;
    }

    // ----------------------------------------------------------------- rows

    private function vps(): array
    {
        $production = app()->environment('production');
        $debugOff = ! config('app.debug');
        $key = filled(config('app.key'));

        $scheduler = $this->fresh(Cache::get(Heartbeat::KEY));
        $queue = $this->fresh(Cache::get(QueueHeartbeat::KEY));

        $configured = $production && $debugOff && $key;

        return $this->item('vps', match (true) {
            $configured && $scheduler && $queue => self::LIVE,
            $configured => self::READY,
            default => self::EXTERNAL,
        }, match (true) {
            ! $production => 'not_production',
            ! $debugOff => 'debug_enabled',
            ! $key => 'no_app_key',
            ! $scheduler => 'awaiting_scheduler',
            ! $queue => 'awaiting_queue_worker',
            default => null,
        }, [
            'production_environment' => $production,
            'debug_off' => $debugOff,
            'app_key' => $key,
            'scheduler_running' => $scheduler,
            'queue_worker_running' => $queue,
        ], commissionable: false);
    }

    private function domain(Request $request): array
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $https = str_starts_with((string) config('app.url'), 'https://');

        /*
         * A public name, not merely a configured one. `localhost`, an IP, a
         * `.test` and the documentation domains all parse and all fail to be
         * reachable by a customer, which is the one property a domain is for.
         */
        $public = Deployment::publicHost($host);

        /*
         * This very request, as the evidence. If the person reading the screen
         * got here over HTTPS at the configured name, then DNS, the certificate
         * and the proxy all work — which no amount of reading the config can
         * say. Behind a proxy `isSecure()` is only as true as TRUSTED_PROXIES,
         * so a misconfigured proxy shows up here as "ready, not verified"
         * rather than as a false pass.
         */
        $here = $public && strcasecmp($request->getHost(), $host) === 0 && $request->isSecure();

        return $this->item('domain', match (true) {
            $public && $https && $here => self::LIVE,
            $public && $https => self::READY,
            default => self::EXTERNAL,
        }, match (true) {
            ! $public => 'not_a_public_domain',
            ! $https => 'not_https',
            ! $here => 'awaiting_request_on_domain',
            default => null,
        }, [
            'public_domain' => $public,
            'https' => $https,
            'request_arrived_securely_on_it' => $here,
        ], commissionable: false);
    }

    private function googleOAuth($rows, bool $workspace, array $last): array
    {
        $client = $this->google->configured();
        $picker = $this->google->pickerConfigured();

        $evidence = $workspace
            ? ($rows->get('google_sheets')['verification'] ?? null) === IntegrationHealthReport::EXTERNALLY_VERIFIED
            : SheetSyncRun::withoutGlobalScopes()
                ->where('status', SheetSyncRun::COMPLETED)
                ->where('rows_seen', '>', 0)
                ->exists();

        $connected = $workspace && ($rows->get('google_sheets')['connected'] ?? false);

        $status = match (true) {
            $evidence => self::LIVE,
            $client && $picker => self::READY,
            default => self::EXTERNAL,
        };

        return $this->item('google', $this->afterCommissioning($status, $last['google'] ?? null), match (true) {
            $evidence => null,
            ! $client => 'platform_not_configured',
            ! $picker => 'picker_not_configured',
            $workspace && ! $connected => 'awaiting_consent',
            default => 'awaiting_first_event',
        }, [
            'oauth_client' => $client,
            'picker_key_and_project' => $picker,
            'account_connected' => $connected,
        ], commissionable: $workspace, last: $last['google'] ?? null);
    }

    private function whatsapp($rows, array $last): array
    {
        $row = $rows->get('whatsapp') ?? [];
        $verified = ($row['verification'] ?? null) === IntegrationHealthReport::EXTERNALLY_VERIFIED;

        // The manager's own definition: a token *and* a number id. The health
        // row's `connected` is only the first, and a half-filled form is not
        // a workspace that can send.
        $live = $this->whatsapp->isLive($this->whatsapp->integration());

        $status = match (true) {
            $verified => self::LIVE,
            $live => self::READY,
            default => self::EXTERNAL,
        };

        return $this->item('whatsapp', $this->afterCommissioning($status, $last['whatsapp'] ?? null), $verified
            ? null
            : ($live ? 'awaiting_first_event' : 'credentials_missing'), [
                'credentials_present' => $live,
                'receipt_or_inbound_seen' => $verified,
            ], commissionable: true, last: $last['whatsapp'] ?? null);
    }

    private function twilio($rows, array $last): array
    {
        $row = $rows->get('calls') ?? [];
        $verified = ($row['verification'] ?? null) === IntegrationHealthReport::EXTERNALLY_VERIFIED;
        $configured = (bool) ($row['configured'] ?? false);

        $status = match (true) {
            $verified => self::LIVE,
            $configured => self::READY,
            default => self::EXTERNAL,
        };

        return $this->item('twilio', $this->afterCommissioning($status, $last['twilio'] ?? null), $verified
            ? null
            : ($configured ? 'awaiting_first_event' : 'credentials_missing'), [
                'credentials_present' => $configured,
                'measured_call_seen' => $verified,
            ], commissionable: true, last: $last['twilio'] ?? null);
    }

    /**
     * The ad platforms, as one row with the five underneath it.
     *
     * One row because they are one question — "are leads coming in from ads" —
     * and the answer is yes when any of them is. The five stay visible, because
     * the second question is always which.
     */
    private function ads($rows, array $last): array
    {
        $children = [];

        foreach (['meta', 'tiktok', 'google_ads', 'snapchat', 'linkedin'] as $key) {
            $row = $rows->get($key) ?? [];
            $verified = ($row['verification'] ?? null) === IntegrationHealthReport::EXTERNALLY_VERIFIED;
            $configured = (bool) ($row['configured'] ?? false);

            $children[] = [
                'key' => $key,
                'status' => match (true) {
                    $verified => self::LIVE,
                    $configured => self::READY,
                    default => self::EXTERNAL,
                },
            ];
        }

        $states = array_column($children, 'status');

        $status = match (true) {
            in_array(self::LIVE, $states, true) => self::LIVE,
            in_array(self::READY, $states, true) => self::READY,
            default => self::EXTERNAL,
        };

        $item = $this->item('ads', $this->afterCommissioning($status, $last['ads'] ?? null), match ($status) {
            self::LIVE => null,
            self::READY => 'awaiting_first_event',
            default => 'credentials_missing',
        }, [], commissionable: true, last: $last['ads'] ?? null);

        $item['children'] = $children;

        return $item;
    }

    /**
     * Email: a real mail server configured is "ready"; an email a customer
     * actually opened or clicked — a pixel or a link only a real inbox fetches
     * — is the proof it reaches people. The log driver proves nothing.
     */
    private function email($rows, array $last = []): array
    {
        $manager = app(EmailManager::class);
        $configured = $manager->provider() !== 'mock';
        // The same proof the Integrations screen uses: a customer's own mail
        // client fetched a message we sent over a real transport. One source,
        // so the two screens cannot disagree about whether email is working.
        $proven = ($rows->get('email')['verification'] ?? null) === IntegrationHealthReport::EXTERNALLY_VERIFIED;
        $replies = filled(config('services.email_inbound.domain')) && filled(config('services.email_inbound.secret'));

        $status = match (true) {
            $configured && $proven => self::LIVE,
            $configured => self::READY,
            default => self::EXTERNAL,
        };

        return $this->item('email', $status, match ($status) {
            self::LIVE => $replies ? null : 'replies_not_routed',
            self::READY => 'awaiting_first_event',
            default => 'credentials_missing',
        }, [
            'mail_server' => $configured,
            'opened_by_a_customer' => $proven,
            'replies_routed' => $replies,
        ], commissionable: true, last: $last['email'] ?? null);
    }

    /**
     * Claude for the agents: a key is "ready"; an answer the model actually
     * gave is the proof. Without a key the agents work from their rules.
     */
    private function claude(): array
    {
        $key = filled(config('services.anthropic.key')) || filled(TenantSetting::query()->value('ai_api_key'));
        $answered = AgentMessage::query()->where('data->mode', 'ai')->exists();

        $status = match (true) {
            $key && $answered => self::LIVE,
            $key => self::READY,
            default => self::EXTERNAL,
        };

        return $this->item('claude', $status, match ($status) {
            self::LIVE => null,
            self::READY => 'awaiting_first_event',
            default => 'credentials_missing',
        }, [
            'api_key' => $key,
            'model_answered' => $answered,
        ], commissionable: false);
    }

    /**
     * Visits in people's calendars. The private feed needs nothing from anybody and is live once a calendar app has
     * read one; two-way Google Calendar sync needs the Calendar scope approved on the Google project, which is the
     * owner's to do.
     */
    private function calendar(): array
    {
        $feeds = User::query()->whereNotNull('calendar_token_hash')->count();
        $read = User::query()->whereNotNull('calendar_feed_fetched_at')->exists();

        $live = $read && Deployment::isProduction();

        return $this->item('calendar', $live ? self::LIVE : self::READY, $live ? null : ($read ? 'not_recorded_in_production' : 'awaiting_first_event'), [
            'ics_feed' => true,
            'feed_created' => $feeds > 0,
            'feed_read_by_a_calendar' => $read,
            'google_calendar_sync' => false,
        ], commissionable: false);
    }

    /** A row for something that is configured by each workspace, not by the platform. */
    private function perWorkspace(string $key): array
    {
        return $this->item($key, self::EXTERNAL, 'configured_per_workspace', [], commissionable: false);
    }

    // -------------------------------------------------------------- helpers

    /**
     * A failed commissioning takes precedence over "credentials present".
     *
     * Having a token and a token Meta rejected are different states, and the
     * screen saying *ready* for the second is the kind of green that costs a
     * day. It does not override LIVE: something real has already happened, and
     * a later failed test is a thing to look at, not a reason to un-prove it.
     */
    private function afterCommissioning(string $status, ?array $last): string
    {
        if ($status === self::READY && $last !== null && $last['ok'] === false) {
            return self::EXTERNAL;
        }

        return $status;
    }

    /** Whether the product's own email really leaves the building. The provider's name, never its key. */
    private function mailProvider(): array
    {
        $delivers = app(MailerRegistry::class)->delivers();
        $configured = $delivers && app(MailerRegistry::class)->current()->isConfigured();
        $dns = rescue(fn () => app(EmailDnsReadiness::class)->check(), null, false);
        $dnsOk = is_array($dns) && ($dns['ready'] ?? false) === true;

        return $this->item('mail', $configured && $dnsOk ? self::READY : self::EXTERNAL, match (true) {
            ! $delivers => 'no_mail_provider',
            ! $configured => 'mail_provider_not_configured',
            ! $dnsOk => 'mail_dns_incomplete',
            default => null,
        }, ['provider' => $delivers, 'credentials' => $configured, 'dns' => $dnsOk], false);
    }

    private function googleLogin(): array
    {
        $on = filled(config('services.google_login.client_id')) && filled(config('services.google_login.client_secret'));

        return $this->item('google_login', $on ? self::READY : self::EXTERNAL, $on ? null : 'awaiting_google_login_credentials', ['credentials' => $on], false);
    }

    /** Whether rules can run at all: a worker's queue, and the sweeps' last pulse. */
    private function automationEngine(): array
    {
        $failed = rescue(fn () => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(), 0, false);

        return $this->item('automation', $failed === 0 ? self::READY : self::EXTERNAL, $failed === 0 ? null : 'failed_jobs_in_the_last_day', ['no_recent_failed_jobs' => $failed === 0], false);
    }

    private function item(string $key, string $status, ?string $blocker, array $checks, bool $commissionable, ?array $last = null): array
    {
        // A failed run names itself as the blocker, whatever the credentials say.
        if ($status === self::EXTERNAL && $last !== null && $last['ok'] === false && $blocker === 'awaiting_first_event') {
            $blocker = 'commissioning_failed';
        }

        return [
            'key' => $key,
            'status' => $status,
            'blocker' => $blocker,
            'checks' => array_map(
                fn (string $name, bool $ok) => ['key' => $name, 'ok' => $ok],
                array_keys($checks),
                array_values($checks),
            ),
            'commissionable' => $commissionable,
            'last_commissioning' => $last,
        ];
    }

    private function fresh(mixed $timestamp): bool
    {
        return is_numeric($timestamp) && (time() - (int) $timestamp) <= self::PULSE_SECONDS;
    }

    /** @return array<string,array{ok:bool, at:string, steps:array}> */
    private function lastCommissioning(): array
    {
        $out = [];

        foreach (CommissioningRun::orderByDesc('id')->limit(40)->get() as $run) {
            $out[$run->key] ??= [
                'ok' => $run->ok,
                'at' => $run->created_at?->toIso8601String(),
                'steps' => $run->steps,
            ];
        }

        return $out;
    }
}
