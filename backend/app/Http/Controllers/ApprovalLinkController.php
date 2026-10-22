<?php

namespace App\Http\Controllers;

use App\Domain\Actions\ActionGateway;
use App\Domain\Actions\ActionLabels;
use App\Models\ActionProposal;
use App\Models\ApprovalToken;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The page an approval link opens.
 *
 * GET shows the proposal and two buttons; it never decides anything, so a link
 * preview, a security scanner or an accidental tap cannot approve a budget.
 * POST decides — once: the token is spent inside the same transaction that
 * checks it, and the decision is made as the person the link was sent to.
 */
class ApprovalLinkController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function show(string $token)
    {
        [$row, $error] = $this->resolve($token);

        return response()->view('approval', $this->viewData($row, $error));
    }

    public function decide(Request $request, string $token, ActionGateway $gateway, AuditLogger $audit)
    {
        $decision = $request->input('decision');
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422);

        [$row, $error] = $this->resolve($token);

        if ($error !== null) {
            return response()->view('approval', $this->viewData($row, $error), 410);
        }

        return $this->context->withTenant($row->proposal->tenant, function () use ($row, $decision, $gateway, $audit) {
            $spent = DB::transaction(fn () => ApprovalToken::query()->whereKey($row->id)->whereNull('used_at')
                ->update(['used_at' => now(), 'used_decision' => $decision]));

            if ($spent !== 1) {
                return response()->view('approval', $this->viewData($row, 'used'), 410);
            }

            $proposal = ActionProposal::query()->findOrFail($row->action_proposal_id);

            try {
                $proposal = $decision === 'approve'
                    ? $gateway->approve($proposal, $row->user, 'WhatsApp')
                    : $gateway->reject($proposal, $row->user, 'WhatsApp');
            } catch (InvalidArgumentException) {
                return response()->view('approval', $this->viewData($row, 'decided'), 409);
            }

            $audit->log('action_proposal.decided_by_link', $row->user, $proposal, ['decision' => $decision, 'channel' => 'whatsapp']);

            return response()->view('approval', $this->viewData($row->fresh(), null, $proposal->fresh()));
        });
    }

    /** @return array{0: ?ApprovalToken, 1: ?string} */
    private function resolve(string $token): array
    {
        $row = $this->context->withoutScope(fn () => ApprovalToken::query()->with(['proposal.campaign', 'proposal.tenant', 'user'])
            ->where('token_hash', ApprovalToken::hash($token))->first());

        return match (true) {
            $row === null => [null, 'unknown'],
            $row->used_at !== null => [$row, 'used'],
            $row->expires_at->isPast() => [$row, 'expired'],
            $row->proposal->status !== ActionProposal::PENDING => [$row, 'decided'],
            default => [$row, null],
        };
    }

    private function viewData(?ApprovalToken $row, ?string $error, ?ActionProposal $result = null): array
    {
        $p = $result ?? $row?->proposal;

        return [
            'error' => $error,
            'result' => $result?->status,
            'action' => $p ? ActionLabels::ar($p->action) : null,
            'campaign' => $p?->campaign?->name,
            'reason' => $p?->reason,
            'impact' => $p?->expected_impact,
            'blocks' => $p?->policy['blocks'] ?? [],
            'payload' => $p?->payload ?? [],
            'person' => $row?->user?->name,
        ];
    }
}
