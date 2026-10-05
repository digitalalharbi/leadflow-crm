<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Lead;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * What is quietly wrong with the data a workspace is paying to collect.
 *
 * Every number here costs money that has already been spent. A lead with an
 * unreachable phone is a click somebody paid for and nobody can call. A lead
 * with no campaign is a click whose source can never be credited, so the
 * campaign that produced it cannot be scaled and the one that wasted the budget
 * cannot be stopped. None of this shows up as an error anywhere — the lead
 * arrives, the list gets longer, and the reports quietly get less true.
 *
 * Each check carries the filter that opens the leads behind it. A dashboard
 * number nobody can act on is a number people learn to scroll past.
 */
class DataQualityController extends Controller
{
    /** A lead nobody has touched for this long has gone cold on its own. */
    private const STALE_DAYS = 14;

    public function __invoke(Request $request)
    {
        // The same authority as the reports: these counts span the workspace,
        // and a rep seeing only their own slice would read it as the whole.
        $this->authorize('export', Lead::class);

        $checks = [
            $this->unreachablePhones(),
            $this->duplicates(),
            $this->missingAttribution(),
            $this->unassigned(),
            $this->stale(),
            $this->missingCampaign(),
        ];

        return ApiResponse::success([
            'checks' => $checks,
            'total_issues' => array_sum(array_column($checks, 'count')),
            'integrations' => $this->integrationHealth(),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * A phone that normalisation could not make sense of.
     *
     * The ingestion pipeline normalises every number it can; what is left is
     * what no Saudi format could be read out of — a typo, a landline where a
     * mobile was asked for, or a form field somebody wrote their name into.
     */
    private function unreachablePhones(): array
    {
        $count = Lead::query()
            ->whereNull('phone_normalized')
            ->whereNotIn('status', [LeadStatus::Invalid->value])
            ->count();

        return $this->check('unreachable_phones', $count, ['data_issue' => 'unreachable_phones']);
    }

    /**
     * The same person, arriving twice — by email.
     *
     * Not by phone: `leads_tenant_phone_unique` makes a duplicate phone
     * impossible to insert, so a check for one would report zero forever and
     * quietly imply the workspace had been checked for something it had not.
     * Email carries no such constraint, and a buyer who filled two forms with
     * one address under two numbers is a real pair of records somebody has to
     * merge.
     *
     * Counted as every row caught up in a duplicate group, not the surplus:
     * the list behind this number opens all of them, because you cannot merge
     * two records while looking at one. The count and the list have to be the
     * same rows — a number whose list disagrees with it is worse than no
     * number, since somebody will trust one of the two with no way to tell
     * which.
     */
    private function duplicates(): array
    {
        // Two scoped counts rather than one raw query: the global tenant scope
        // applies to Eloquent and not to a hand-built DB::table(), and this
        // number must never include another workspace's rows.
        $involved = Lead::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereIn('email', function ($sub) {
                $sub->from('leads')
                    ->select('email')
                    ->whereNull('deleted_at')
                    ->whereNotNull('email')
                    ->groupBy('tenant_id', 'email')
                    ->havingRaw('count(*) > 1');
            })
            ->count();

        return $this->check('duplicates', $involved, ['data_issue' => 'duplicates']);
    }

    /** No platform and no source: the spend behind it can never be credited. */
    private function missingAttribution(): array
    {
        $count = Lead::query()
            ->whereNull('platform')
            ->whereNull('source')
            ->count();

        return $this->check('missing_attribution', $count, ['data_issue' => 'missing_attribution']);
    }

    private function unassigned(): array
    {
        $count = Lead::query()
            ->whereNull('assigned_to')
            ->whereIn('status', LeadStatus::openValues())
            ->count();

        return $this->check('unassigned', $count, ['data_issue' => 'unassigned']);
    }

    /** Open, assigned, and untouched for a fortnight. */
    private function stale(): array
    {
        $cutoff = now()->subDays(self::STALE_DAYS);

        $count = Lead::query()
            ->whereIn('status', LeadStatus::openValues())
            ->where(fn ($q) => $q->whereNull('last_contact_at')->orWhere('last_contact_at', '<', $cutoff))
            ->where('created_at', '<', $cutoff)
            ->count();

        return $this->check('stale', $count, ['data_issue' => 'stale']);
    }

    /**
     * Came from a paid platform but carries no campaign.
     *
     * Narrower than "missing attribution" on purpose: these are the expensive
     * ones. The platform is known, so the money is traceable in the ad account
     * — but not to anything in here, which is where the ROAS report stops being
     * able to tell the truth.
     */
    private function missingCampaign(): array
    {
        $count = Lead::query()
            ->whereNotNull('platform')
            ->whereNull('campaign_name')
            ->whereNull('campaign_id')
            ->count();

        return $this->check('missing_campaign', $count, ['data_issue' => 'missing_campaign']);
    }

    /**
     * Connections that have stopped working.
     *
     * A broken integration does not announce itself: leads simply stop
     * arriving, and a quiet morning looks exactly like a slow one.
     */
    private function integrationHealth(): array
    {
        return Integration::query()
            ->get()
            ->map(fn (Integration $integration) => [
                'id' => $integration->uuid,
                'provider' => $integration->provider,
                'name' => $integration->name,
                'status' => $integration->status,
                'healthy' => $integration->status === Integration::CONNECTED,
                'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
                'last_error' => $integration->last_error,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $filter  what to send the leads list, so the
     *                                         number opens the work behind it
     */
    private function check(string $key, int $count, array $filter): array
    {
        return ['key' => $key, 'count' => $count, 'filter' => $filter];
    }
}
