<?php

namespace App\Domain\Integrations;

use App\Models\CommissioningRun;
use App\Models\LeadMessage;
use App\Models\LeadSourceEvent;
use App\Support\Deployment;

/**
 * How far an integration has actually got, in six words that do not overclaim.
 *
 *   not_configured       nothing has been supplied
 *   code_verified        credentials are entered; our code is tested; nothing has
 *                        yet proven the credentials to the provider
 *   sandbox_verified     the pipeline has run end to end on the demo provider or
 *                        on simulated events — real shape, no real counterpart
 *   ready                the provider accepted the credentials when asked
 *   live_verified        the other end did something real: a receipt, a lead,
 *                        a measured call, a sheet read
 *   production_verified  live-verified, in production, recently, with no error
 *
 * The word "connected" is not on the list, deliberately. It was used for "a row
 * exists", "a token was typed" and "Meta said yes" interchangeably, and a screen
 * that says connected beside a demo provider is a screen that will, one day,
 * tell somebody their messages are being delivered.
 *
 * Computed from evidence each time it is asked, never stored: a stored state is a
 * claim that outlives the evidence for it.
 */
class IntegrationState
{
    public const NOT_CONFIGURED = 'not_configured';

    public const CODE_VERIFIED = 'code_verified';

    public const SANDBOX_VERIFIED = 'sandbox_verified';

    public const READY = 'ready';

    public const LIVE_VERIFIED = 'live_verified';

    public const PRODUCTION_VERIFIED = 'production_verified';

    /** Weakest to strongest, for ordering and for "at least". */
    public const LADDER = [
        self::NOT_CONFIGURED,
        self::CODE_VERIFIED,
        self::SANDBOX_VERIFIED,
        self::READY,
        self::LIVE_VERIFIED,
        self::PRODUCTION_VERIFIED,
    ];

    /** How recently something real must have happened to call it production-verified. */
    private const FRESH_DAYS = 7;

    /** Which commissioning key proves which health row. */
    private const COMMISSIONED_BY = [
        'google_sheets' => 'google',
        'whatsapp' => 'whatsapp',
        'calls' => 'twilio',
        'email' => 'email',
        'meta' => 'ads',
        'tiktok' => 'ads',
        'google_ads' => 'ads',
        'snapchat' => 'ads',
        'linkedin' => 'ads',
    ];

    /** @var array<string,bool>|null */
    private ?array $commissioned = null;

    /**
     * @param  array<string,mixed>  $row  a health row
     * @param  bool  $credentials  whether the workspace has entered what this needs
     */
    public function resolve(array $row, bool $credentials): string
    {
        $key = (string) $row['key'];
        $live = ($row['verification'] ?? null) === IntegrationHealthReport::EXTERNALLY_VERIFIED;

        if ($live) {
            /*
             * Real events count as *live* only when they were recorded by a
             * production deployment. The same event on a developer machine or a
             * staging server proves the provider and the credentials work —
             * that is "ready" — and says nothing about what customers use.
             */
            if (! Deployment::isProduction()) {
                return self::READY;
            }

            return $this->isFresh($row) ? self::PRODUCTION_VERIFIED : self::LIVE_VERIFIED;
        }

        // The generic webhook is the workspace's own systems posting to an
        // endpoint we provisioned: there is no provider to commission and no
        // sandbox to be in. It is ready the moment it exists.
        if ($key === 'generic_webhook') {
            return self::READY;
        }

        if ($this->commissioned($key)) {
            return self::READY;
        }

        if ($this->sandboxEvidence($key, $credentials)) {
            return self::SANDBOX_VERIFIED;
        }

        return $credentials ? self::CODE_VERIFIED : self::NOT_CONFIGURED;
    }

    public static function atLeast(string $state, string $minimum): bool
    {
        return array_search($state, self::LADDER, true) >= array_search($minimum, self::LADDER, true);
    }

    /**
     * Live becomes production-verified while it keeps being true: the last real
     * event is recent and nothing is currently wrong. A deployment that proved
     * itself in March and has been silent since has earned "live", and no more.
     */
    private function isFresh(array $row): bool
    {
        if (filled($row['last_error'] ?? null)) {
            return false;
        }

        $last = $row['last_event_at'] ?? null;

        return $last !== null && strtotime((string) $last) >= now()->subDays(self::FRESH_DAYS)->timestamp;
    }

    private function commissioned(string $key): bool
    {
        $run = self::COMMISSIONED_BY[$key] ?? null;

        if ($run === null) {
            return false;
        }

        $this->commissioned ??= CommissioningRun::orderByDesc('id')->limit(60)->get()
            ->unique('key')
            ->mapWithKeys(fn (CommissioningRun $r) => [$r->key => $r->ok])
            ->all();

        return $this->commissioned[$run] ?? false;
    }

    /**
     * Evidence that something ran, from something that is not the real provider.
     *
     * WhatsApp: a message the demo provider "sent". A lead source: events that
     * arrived although this workspace holds no credentials for that platform —
     * which can only have been a simulation, a test payload posted by hand, or
     * the demo "simulate a lead" button.
     */
    private function sandboxEvidence(string $key, bool $credentials): bool
    {
        if ($key === 'whatsapp') {
            return LeadMessage::where('provider', 'mock')->exists();
        }

        // An email the log or array transport "sent": the pipeline ran end to
        // end and nothing left the building.
        if ($key === 'email') {
            return LeadMessage::where('channel', 'email')->where('provider', 'mock')->exists();
        }

        if (in_array($key, ['meta', 'tiktok', 'google_ads', 'snapchat', 'linkedin'], true) && ! $credentials) {
            return LeadSourceEvent::where('provider', $key)->exists();
        }

        return false;
    }
}
