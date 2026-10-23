<?php

namespace App\Http\Controllers;

use App\Domain\Tracking\Attribution;
use App\Domain\Tracking\ClickIds;
use App\Domain\Tracking\EventRecorder;
use App\Domain\Tracking\Sessions;
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
 *
 * The click starts (or continues) a session, keeps every platform click id it arrived with, and
 * hands the visitor and the session on — to an address of the workspace's, or to a landing page of ours.
 */
class TrackingRedirectController extends Controller
{
    public function __construct(private TenantContext $context, private TrackingLinks $links, private EventRecorder $events, private Sessions $sessions) {}

    public function __invoke(Request $request, string $token)
    {
        $link = $this->context->withoutScope(fn () => TrackingLink::withoutGlobalScopes()->where('token', $token)->first());

        abort_if($link === null || ! $link->isLive(), 404);

        $visitor = Visitor::clean($request->query('mt_vid')) ?? Visitor::clean($request->cookie(Visitor::COOKIE)) ?? Visitor::newId();
        $sessionId = Sessions::clean($request->query('mt_sid')) ?? Sessions::clean($request->cookie(Sessions::COOKIE));
        $clickIds = ClickIds::from($request->query());

        $sessionId = $this->record($request, $link, $visitor, $sessionId, $clickIds);

        $year = 60 * 24 * 365;
        $secure = $request->isSecure();

        return redirect()->away($this->links->destination($link, $visitor, $sessionId, $clickIds))
            ->withCookie(Cookie::make(Visitor::COOKIE, $visitor, $year, '/', null, $secure, false, false, 'Lax'))
            ->withCookie(Cookie::make(Sessions::COOKIE, $sessionId ?? Sessions::newId(), 60 * 24, '/', null, $secure, false, false, 'Lax'));
    }

    /** @return string|null the session the click belongs to */
    private function record(Request $request, TrackingLink $link, string $visitor, ?string $sessionId, array $clickIds): ?string
    {
        try {
            $tenant = Tenant::withoutGlobalScopes()->findOrFail($link->tenant_id);

            return $this->context->withTenant($tenant, function () use ($request, $link, $visitor, $sessionId, $clickIds) {
                $agent = Visitor::agent($request->userAgent());
                $utm = array_filter([
                    'utm_source' => $link->utm_source, 'utm_medium' => $link->utm_medium, 'utm_campaign' => $link->utm_campaign,
                    'utm_term' => $link->utm_term, 'utm_content' => $link->utm_content,
                ]);

                $session = $this->sessions->touch($visitor, $sessionId, $link, [
                    'click_ids' => $clickIds, 'utm' => $utm, 'landing_url' => $request->fullUrl(), 'referrer' => $request->headers->get('referer'),
                    'ip_hash' => Visitor::ipHash($request->ip()),
                ] + $agent);

                $this->events->record($this->links->clickEvent($link), [
                    'tracking_link_id' => $link->id,
                    'visitor_id' => $visitor,
                    'session_id' => $session->session_id,
                    'project_id' => $link->project_id,
                    'property_id' => $link->property_id,
                    'campaign_id' => $link->campaign_id,
                    'platform' => Attribution::platformOf($link->channel),
                    'url' => $request->fullUrl(),
                    'referrer' => $request->headers->get('referer'),
                    'utm_source' => $link->utm_source,
                    'utm_medium' => $link->utm_medium,
                    'utm_campaign' => $link->utm_campaign,
                    'utm_term' => $link->utm_term,
                    'utm_content' => $link->utm_content,
                    'click_id' => ClickIds::primary($clickIds),
                    'ip_hash' => Visitor::ipHash($request->ip()),
                ] + $agent);

                return $session->session_id;
            });
        } catch (\Throwable $e) {
            Log::warning('tracking_click_failed', ['link' => $link->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
