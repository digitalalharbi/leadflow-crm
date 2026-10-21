<?php

namespace App\Domain\Partners;

use App\Domain\Analytics\LeadFunnel;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\PartnerCommissionPayment;
use Carbon\CarbonInterface;

/**
 * What a partner is owed, what has been paid, and what is left.
 *
 * The terms are the partner's: a percentage of what each won deal brought in, or a fixed amount per
 * won deal. Due is worked out from the deals themselves (every lead credited to the partner that was
 * won), paid is the sum of the payments that were recorded, and the balance is the difference — so
 * the number somebody is told is the number the deals and the payments add up to, and changing a
 * deal's value or recording a payment moves it. A partner with no terms agreed has no commission
 * (null), not a commission of zero.
 */
class CommissionLedger
{
    /** @return array{type:string,percent:?float,fixed:?float,agreed:bool} */
    public static function terms(Partner $partner): array
    {
        $type = $partner->commission_type ?: 'percentage';

        return [
            'type' => $type,
            'percent' => $partner->commission_percent !== null ? (float) $partner->commission_percent : null,
            'fixed' => $partner->commission_fixed_minor !== null ? round($partner->commission_fixed_minor / 100, 2) : null,
            'agreed' => $type === 'fixed' ? $partner->commission_fixed_minor !== null : $partner->commission_percent !== null,
        ];
    }

    /** What the terms make of this many won deals worth this much (minor units), or null when none were agreed. */
    public static function due(Partner $partner, int $deals, int $revenueMinor): ?int
    {
        $terms = self::terms($partner);

        if (! $terms['agreed']) {
            return null;
        }

        return $terms['type'] === 'fixed'
            ? $deals * (int) $partner->commission_fixed_minor
            : (int) round($revenueMinor * (float) $partner->commission_percent / 100);
    }

    /**
     * Everything, all time, per partner.
     *
     * @param  list<int>  $partnerIds
     * @return array<int,array{due:?float,paid:float,balance:?float,deals:int,revenue:float}>
     */
    public function for(array $partnerIds): array
    {
        if ($partnerIds === []) {
            return [];
        }

        $won = Lead::query()->reorder()->whereIn('leads.partner_id', $partnerIds)->whereRaw(LeadFunnel::stages()['won'])
            ->selectRaw('leads.partner_id, count(*) as deals, coalesce(sum(leads.deal_value_minor), 0) as revenue_minor')
            ->groupBy('leads.partner_id')->get()->keyBy('partner_id');
        $paid = PartnerCommissionPayment::query()->whereIn('partner_id', $partnerIds)->selectRaw('partner_id, sum(amount_minor) as total')->groupBy('partner_id')->pluck('total', 'partner_id');
        $partners = Partner::withTrashed()->whereIn('id', $partnerIds)->get()->keyBy('id');

        $out = [];

        foreach ($partnerIds as $id) {
            $deals = (int) ($won[$id]->deals ?? 0);
            $revenue = (int) ($won[$id]->revenue_minor ?? 0);
            $due = $partners->has($id) ? self::due($partners[$id], $deals, $revenue) : null;
            $paidMinor = (int) ($paid[$id] ?? 0);

            $out[$id] = [
                'due' => $due !== null ? round($due / 100, 2) : null,
                'paid' => round($paidMinor / 100, 2),
                'balance' => $due !== null ? round(($due - $paidMinor) / 100, 2) : null,
                'deals' => $deals,
                'revenue' => round($revenue / 100, 2),
            ];
        }

        return $out;
    }

    /**
     * The won deals behind the number, newest first: what each brought in and what it earns the partner.
     *
     * @return list<array{lead:string,won_at:?string,value:float,commission:?float}>
     */
    public function deals(Partner $partner, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        return Lead::query()->reorder()->where('leads.partner_id', $partner->id)->whereRaw(LeadFunnel::stages()['won'])
            ->when($from, fn ($q) => $q->where('leads.won_at', '>=', $from))->when($to, fn ($q) => $q->where('leads.won_at', '<=', $to))
            ->orderByDesc('leads.won_at')->limit(200)->get(['id', 'uuid', 'full_name', 'won_at', 'deal_value_minor'])
            ->map(fn (Lead $l) => [
                'lead' => $l->full_name, 'lead_id' => $l->uuid, 'won_at' => $l->won_at?->toIso8601String(),
                'value' => round((int) $l->deal_value_minor / 100, 2),
                'commission' => ($c = self::due($partner, 1, (int) $l->deal_value_minor)) !== null ? round($c / 100, 2) : null,
            ])->all();
    }
}
