<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tracking\EventRecorder;
use App\Domain\Tracking\EventType;
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
    public function __construct(private TenantContext $context, private EventRecorder $events) {}

    public function __invoke(Request $request, string $token)
    {
        // A beacon is posted as text/plain so no preflight is needed; the body is still JSON.
        $body = $request->isJson() ? $request->json()->all() : (json_decode($request->getContent(), true) ?: $request->all());

        $v = Validator::make(is_array($body) ? $body : [], [
            'event' => ['required', 'string', 'in:'.implode(',', EventType::browserSafeValues())],
            'visitor_id' => ['nullable', 'string', 'max:40'],
            'url' => ['nullable', 'string', 'max:2000'],
            'referrer' => ['nullable', 'string', 'max:2000'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'click_id' => ['nullable', 'string', 'max:512'],
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

            $this->context->withTenant($tenant, fn () => $this->events->record(EventType::from($data['event']), [
                'tracking_link_id' => $link->id,
                'visitor_id' => Visitor::clean($data['visitor_id'] ?? null) ?? Visitor::newId(),
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
                'click_id' => $data['click_id'] ?? null,
                'ip_hash' => Visitor::ipHash($request->ip()),
                'meta' => isset($data['meta']) ? $this->small($data['meta']) : null,
                'dedupe_key' => isset($data['dedupe_key']) ? $link->id.':'.$data['dedupe_key'] : null,
            ] + Visitor::agent($request->userAgent())));
        }

        return response()->noContent();
    }

    /** @param  array<string,mixed>  $meta */
    private function small(array $meta): array
    {
        $meta = array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 200) : null, $meta);

        return array_filter($meta, fn ($v) => $v !== null);
    }
}
