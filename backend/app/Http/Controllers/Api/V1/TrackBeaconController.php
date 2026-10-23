<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tracking\Attribution;
use App\Domain\Tracking\ClickIds;
use App\Domain\Tracking\EventRecorder;
use App\Domain\Tracking\EventType;
use App\Domain\Tracking\Sessions;
use App\Domain\Tracking\Visitor;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\TrackingLink;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * What a landing page tells us a visitor did.
 *
 * Public and unauthenticated by nature — the page is somebody else's website — so it trusts
 * almost nothing: the link token says which workspace the event belongs to, the event type is
 * from a closed list of things a browser may report (never a sale, a reservation or a visit to
 * a site), the visitor id must be one we issued, and a replayed beacon is the same event.
 *
 * It answers 204 to everything it understood and 422 to what it did not, and never says whether
 * a token exists, so it cannot be used to find tokens.
 */
class TrackBeaconController extends Controller
{
    public function __construct(private TenantContext $context, private EventRecorder $events, private Sessions $sessions) {}

    public function __invoke(Request $request, string $token)
    {
        // A beacon is posted as text/plain so no preflight is needed; the body is still JSON.
        $body = $request->isJson() ? $request->json()->all() : (json_decode($request->getContent(), true) ?: $request->all());

        $v = Validator::make(is_array($body) ? $body : [], [
            'event' => ['required', 'string', 'in:'.implode(',', EventType::browserSafeValues())],
            'visitor_id' => ['nullable', 'string', 'max:40'],
            'session_id' => ['nullable', 'string', 'max:40'],
            // The id the browser's own pixel used for the same thing, so a platform counts it once.
            'event_id' => ['nullable', 'uuid'],
            'consent' => ['nullable', 'in:granted,denied'],
            'url' => ['nullable', 'string', 'max:2000'],
            'referrer' => ['nullable', 'string', 'max:2000'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'click_id' => ['nullable', 'string', 'max:512'],
            'click_ids' => ['nullable', 'array'],
            'project' => ['nullable', 'uuid'],
            'property' => ['nullable', 'uuid'],
            'dedupe_key' => ['nullable', 'string', 'max:80'],
            'meta' => ['nullable', 'array', 'max:12'],
        ]);

        if ($v->fails()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Invalid event.', 'errors' => $v->errors()], 422);
        }

        $data = $v->validated();

        $link = $this->context->withoutScope(fn () => TrackingLink::withoutGlobalScopes()->where('token', $token)->first());

        if ($link !== null && $link->isLive()) {
            $tenant = Tenant::withoutGlobalScopes()->findOrFail($link->tenant_id);

            $this->context->withTenant($tenant, function () use ($data, $link, $request) {
                $visitor = Visitor::clean($data['visitor_id'] ?? null) ?? Visitor::newId();
                $agent = Visitor::agent($request->userAgent());
                $clickIds = ClickIds::from((array) ($data['click_ids'] ?? [])) ?: ClickIds::from(['gclid' => $data['click_id'] ?? null]);
                $utm = array_filter(array_intersect_key($data, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'])));

                $session = $this->sessions->touch($visitor, $data['session_id'] ?? null, $link, [
                    'click_ids' => $clickIds, 'utm' => $utm, 'landing_url' => $data['url'] ?? null, 'referrer' => $data['referrer'] ?? $request->headers->get('referer'),
                    'ip_hash' => Visitor::ipHash($request->ip()), 'consent' => $data['consent'] ?? null,
                ] + $agent);

                $this->events->record(EventType::from($data['event']), [
                    'tracking_link_id' => $link->id,
                    'visitor_id' => $visitor,
                    'session_id' => $session->session_id,
                    'event_id' => $data['event_id'] ?? null,
                    'campaign_id' => $link->campaign_id,
                    'platform' => Attribution::platformOf($link->channel),
                    'consent' => $session->consent,
                    // The page may say which project or unit was viewed; the link's own is the fallback.
                    'project_id' => isset($data['project']) ? Project::where('uuid', $data['project'])->value('id') : $link->project_id,
                    'property_id' => isset($data['property']) ? Property::where('uuid', $data['property'])->value('id') : $link->property_id,
                    'url' => $data['url'] ?? null,
                    'referrer' => $data['referrer'] ?? $request->headers->get('referer'),
                    'utm_source' => $data['utm_source'] ?? null,
                    'utm_medium' => $data['utm_medium'] ?? null,
                    'utm_campaign' => $data['utm_campaign'] ?? null,
                    'utm_term' => $data['utm_term'] ?? null,
                    'utm_content' => $data['utm_content'] ?? null,
                    'click_id' => ClickIds::primary($clickIds) ?? ($data['click_id'] ?? null),
                    'ip_hash' => Visitor::ipHash($request->ip()),
                    'meta' => isset($data['meta']) ? $this->small($data['meta'], $data['event']) : null,
                    'dedupe_key' => isset($data['dedupe_key']) ? $link->id.':'.$data['dedupe_key'] : null,
                ] + $agent);
            });
        }

        return response()->noContent();
    }

    /** @param  array<string,mixed>  $meta */
    private function small(array $meta, string $event): array
    {
        // A form's own events say where a visitor was — a step, a field's key — and nothing else: never a word they typed.
        if (str_starts_with($event, 'form_')) {
            return array_filter([
                'step' => isset($meta['step']) && preg_match('/^\d{1,2}$/', (string) $meta['step']) ? (string) (int) $meta['step'] : null,
                'field' => isset($meta['field']) && is_string($meta['field']) && preg_match('/^[a-z][a-z0-9_]{0,30}$/', $meta['field']) ? $meta['field'] : null,
            ], fn ($v) => $v !== null);
        }

        $meta = array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 200) : null, $meta);

        return array_filter($meta, fn ($v) => $v !== null);
    }
}
