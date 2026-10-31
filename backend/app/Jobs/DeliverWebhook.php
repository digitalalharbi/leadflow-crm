<?php

namespace App\Jobs;

use App\Domain\Tracking\Destinations\SafeUrl;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * Posts one event to one company endpoint, signed.
 *
 * Signature: `X-Mohtam-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<unix>.<body>" with the subscription secret>`, so a
 * receiver can reject a replayed request by its age. `X-Mohtam-Event-Id` is the idempotency key on their side.
 *
 * Retried with backoff (1 min → 5 → 30 → 2 h → 6 h). A delivery that exhausts its tries is marked failed and counts
 * against the subscription; after WebhookSubscription::PAUSE_AFTER failures in a row the subscription pauses itself (the
 * circuit breaker), so a dead endpoint is not hammered and the queue is not filled with hopeless work. No redirects are
 * followed, the host must resolve publicly at send time, and the connection is pinned to that checked address.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public array $backoff = [60, 300, 1800, 7200, 21600];

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(TenantContext $tenants): void
    {
        $delivery = WebhookDelivery::withoutGlobalScopes()->find($this->deliveryId);

        if ($delivery === null || $delivery->status === WebhookDelivery::SUCCEEDED) {
            return;
        }

        $tenant = Tenant::withoutGlobalScopes()->find($delivery->tenant_id);

        if ($tenant === null) {
            return;
        }

        $tenants->withTenant($tenant, function () use ($delivery) {
            $subscription = WebhookSubscription::find($delivery->subscription_id);

            if ($subscription === null || (! $subscription->is_active && $delivery->event !== 'webhook.test')) {
                $delivery->forceFill(['status' => WebhookDelivery::FAILED, 'error' => 'Subscription disabled'])->save();

                return;
            }

            [$ok, $status, $error] = $this->post($subscription, $delivery);

            $delivery->forceFill([
                'attempts' => $delivery->attempts + 1,
                'response_status' => $status,
                'status' => $ok ? WebhookDelivery::SUCCEEDED : WebhookDelivery::PENDING,
                'error' => $ok ? null : $error,
                'delivered_at' => $ok ? now() : null,
            ])->save();

            if ($ok) {
                $subscription->forceFill(['consecutive_failures' => 0, 'last_success_at' => now(), 'last_error' => null])->saveQuietly();

                return;
            }

            $subscription->forceFill(['last_failure_at' => now(), 'last_error' => $error])->saveQuietly();

            // A test is one try, reported straight back. A real event goes back to the queue.
            if ($delivery->event === 'webhook.test') {
                $delivery->forceFill(['status' => WebhookDelivery::FAILED])->save();

                return;
            }

            if ($this->attempts() >= $this->tries) {
                $this->giveUp($delivery, $subscription);

                return;
            }

            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        });
    }

    /** @return array{0: bool, 1: ?int, 2: ?string} */
    private function post(WebhookSubscription $subscription, WebhookDelivery $delivery): array
    {
        $pinned = SafeUrl::isAcceptable($subscription->url) ? SafeUrl::pinned($subscription->url) : null;

        if ($pinned === null) {
            return [false, null, 'The address is not a public HTTPS address.'];
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $subscription->secret);

        try {
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->withOptions($pinned)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Mohtam-Webhooks/1.0',
                    'X-Mohtam-Event' => $delivery->event,
                    'X-Mohtam-Event-Id' => $delivery->event_id,
                    'X-Mohtam-Signature' => "t={$timestamp},v1={$signature}",
                ])
                ->withBody($body, 'application/json')
                ->post($subscription->url);
        } catch (\Throwable $e) {
            // The exception text can name internal hosts; keep only its kind.
            return [false, null, 'Network error ('.class_basename($e).')'];
        }

        return $response->successful()
            ? [true, $response->status(), null]
            : [false, $response->status(), 'The endpoint answered '.$response->status()];
    }

    private function giveUp(WebhookDelivery $delivery, WebhookSubscription $subscription): void
    {
        $delivery->forceFill(['status' => WebhookDelivery::FAILED])->save();

        $failures = $subscription->consecutive_failures + 1;
        $pause = $failures >= WebhookSubscription::PAUSE_AFTER && $subscription->paused_at === null;

        $subscription->forceFill(array_filter([
            'consecutive_failures' => $failures,
            'paused_at' => $pause ? now() : null,
            'paused_reason' => $pause ? 'consecutive_failures' : null,
        ], fn ($v) => $v !== null))->saveQuietly();

        if ($pause) {
            app(AuditLogger::class)->logForTenant($subscription->tenant_id, 'webhook.paused', null, $subscription, ['failures' => $failures]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        WebhookDelivery::withoutGlobalScopes()->whereKey($this->deliveryId)->where('status', '!=', WebhookDelivery::SUCCEEDED)
            ->update(['status' => WebhookDelivery::FAILED]);
    }
}
