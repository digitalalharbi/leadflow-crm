<?php

namespace App\Http\Controllers;

use App\Domain\Tracking\EventRecorder;
use App\Domain\Tracking\TrackingLinks;
use App\Domain\Tracking\Visitor;
use App\Models\Tenant;
use App\Models\TrackingLink;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;

/**
 * `/t/{token}` — the link that knows what it is for.
 *
 * Public, because it is printed on a billboard and pasted into an ad; what stands in for
 * authentication is that the token is short, random and worth nothing on its own. It records the
 * click and sends the visitor on in one redirect, and a failure to record must never stop the
 * redirect: somebody who tapped a link should arrive wherever the tracking tables are.
 */
class TrackingRedirectController extends Controller
{
    public function __construct(private TenantContext $context, private TrackingLinks $links, private EventRecorder $events) {}

    public function __invoke(Request $request, string $token)
    {
        $link = $this->context->withoutScope(fn () => TrackingLink::withoutGlobalScopes()->where('token', $token)->first());

        abort_if($link === null || ! $link->isLive(), 404);

        $visitor = Visitor::clean($request->query('mt_vid')) ?? Visitor::clean($request->cookie(Visitor::COOKIE)) ?? Visitor::newId();

        $this->record($request, $link, $visitor);

        return redirect()->away($this->links->destination($link, $visitor))
            ->withCookie(Cookie::make(Visitor::COOKIE, $visitor, 60 * 24 * 365, '/', null, $request->isSecure(), false, false, 'Lax'));
    }

    private function record(Request $request, TrackingLink $link, string $visitor): void
    {
        try {
            $tenant = Tenant::withoutGlobalScopes()->findOrFail($link->tenant_id);

            $this->context->withTenant($tenant, function () use ($request, $link, $visitor) {
                $this->events->record($this->links->clickEvent($link), [
                    'tracking_link_id' => $link->id,
                    'visitor_id' => $visitor,
                    'project_id' => $link->project_id,
                    'property_id' => $link->property_id,
                    'url' => $request->fullUrl(),
                    'referrer' => $request->headers->get('referer'),
                    'utm_source' => $link->utm_source,
                    'utm_medium' => $link->utm_medium,
                    'utm_campaign' => $link->utm_campaign,
                    'utm_term' => $link->utm_term,
                    'utm_content' => $link->utm_content,
                    'click_id' => $request->query('gclid') ?? $request->query('fbclid') ?? $request->query('ttclid') ?? $request->query('sccid'),
                    'ip_hash' => Visitor::ipHash($request->ip()),
                ] + Visitor::agent($request->userAgent()));
            });
        } catch (\Throwable $e) {
            Log::warning('tracking_click_failed', ['link' => $link->id, 'error' => $e->getMessage()]);
        }
    }
}
