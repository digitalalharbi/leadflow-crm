<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CampaignSpend;
use App\Models\Lead;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What the ads cost, typed in by whoever can see the ad account.
 *
 * The campaign reports have always counted leads, qualified leads and sales per
 * campaign and never known what any of it cost — so "which campaign is worth
 * scaling" could only be answered by volume, which is the question that
 * flatters the cheapest traffic.
 *
 * Deliberately not waiting for ad-platform credentials. A manager with the
 * numbers in front of them can enter a week of spend in a minute, and the same
 * rows are what an insights sync would write later.
 */
class CampaignSpendController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(Request $request)
    {
        // Spend has no project of its own: it is the whole company's.
        abort_if($request->user()->isProjectScoped(), 403, 'Your role does not include this.');
        $this->authorize('export', Lead::class);

        $rows = CampaignSpend::query()
            ->when($request->date('date_from'), fn ($q, $d) => $q->where('spend_date', '>=', $d))
            ->when($request->date('date_to'), fn ($q, $d) => $q->where('spend_date', '<=', $d))
            ->orderByDesc('spend_date')
            ->limit(500)
            ->get();

        return ApiResponse::success([
            'spends' => $rows->map(fn (CampaignSpend $s) => [
                'id' => $s->id,
                'platform' => $s->platform,
                'campaign_name' => $s->campaign_name,
                'spend_date' => $s->spend_date->toDateString(),
                'amount' => $s->amount(),
                'currency' => $s->currency,
                'source' => $s->source,
            ]),
            'currency' => $this->currency(),
        ]);
    }

    /**
     * Upsert a day's spend, one row or many.
     *
     * Entering the same day twice corrects it rather than doubling it — which
     * is what somebody re-uploading last week's figures after a correction
     * expects, and the opposite of what an append-only log would do.
     */
    public function store(Request $request)
    {
        // Spend has no project of its own: it is the whole company's.
        abort_if($request->user()->isProjectScoped(), 403, 'Your role does not include this.');
        $this->authorize('export', Lead::class);

        $data = $request->validate([
            'spends' => ['required', 'array', 'min:1', 'max:500'],
            'spends.*.platform' => ['required', 'string', 'max:64'],
            'spends.*.campaign_name' => ['required', 'string', 'max:255'],
            'spends.*.spend_date' => ['required', 'date'],
            // In major units, because that is what the ad account shows and
            // what a person types. The minor conversion happens here, once.
            'spends.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $currency = $this->currency();
        $now = now();

        $rows = array_map(fn (array $row) => [
            'tenant_id' => $this->context->id(),
            'platform' => $row['platform'],
            'campaign_name' => $row['campaign_name'],
            'spend_date' => $row['spend_date'],
            'amount_minor' => (int) round($row['amount'] * 100),
            'currency' => $currency,
            'source' => 'manual',
            'created_by' => $request->user()->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $data['spends']);

        DB::table('campaign_spends')->upsert(
            $rows,
            ['tenant_id', 'platform', 'campaign_name', 'spend_date'],
            ['amount_minor', 'currency', 'source', 'created_by', 'updated_at'],
        );

        app(AuditLogger::class)->log('campaign_spend.saved', $request->user(), null, ['count' => count($rows), 'total' => round(array_sum(array_column($data['spends'], 'amount')), 2), 'campaigns' => array_values(array_unique(array_column($data['spends'], 'campaign_name')))]);

        return ApiResponse::success(['saved' => count($rows)]);
    }

    public function destroy(Request $request, int $id)
    {
        // Spend has no project of its own: it is the whole company's.
        abort_if($request->user()->isProjectScoped(), 403, 'Your role does not include this.');
        $this->authorize('export', Lead::class);

        $spend = CampaignSpend::whereKey($id)->firstOrFail();
        app(AuditLogger::class)->log('campaign_spend.deleted', $request->user(), $spend, ['before' => ['campaign' => $spend->campaign_name, 'date' => $spend->spend_date?->toDateString(), 'amount' => $spend->amount()]]);
        $spend->delete();

        return ApiResponse::success(null, 'Removed.');
    }

    /** The workspace's own currency; spend is never stored in a guessed one. */
    private function currency(): string
    {
        return $this->context->get()?->settings?->currency ?? 'SAR';
    }
}
