<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Calendar\IcsCalendar;
use App\Domain\Exports\ActingScope;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Policies\LeadPolicy;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Visits in a calendar: one as a file, or all of a person's through a private feed their phone subscribes to.
 */
class CalendarController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** One visit as an .ics file, for anyone who may see its lead. */
    public function appointment(Request $request, string $uuid)
    {
        $appointment = Appointment::with(['lead:id,uuid,full_name,assigned_to,tenant_id', 'project:id,name'])->where('uuid', $uuid)->firstOrFail();
        // The policy itself, not the gate (which passes every permission-table role): only who may open the lead.
        abort_unless($appointment->lead !== null && app(LeadPolicy::class)->view($request->user(), $appointment->lead), 403);

        return response(IcsCalendar::render([$appointment], 'Mohtam', (string) config('integrations.app_url')), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="visit-'.$appointment->uuid.'.ics"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Whether the person has a feed; the address itself is only shown when it is made. */
    public function feed(Request $request)
    {
        $user = $request->user();

        return ApiResponse::success([
            'enabled' => $user->calendar_token_hash !== null,
            'last_fetched_at' => $user->calendar_feed_fetched_at?->toIso8601String(),
        ]);
    }

    /** A new private address; the old one stops working at once. */
    public function rotate(Request $request)
    {
        $user = $request->user();
        $token = Str::random(48);
        $user->forceFill(['calendar_token_hash' => hash('sha256', $token), 'calendar_feed_fetched_at' => null])->save();
        $this->audit->log('calendar_feed.rotated', $user, $user);

        return ApiResponse::success([
            'enabled' => true,
            'url' => url('/api/v1/public/calendar/'.$token.'.ics'),
        ]);
    }

    public function disable(Request $request)
    {
        $user = $request->user();
        $user->forceFill(['calendar_token_hash' => null, 'calendar_feed_fetched_at' => null])->save();
        $this->audit->log('calendar_feed.disabled', $user, $user);

        return ApiResponse::success(['enabled' => false]);
    }

    /**
     * The feed itself, read by a calendar app with no session: the secret in the address is the credential. The
     * person's own visits — or every visit, for someone who sees all leads — from a month back to six months ahead.
     */
    public function publicFeed(string $token, TenantContext $context, ActingScope $scope)
    {
        $user = strlen($token) === 48
            ? $context->withoutScope(fn () => User::where('calendar_token_hash', hash('sha256', $token))->where('is_active', true)->first())
            : null;

        abort_if($user === null || $user->tenant === null || ! $user->tenant->is_active, 404);

        return $context->withTenant($user->tenant, function () use ($user, $scope) {
            /*
             * This route has no session and so none of the company or project limits the person's screens run
             * under; without them the scopes on visits and leads let everything through. The same middleware is
             * replayed for them here, so the feed shows exactly the visits their own screen would.
             */
            $appointments = $scope->run($user, null, fn () => Appointment::with(['lead:id,uuid,full_name', 'project:id,name'])
                ->whereNotNull('scheduled_at')
                ->whereBetween('scheduled_at', [now()->subDays(30), now()->addDays(180)])
                ->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))
                ->whereHas('lead')
                ->orderBy('scheduled_at')->limit(1000)->get());

            $user->forceFill(['calendar_feed_fetched_at' => now()])->saveQuietly();

            return response(IcsCalendar::render($appointments, 'Mohtam — '.$user->name, (string) config('integrations.app_url')), 200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Cache-Control' => 'private, max-age=900',
                'X-Robots-Tag' => 'noindex',
            ]);
        });
    }
}
