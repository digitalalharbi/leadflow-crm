<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Leads\LeadClassification;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AutomationRun;
use App\Models\FileShare;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * The product's one promise, as six numbers in order.
 *
 *   arrived → automated → reached → followed up → waiting for the team → closed
 *
 * Every step counts leads, not events, so one lead that was messaged three
 * times is one — otherwise the step after "arrived" could be larger than it and
 * the picture would stop being a funnel. The window is the last N days, and
 * what the team is waiting on is a count of right now, because "waiting" has no
 * period.
 *
 * Scoped like every lead screen: a rep sees the journey of the leads they may
 * see, a manager the tenant's.
 */
class JourneyController extends Controller
{
    public function show(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $user = $request->user();
        $days = min(max((int) $request->input('days', 7), 1), 90);
        $since = now()->subDays($days);

        $visible = fn () => Lead::query()->visibleTo($user)->reorder()->select('leads.id');

        $arrived = Lead::query()->visibleTo($user)->where('created_at', '>=', $since)->count();

        $automated = AutomationRun::query()
            ->where('created_at', '>=', $since)->where('outcome', 'applied')
            ->whereIn('lead_id', $visible())->distinct()->count('lead_id');

        $messaged = LeadMessage::query()
            ->where('direction', 'outbound')->where('created_at', '>=', $since)
            ->whereIn('lead_id', $visible())->distinct()->pluck('lead_id');

        $filed = FileShare::query()
            ->where('created_at', '>=', $since)
            ->whereIn('lead_id', $visible())->distinct()->pluck('lead_id');

        $reached = $messaged->merge($filed)->unique()->count();

        $followed = FollowUp::query()
            ->where('created_at', '>=', $since)
            ->whereIn('lead_id', $visible())->distinct()->count('lead_id');

        // Waiting for the team now: open, and worth a person's time.
        $waiting = Lead::query()->visibleTo($user)->reorder()
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value, LeadStatus::Invalid->value, LeadStatus::NoResponse->value])
            ->whereRaw('('.LeadClassification::sql('leads').") in ('priority', 'hot', 'qualified')")
            ->count();

        $closed = [
            'appointments' => Appointment::query()
                ->where('created_at', '>=', $since)->whereIn('lead_id', $visible())->count(),
            'won' => Lead::query()->visibleTo($user)->where('won_at', '>=', $since)->count(),
        ];

        return ApiResponse::success([
            'days' => $days,
            'steps' => [
                ['key' => 'arrived', 'count' => $arrived],
                ['key' => 'automated', 'count' => $automated],
                ['key' => 'reached', 'count' => $reached],
                ['key' => 'followed', 'count' => $followed],
                ['key' => 'waiting', 'count' => $waiting],
                ['key' => 'closed', 'count' => $closed['appointments'] + $closed['won'], 'appointments' => $closed['appointments'], 'won' => $closed['won']],
            ],
        ]);
    }
}
