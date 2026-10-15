<?php

namespace App\Domain\Integrations;

use App\Enums\IntegrationProvider;
use App\Models\Call;
use App\Models\Integration;
use App\Models\LeadMessage;
use App\Models\LeadSourceEvent;
use App\Models\RepositoryWebhookDelivery;
use App\Models\SheetSyncRun;
use App\Models\WhatsappEvent;

/**
 * Which integrations have ever actually worked.
 *
 * The distinction this exists for: **implemented** and **externally verified**
 * are not the same claim, and a product that shows them as one will eventually
 * tell somebody a channel is ready when nobody has ever sent anything through
 * it. Every adapter here has tests; tests prove the code does what we think
 * when the other end behaves as we think. They cannot prove the other end
 * exists.
 *
 * So verification is computed from evidence rather than asserted: a provider is
 * externally verified when something real has come through it — a lead with
 * that provider's own id on it, a sheet row imported, a WhatsApp delivery
 * receipt, a signed GitHub delivery. Not when the code was written, and not
 * when a credential was pasted in.
 */
class IntegrationHealthReport
{
    public const IMPLEMENTED = 'implemented';

    public const EXTERNALLY_VERIFIED = 'externally_verified';

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return array_merge(
            $this->leadSources(),
            [$this->sheets(), $this->whatsapp(), $this->calls(), $this->github()],
        );
    }

    /**
     * The ad platforms and the generic webhook.
     *
     * Evidence is a `LeadSourceEvent` carrying that provider: a real arrival,
     * recorded at the moment it was ingested. Credentials being present proves
     * only that somebody typed them.
     */
    private function leadSources(): array
    {
        $providers = [
            IntegrationProvider::Meta,
            IntegrationProvider::TikTok,
            IntegrationProvider::GoogleAds,
            IntegrationProvider::Snapchat,
            IntegrationProvider::LinkedIn,
            IntegrationProvider::GenericWebhook,
        ];

        $rows = [];

        foreach ($providers as $provider) {
            $integration = Integration::where('provider', $provider->value)->first();

            $lastEvent = LeadSourceEvent::where('provider', $provider->value)
                ->latest('received_at')
                ->first();

            $rows[] = [
                'key' => $provider->value,
                'kind' => 'lead_source',
                'connected' => $integration !== null && $integration->status === Integration::CONNECTED,
                'configured' => $integration !== null && $integration->configuredCredentialKeys() !== [],
                'last_event_at' => $lastEvent?->received_at?->toIso8601String(),
                'last_sync_at' => $integration?->last_synced_at?->toIso8601String(),
                'last_error' => $integration?->last_error,
                'events' => (int) ($integration?->leads_received ?? 0),
                // How this one arrives, which is what a reader needs to know
                // before asking why nothing has.
                'flow' => $provider === IntegrationProvider::GoogleAds ? 'pull' : 'webhook',
                'verification' => $lastEvent ? self::EXTERNALLY_VERIFIED : self::IMPLEMENTED,
                'blocker' => match (true) {
                    $lastEvent !== null => null,
                    $integration === null || $integration->configuredCredentialKeys() === [] => 'credentials_missing',
                    default => 'awaiting_first_event',
                },
            ];
        }

        return $rows;
    }

    /** Evidence: a sync that finished having actually read rows. */
    private function sheets(): array
    {
        $connection = Integration::where('provider', IntegrationProvider::GoogleSheets->value)->first();

        $lastRun = SheetSyncRun::where('status', SheetSyncRun::COMPLETED)
            ->where('rows_seen', '>', 0)
            ->latest('started_at')
            ->first();

        return [
            'key' => 'google_sheets',
            'kind' => 'lead_source',
            'connected' => filled($connection?->credentials['refresh_token'] ?? null),
            'configured' => filled(config('integrations.google_sheets.client_id')),
            'last_event_at' => $lastRun?->started_at?->toIso8601String(),
            'last_sync_at' => SheetSyncRun::max('started_at'),
            'last_error' => $connection?->last_error,
            'events' => (int) SheetSyncRun::sum('imported'),
            'flow' => 'pull',
            'verification' => $lastRun ? self::EXTERNALLY_VERIFIED : self::IMPLEMENTED,
            /*
             * Three reasons with one symptom, kept apart because they are three
             * different people's jobs: the platform has no OAuth client (ours),
             * the workspace has not completed the grant (theirs), or it has and
             * nothing has synced yet (nobody's — wait).
             */
            'blocker' => match (true) {
                $lastRun !== null => null,
                blank(config('integrations.google_sheets.client_id')) => 'platform_not_configured',
                blank($connection?->credentials['refresh_token'] ?? null) => 'not_connected',
                default => 'awaiting_first_event',
            },
        ];
    }

    /**
     * Evidence: an inbound event from Meta, or a message that reached a phone.
     *
     * A message we sent and never heard about again proves our end works and
     * nothing else — it is the delivery receipt that proves the channel.
     */
    private function whatsapp(): array
    {
        $integration = Integration::where('provider', IntegrationProvider::Whatsapp->value)->first();

        $live = filled($integration?->credentials['access_token'] ?? null)
            && filled($integration?->config['phone_number_id'] ?? null);

        /*
         * Evidence counts only from a workspace that can actually reach Meta.
         *
         * The mock provider mints delivery receipts of its own, and the E2E
         * fixtures post signed webhooks to a workspace that has an app secret
         * and no access token — both of which wrote rows this report used to
         * read as proof. A workspace with no live credentials has never spoken
         * to Meta, whatever its tables say, so the question is settled before
         * the tables are asked.
         */
        $inbound = $live ? WhatsappEvent::latest('id')->first() : null;

        $delivered = $live
            ? LeadMessage::where('channel', 'whatsapp')
                ->where('provider', 'whatsapp_cloud')
                ->whereIn('status', ['delivered', 'read'])
                ->latest('id')
                ->first()
            : null;

        $evidence = $inbound ?? $delivered;

        return [
            'key' => 'whatsapp',
            'kind' => 'messaging',
            'connected' => filled($integration?->credentials['access_token'] ?? null),
            'configured' => $integration !== null,
            'last_event_at' => $evidence?->created_at?->toIso8601String(),
            'last_sync_at' => $integration?->last_synced_at?->toIso8601String(),
            'last_error' => $integration?->last_error,
            'events' => (int) LeadMessage::where('channel', 'whatsapp')->count(),
            'flow' => 'webhook',
            'verification' => $evidence ? self::EXTERNALLY_VERIFIED : self::IMPLEMENTED,
            'blocker' => $evidence ? null : ($live ? 'awaiting_first_event' : 'credentials_missing'),
        ];
    }

    /**
     * Calls are the one row that is honest by construction.
     *
     * The only provider registered places no calls, so there is nothing to
     * verify externally and the row says so rather than reporting a channel
     * that works. A connected provider would be evidenced by a call that
     * carries its id.
     */
    private function calls(): array
    {
        $configured = Integration::where('provider', IntegrationProvider::TwilioVoice->value)->first();

        /*
         * Evidence is a call the provider itself reported on: it has an
         * external id, and it reached a state only a provider could put it in.
         * A row with provider `twilio` that never left `initiated` proves the
         * request was sent and nothing more.
         */
        $provider = Call::whereNot('provider', 'manual')
            ->whereNotNull('external_id')
            ->whereIn('status', [Call::ANSWERED, Call::COMPLETED, Call::NO_ANSWER])
            ->latest('id')
            ->first();

        return [
            'key' => 'calls',
            'kind' => 'telephony',
            'connected' => $configured?->status === Integration::CONNECTED,
            'configured' => filled($configured?->credentials['account_sid'] ?? null),
            'last_event_at' => $provider?->started_at?->toIso8601String(),
            'last_sync_at' => null,
            'last_error' => null,
            'events' => (int) Call::count(),
            'flow' => $configured?->status === Integration::CONNECTED ? 'webhook' : 'manual',
            // Without a provider this is not "implemented but unproven" — it is
            // the product telling you the rep dials, which is a different state
            // and deserves its own word.
            // `manual_only` is a fact about the product, not a stage of
            // progress: with no telephony account the rep dials, and that is
            // not "unverified" — it is a different arrangement.
            'verification' => $provider
                ? self::EXTERNALLY_VERIFIED
                : (filled($configured?->credentials['account_sid'] ?? null) ? self::IMPLEMENTED : 'manual_only'),
            'blocker' => match (true) {
                $provider !== null => null,
                blank($configured?->credentials['account_sid'] ?? null) => 'no_provider_selected',
                $configured->status !== Integration::CONNECTED => 'credentials_missing',
                default => 'awaiting_first_event',
            },
        ];
    }

    /** Evidence: a delivery that arrived, verified its signature and was processed. */
    private function github(): array
    {
        $connection = Integration::where('provider', IntegrationProvider::GitHub->value)->first();

        $delivery = RepositoryWebhookDelivery::where('signature_valid', true)
            ->where('status', RepositoryWebhookDelivery::PROCESSED)
            ->latest('received_at')
            ->first();

        return [
            'key' => 'github',
            'kind' => 'development',
            'connected' => filled($connection?->credentials['access_token'] ?? null),
            'configured' => $connection !== null,
            'last_event_at' => $delivery?->received_at?->toIso8601String(),
            'last_sync_at' => $connection?->last_synced_at?->toIso8601String(),
            'last_error' => $connection?->last_error,
            'events' => (int) RepositoryWebhookDelivery::where('signature_valid', true)->count(),
            'flow' => 'webhook',
            'verification' => $delivery ? self::EXTERNALLY_VERIFIED : self::IMPLEMENTED,
            'blocker' => match (true) {
                $delivery !== null => null,
                blank($connection?->credentials['access_token'] ?? null) => 'credentials_missing',
                default => 'awaiting_first_event',
            },
        ];
    }
}
