<?php

namespace App\Domain\Actions;

use App\Models\ActionProposal;

/** One shape for a proposal, wherever it is shown — the inbox, a chat reply, a WhatsApp page. */
final class ProposalPresenter
{
    public static function present(ActionProposal $p): array
    {
        $p->loadMissing(['campaign', 'adSet', 'ad', 'proposer', 'decider', 'log']);

        return [
            'id' => $p->uuid,
            'action' => $p->action,
            'platform' => $p->platform,
            'status' => $p->status,
            'payload' => $p->payload ?? [],
            'reason' => $p->reason,
            'expected_impact' => $p->expected_impact,
            'proposed_by' => $p->proposed_by,
            'agent' => $p->agent,
            'proposer' => $p->proposer?->name,
            'decider' => $p->decider?->name,
            'decided_at' => $p->decided_at?->toIso8601String(),
            'decision_note' => $p->decision_note,
            'policy' => $p->policy ?? ['allowed' => true, 'blocks' => [], 'warnings' => [], 'limits' => []],
            'error' => $p->error,
            'executed_at' => $p->executed_at?->toIso8601String(),
            // Carried out against the stand-in, not the platform: recorded, never sent.
            'sandbox' => $p->log->contains(fn ($entry) => ($entry->response['sandbox'] ?? false) === true),
            'created_at' => $p->created_at?->toIso8601String(),
            'campaign' => $p->campaign ? ['id' => $p->campaign->uuid, 'name' => $p->campaign->name, 'daily_budget_minor' => $p->campaign->daily_budget_minor] : null,
            'ad_set' => $p->adSet?->name,
            'ad' => $p->ad?->name,
        ];
    }
}
