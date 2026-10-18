<?php

namespace App\Domain\Readiness;

use App\Console\Commands\Heartbeat;
use App\Domain\Integrations\IntegrationHealthReport;
use App\Domain\Sheets\GoogleOAuth;
use App\Jobs\QueueHeartbeat;
use App\Models\CommissioningRun;
use App\Models\SheetSyncRun;
use App\Services\Whatsapp\WhatsappManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
            $workspace ? $this->whatsapp($rows, $last) : $this->perWorkspace('whatsapp'),
            $workspace ? $this->twilio($rows, $last) : $this->perWorkspace('twilio'),
            $workspace ? $this->ads($rows, $last) : $this->perWorkspace('ads'),
        ];

        $summary = array_count_values(array_column($items, 'status'));

        $infrastructure = $this->infrastructure->all();

        return [
            'scope' => $workspace ? 'workspace' : 'platform',
            'items' => $items,
            /*
             * What the platform stands on. Apart from `items` on purpose: those
             * are things a customer connects, these are things the deployment
             * either has or does not, and the two have different people to ask.
             */
            'infrastructure' => $infrastructure,
            'infrastructure_summary' => array_count_values(array_column($infrastructure, 'state')),
            'summary' => [
                self::LIVE => $summary[self::LIVE] ?? 0,
                self::READY => $summary[self::READY] ?? 0,
                self::EXTERNAL => $summary[self::EXTERNAL] ?? 0,
            ],
        ];
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
        $public = $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/(^|\.)(test|local|localhost|invalid|internal)$|(^|\.)example\.(com|org|net)$/i', $host) !== 1
            && str_contains($host, '.');

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
