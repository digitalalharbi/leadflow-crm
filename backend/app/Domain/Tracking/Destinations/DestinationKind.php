<?php

namespace App\Domain\Tracking\Destinations;

/**
 * Everywhere an event can go, and what each place needs.
 *
 * A destination is configuration and readiness, nothing more: the identifier its browser tag uses (public by
 * nature), the credential its server API wants (secret, encrypted, never returned), and what it can do — report
 * from the page, receive events from the server, or both. Nothing here invents a credential: a destination
 * without what it needs says it is waiting, and does nothing.
 *
 * Ad platforms that already take a lead's later outcomes through the offline-conversion pipeline (Meta, TikTok,
 * Snapchat, Google Ads, LinkedIn, X) keep doing so there: stage events (qualified, appointment, reservation, won)
 * are not sent twice by two systems. A destination of these kinds adds the web side — the page's pixel, and the
 * server copy of the form submit under the same event id.
 */
enum DestinationKind: string
{
    case Ga4 = 'ga4';
    case GoogleAds = 'google_ads';
    case Meta = 'meta';
    case TikTok = 'tiktok';
    case Snapchat = 'snapchat';
    case LinkedIn = 'linkedin';
    case X = 'x';
    case GtmWeb = 'gtm_web';
    case GtmServer = 'gtm_server';
    case Webhook = 'webhook';

    /** The events a destination may be told about, in the names a marketer uses everywhere. */
    public const EVENTS = ['landing_viewed', 'whatsapp_started', 'call_started', 'form_submitted', 'lead_created', 'qualified', 'interested', 'appointment_requested', 'appointment_booked', 'visit_completed', 'reservation_created', 'won', 'lost', 'revenue_recorded'];

    /**
     * Mohtam's event → the platform's, when a destination says so. A form submit is a Lead by default; a sale is the Purchase.
     * Anything else is the workspace's choice, and the screen warns when it would teach a platform that a form is a sale.
     */
    public const PLATFORM_EVENTS = ['Lead', 'CompleteRegistration', 'SubmitForm', 'Contact', 'Schedule', 'InitiateCheckout', 'AddPaymentInfo', 'Purchase', 'generate_lead', 'qualify_lead', 'purchase'];

    /** What a destination may rename: events the server witnessed. A visit or a tap is reported by the browser, by anybody. */
    public const MAPPABLE_EVENTS = ['form_submitted', 'lead_created', 'qualified', 'interested', 'appointment_requested', 'appointment_booked', 'visit_completed', 'reservation_created', 'won', 'lost', 'revenue_recorded'];

    /** What is sent unless the workspace chooses otherwise. */
    public const DEFAULT_EVENTS = ['form_submitted', 'lead_created', 'qualified', 'interested', 'appointment_booked', 'reservation_created', 'won', 'revenue_recorded'];

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Ga4 => 'Google Analytics 4',
            self::GoogleAds => 'Google Ads',
            self::Meta => 'Meta (Pixel وConversions API)',
            self::TikTok => 'TikTok (Pixel وEvents API)',
            self::Snapchat => 'Snapchat Pixel',
            self::LinkedIn => 'LinkedIn Insight Tag',
            self::X => 'X Pixel',
            self::GtmWeb => 'Google Tag Manager (الويب)',
            self::GtmServer => 'Google Tag Manager (الخادم)',
            self::Webhook => 'Webhook مخصّص',
        };
    }

    /** Reports from the visitor's page. */
    public function browser(): bool
    {
        return in_array($this, [self::Ga4, self::GoogleAds, self::Meta, self::TikTok, self::Snapchat, self::LinkedIn, self::X, self::GtmWeb], true);
    }

    /** Takes events from the server as well. */
    public function server(): bool
    {
        return in_array($this, [self::Ga4, self::Meta, self::TikTok, self::GtmServer, self::Webhook], true);
    }

    /** The ad platform whose offline-conversion pipeline carries the later stages, when there is one. */
    public function conversionPlatform(): ?string
    {
        return in_array($this, [self::Meta, self::TikTok, self::Snapchat, self::GoogleAds, self::LinkedIn, self::X], true) ? $this->value : null;
    }

    /** @return list<string> the public identifiers it needs */
    public function config(): array
    {
        return match ($this) {
            self::Ga4 => ['measurement_id'],
            self::GoogleAds => ['conversion_id'],
            self::Meta, self::TikTok, self::Snapchat, self::X => ['pixel_id'],
            self::LinkedIn => ['partner_id'],
            self::GtmWeb => ['container_id'],
            self::GtmServer, self::Webhook => ['endpoint_url'],
        };
    }

    /** @return list<string> the secrets its server side needs (empty: nothing secret is required) */
    public function credentials(): array
    {
        return match ($this) {
            self::Ga4 => ['api_secret'],
            self::Meta, self::TikTok => ['access_token'],
            self::Webhook => ['secret'],
            default => [],
        };
    }

    /** @return list<string> secrets it may have beyond those it needs */
    public function optionalCredentials(): array
    {
        return $this === self::GtmServer ? ['secret'] : [];
    }

    /**
     * What is wrong with this configuration: identifier → why. Empty when every identifier has the shape of the real thing —
     * an identifier is injected into a page or called from a server, so nothing else is accepted.
     *
     * @param  array<string,mixed>  $config
     * @return array<string,string>
     */
    public function configErrors(array $config): array
    {
        $errors = [];

        foreach ($this->config() as $key) {
            $value = trim((string) ($config[$key] ?? ''));

            $ok = match ([$this, $key]) {
                [self::Ga4, 'measurement_id'] => (bool) preg_match('/^G-[A-Z0-9]{4,14}$/', $value),
                [self::GoogleAds, 'conversion_id'] => (bool) preg_match('/^AW-\d{6,12}$/', $value),
                [self::Meta, 'pixel_id'] => (bool) preg_match('/^\d{5,20}$/', $value),
                [self::TikTok, 'pixel_id'] => (bool) preg_match('/^[A-Z0-9]{8,30}$/', $value),
                [self::Snapchat, 'pixel_id'] => (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value),
                [self::X, 'pixel_id'] => (bool) preg_match('/^[a-z0-9]{4,12}$/', $value),
                [self::LinkedIn, 'partner_id'] => (bool) preg_match('/^\d{4,12}$/', $value),
                [self::GtmWeb, 'container_id'] => (bool) preg_match('/^GTM-[A-Z0-9]{4,10}$/', $value),
                [self::GtmServer, 'endpoint_url'], [self::Webhook, 'endpoint_url'] => SafeUrl::isAcceptable($value),
                default => false,
            };

            if (! $ok) {
                $errors[$key] = $value === '' ? 'required' : 'invalid';
            }
        }

        return $errors;
    }

    /**
     * ready               everything it needs is there
     * browser_only        the page's tag is configured; the server side is waiting for its credential
     * awaiting_credentials it works from the server and the credential is not there yet
     * not_configured      an identifier is missing or malformed
     *
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $credentials
     */
    public function readiness(array $config, array $credentials): string
    {
        if ($this->configErrors($config) !== []) {
            return 'not_configured';
        }

        $missing = array_filter($this->credentials(), fn (string $key) => blank($credentials[$key] ?? null));

        return match (true) {
            $missing === [] => 'ready',
            $this->browser() => 'browser_only',
            default => 'awaiting_credentials',
        };
    }
}
