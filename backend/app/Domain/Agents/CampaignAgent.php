<?php

namespace App\Domain\Agents;

use App\Domain\Actions\ActionGateway;
use App\Models\ActionProposal;
use App\Models\ClientRule;

/**
 * Turns findings into proposals — and nothing more.
 *
 * It cannot pause anything. It can only ask, through the same gateway a person
 * uses, with the reason and the figures, inside the client's limits: a budget
 * moves by at most the client's allowed percentage and never past the ceiling.
 * It does not propose for campaigns the platform does not know (seen only
 * through their leads) — the gateway would refuse, and advice nobody can act on
 * is noise in an inbox people have to trust.
 */
class CampaignAgent
{
    public function __construct(private ActionGateway $gateway) {}

    /** @param  list<Finding>  $findings
     *  @return list<ActionProposal> */
    public function act(array $findings): array
    {
        $proposals = [];

        foreach ($findings as $finding) {
            $campaign = $finding->campaign;

            if ($campaign->external_id === null || $campaign->origin !== 'sync') {
                continue;
            }

            $rules = ClientRule::for($campaign->project_id);
            $pct = max(1, (int) ($rules->max_budget_change_pct ?: 20));
            $f = $finding->figures;

            $proposal = match (true) {
                $finding->key === 'no_results' && $campaign->status === 'active' => [
                    'action' => 'pause',
                    'reason' => sprintf('صرفت %s خلال 7 أيام وجلبت %d عميلًا دون أي عميل مؤهل.', $this->money($f['spend']), $f['leads']),
                    'expected_impact' => 'يوقف صرفًا لا يأتي بعملاء حقيقيين.',
                ],
                $finding->key === 'over_target' && $campaign->status === 'active' && $campaign->daily_budget_minor => [
                    'action' => 'update_budget',
                    'payload' => ['daily_budget_minor' => (int) floor($campaign->daily_budget_minor * (1 - $pct / 100))],
                    'reason' => sprintf('تكلفة العميل المؤهل %s مقابل هدف %s.', $this->money($f['cpql']), $this->money($f['target_cpql'])),
                    'expected_impact' => sprintf('خفض الميزانية %d%% حتى تقترب التكلفة من الهدف.', $pct),
                ],
                $finding->key === 'outperforming' && $campaign->status === 'active' && $campaign->daily_budget_minor => [
                    'action' => 'update_budget',
                    'payload' => ['daily_budget_minor' => $this->raise($campaign->daily_budget_minor, $pct, $rules->daily_budget_ceiling_minor)],
                    'reason' => sprintf('تكلفة العميل المؤهل %s أقل من الهدف %s مع %d مؤهلين.', $this->money($f['cpql']), $this->money($f['target_cpql']), $f['qualified']),
                    'expected_impact' => sprintf('رفع الميزانية %d%% لجلب مؤهلين أكثر بتكلفة ضمن الهدف.', $pct),
                ],
                default => null,
            };

            if ($proposal === null || ($proposal['action'] === 'update_budget' && $proposal['payload']['daily_budget_minor'] === $campaign->daily_budget_minor)) {
                continue;
            }

            $proposals[] = $this->gateway->propose($proposal + [
                'platform' => $campaign->platform,
                'campaign_id' => $campaign->id,
                'project_id' => $campaign->project_id,
            ], null, 'campaign');
        }

        return $proposals;
    }

    private function raise(int $current, int $pct, ?int $ceiling): int
    {
        $next = (int) floor($current * (1 + $pct / 100));

        return $ceiling ? min($next, $ceiling) : $next;
    }

    private function money(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 0);
    }
}
