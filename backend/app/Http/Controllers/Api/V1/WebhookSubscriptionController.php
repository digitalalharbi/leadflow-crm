<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Webhooks\OutboundWebhooks;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A company's outbound webhooks: where to send, which events, the delivery log, a test, a replay.
 *
 * The signing secret is generated here and shown once (on create and on rotate); it is never returned afterwards.
 */
class WebhookSubscriptionController extends Controller
{
    public function __construct(private OutboundWebhooks $webhooks, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->guard($request);

        return ApiResponse::success(WebhookSubscription::query()->orderByDesc('id')->get()->map(fn ($s) => $this->present($s))->values());
    }

    public function store(Request $request)
    {
        $this->guard($request);
        $data = $this->validated($request);
        $secret = 'whsec_'.Str::random(40);

        $subscription = WebhookSubscription::create($data + ['secret' => $secret, 'created_by' => $request->user()->id]);
        $this->audit->log('webhook.created', $request->user(), $subscription, ['url' => $subscription->url, 'events' => $subscription->events]);

        return ApiResponse::created(['subscription' => $this->present($subscription), 'secret' => $secret]);
    }

    public function update(Request $request, string $uuid)
    {
        $this->guard($request);
        $subscription = WebhookSubscription::query()->where('uuid', $uuid)->firstOrFail();
        $data = $this->validated($request, partial: true);

        // Turning it back on also closes the breaker: the person has looked at it.
        if (($data['is_active'] ?? false) === true) {
            $data += ['paused_at' => null, 'paused_reason' => null, 'consecutive_failures' => 0];
        }

        $subscription->update($data);
        $this->audit->log('webhook.updated', $request->user(), $subscription, array_intersect_key($data, array_flip(['url', 'events', 'is_active', 'name'])));

        return ApiResponse::success($this->present($subscription->fresh()));
    }

    public function rotate(Request $request, string $uuid)
    {
        $this->guard($request);
        $subscription = WebhookSubscription::query()->where('uuid', $uuid)->firstOrFail();
        $secret = 'whsec_'.Str::random(40);

        $subscription->update(['secret' => $secret]);
        $this->audit->log('webhook.secret_rotated', $request->user(), $subscription);

        return ApiResponse::success(['subscription' => $this->present($subscription), 'secret' => $secret]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->guard($request);
        $subscription = WebhookSubscription::query()->where('uuid', $uuid)->firstOrFail();

        $this->audit->log('webhook.deleted', $request->user(), $subscription, ['url' => $subscription->url]);
        $subscription->delete();

        return ApiResponse::noContent();
    }

    public function test(Request $request, string $uuid)
    {
        $this->guard($request);
        $subscription = WebhookSubscription::query()->where('uuid', $uuid)->firstOrFail();
        $delivery = $this->webhooks->test($subscription);

        return ApiResponse::success($this->delivery($delivery));
    }

    public function deliveries(Request $request, string $uuid)
    {
        $this->guard($request);
        $subscription = WebhookSubscription::query()->where('uuid', $uuid)->firstOrFail();

        return ApiResponse::success($subscription->deliveries()->latest('id')->limit(50)->get()->map(fn ($d) => $this->delivery($d))->values());
    }

    /** The same event, sent again under the same event id: the receiver can tell it is a repeat. */
    public function replay(Request $request, string $uuid)
    {
        $this->guard($request);
        $delivery = WebhookDelivery::query()->where('uuid', $uuid)->firstOrFail();

        $delivery->forceFill(['status' => WebhookDelivery::PENDING, 'error' => null])->save();
        DeliverWebhook::dispatch($delivery->id);
        $this->audit->log('webhook.replayed', $request->user(), $delivery, ['event' => $delivery->event]);

        return ApiResponse::success($this->delivery($delivery));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'url' => [$required, 'string', 'max:500', 'url:https'],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookSubscription::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['url']) && ! OutboundWebhooks::acceptableUrl($data['url'])) {
            abort(response()->json(['success' => false, 'data' => null, 'message' => 'Invalid address.', 'errors' => ['url' => ['A public HTTPS address is required.']]], 422));
        }

        if (isset($data['events'])) {
            $data['events'] = array_values(array_unique($data['events']));
        }

        return $data;
    }

    /** Built-in roles must be administrators; a matrix role has been checked for `integrations` already. */
    private function guard(Request $request): void
    {
        $user = $request->user();

        abort_unless($user->usesMatrix() || $user->isAdmin(), 403, 'Your role does not include this.');
    }

    private function present(WebhookSubscription $s): array
    {
        return [
            'id' => $s->uuid,
            'name' => $s->name,
            'url' => $s->url,
            'events' => $s->events,
            'is_active' => $s->is_active,
            'paused' => $s->paused_at !== null,
            'paused_reason' => $s->paused_reason,
            'consecutive_failures' => $s->consecutive_failures,
            'last_success_at' => $s->last_success_at?->toIso8601String(),
            'last_failure_at' => $s->last_failure_at?->toIso8601String(),
            'last_error' => $s->last_error,
            'health' => match (true) {
                ! $s->is_active => 'disabled',
                $s->paused_at !== null => 'paused',
                $s->last_failure_at !== null && ($s->last_success_at === null || $s->last_failure_at->gt($s->last_success_at)) => 'error',
                $s->last_success_at !== null => 'healthy',
                default => 'awaiting',
            },
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    private function delivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->uuid,
            'event' => $d->event,
            'event_id' => $d->event_id,
            'status' => $d->status,
            'attempts' => $d->attempts,
            'response_status' => $d->response_status,
            'error' => $d->error,
            'delivered_at' => $d->delivered_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
