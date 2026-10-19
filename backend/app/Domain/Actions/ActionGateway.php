<?php

namespace App\Domain\Actions;

use App\Domain\Campaigns\AdPlatformRegistry;
use App\Jobs\ExecuteActionProposal;
use App\Jobs\NotifyProposalApprovers;
use App\Models\ActionLogEntry;
use App\Models\ActionProposal;
use App\Models\AdConnection;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\ClientRule;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Agent suggests → proposal (why, what it should do) → a person approves,
 * rejects or edits → it runs → it is logged.
 *
 * The only path to a write on an ad account. Agents and screens call propose();
 * nothing else calls an adapter's execute().
 */
class ActionGateway
{
    public function __construct(
        private ActionPolicy $policy,
        private AdPlatformRegistry $registry,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{action: string, platform: string, campaign_id?: ?int, ad_set_id?: ?int, ad_id?: ?int, project_id?: ?int, payload?: array, reason: string, expected_impact?: ?string}  $data
     */
    public function propose(array $data, ?User $user = null, ?string $agent = null): ActionProposal
    {
        if (trim((string) ($data['reason'] ?? '')) === '') {
            throw new InvalidArgumentException('A proposal needs a reason.');
        }

        $payload = $data['payload'] ?? [];

        if ($data['action'] === 'create_campaign') {
            $payload['status'] = 'paused';
        }

        // The same change, on the same thing, on the same day, is one proposal —
        // an hourly routine must not file the same advice twenty-four times.
        $key = sha1(implode('|', [
            $data['action'], $data['platform'], $data['campaign_id'] ?? '-', $data['ad_set_id'] ?? '-', $data['ad_id'] ?? '-',
            json_encode($payload), now()->toDateString(),
        ]));

        $existing = ActionProposal::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        $proposal = new ActionProposal([
            'project_id' => $data['project_id'] ?? null,
            'campaign_id' => $data['campaign_id'] ?? null,
            'ad_set_id' => $data['ad_set_id'] ?? null,
            'ad_id' => $data['ad_id'] ?? null,
            'platform' => $data['platform'],
            'action' => $data['action'],
            'payload' => $payload,
            'reason' => $data['reason'],
            'expected_impact' => $data['expected_impact'] ?? null,
            'proposed_by' => $agent ? 'agent' : 'user',
            'agent' => $agent,
            'proposed_by_user_id' => $user?->id,
            'idempotency_key' => $key,
        ]);

        $verdict = $this->verdict($proposal);
        $proposal->fill(['policy' => $verdict, 'status' => $verdict['allowed'] ? ActionProposal::PENDING : ActionProposal::BLOCKED])->save();

        $this->audit->log('action_proposal.created', $user, $proposal, ['action' => $proposal->action, 'agent' => $agent]);

        // An agent's proposal also goes to the approvers' WhatsApp; a person's
        // own proposal does not — they are already looking at it.
        if ($agent !== null && $proposal->status === ActionProposal::PENDING) {
            NotifyProposalApprovers::dispatch($proposal->id)->afterCommit();
        }

        return $proposal;
    }

    /** A person changes what is proposed, before deciding. The policy is asked again. */
    public function edit(ActionProposal $proposal, User $user, array $payload): ActionProposal
    {
        $this->mustBe($proposal, [ActionProposal::PENDING, ActionProposal::BLOCKED]);

        $proposal->payload = $payload;
        $verdict = $this->verdict($proposal);
        $proposal->fill(['policy' => $verdict, 'status' => $verdict['allowed'] ? ActionProposal::PENDING : ActionProposal::BLOCKED])->save();

        $this->audit->log('action_proposal.edited', $user, $proposal, ['payload' => $payload]);

        return $proposal;
    }

    public function reject(ActionProposal $proposal, User $user, ?string $note = null): ActionProposal
    {
        $this->mustBe($proposal, [ActionProposal::PENDING, ActionProposal::BLOCKED]);

        $proposal->fill(['status' => ActionProposal::REJECTED, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->log('action_proposal.rejected', $user, $proposal, ['note' => $note]);

        return $proposal;
    }

    public function approve(ActionProposal $proposal, User $user, ?string $note = null): ActionProposal
    {
        $this->mustBe($proposal, [ActionProposal::PENDING]);

        $verdict = $this->verdict($proposal, executing: true);

        if (! $verdict['allowed']) {
            $proposal->fill(['policy' => $verdict, 'status' => ActionProposal::BLOCKED])->save();

            return $proposal;
        }

        $proposal->fill(['status' => ActionProposal::APPROVED, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->log('action_proposal.approved', $user, $proposal, ['action' => $proposal->action]);

        ExecuteActionProposal::dispatch($proposal->id, $user->id);

        return $proposal->fresh();
    }

    /**
     * Run an approved proposal, exactly once.
     *
     * The row is locked and its status checked inside the lock, so a retried job,
     * a double click or two workers can never send the same change twice.
     */
    public function execute(int $proposalId, ?int $userId = null): void
    {
        $proposal = DB::transaction(function () use ($proposalId) {
            $row = ActionProposal::query()->whereKey($proposalId)->lockForUpdate()->first();

            if ($row === null || $row->status !== ActionProposal::APPROVED) {
                return null;
            }

            $row->update(['status' => ActionProposal::EXECUTING]);

            return $row;
        });

        if ($proposal === null) {
            return;
        }

        $adapter = $this->registry->get($proposal->platform);
        $connection = AdConnection::for($proposal->platform);
        $verdict = $this->verdict($proposal, executing: true);

        if (! $verdict['allowed']) {
            $proposal->update(['status' => ActionProposal::BLOCKED, 'policy' => $verdict]);

            return;
        }

        $started = microtime(true);
        $result = $adapter->execute($connection, $proposal->action, $this->policy->target($proposal), $proposal->payload ?? []);

        ActionLogEntry::create([
            'action_proposal_id' => $proposal->id,
            'platform' => $proposal->platform,
            'action' => $proposal->action,
            'request' => $result['request'],
            'response' => ($result['response'] ?? []) + ['created_external_id' => $result['created_external_id'] ?? null],
            'ok' => $result['ok'],
            'executed_by' => $userId,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        if (! $result['ok']) {
            $proposal->update(['status' => ActionProposal::FAILED, 'error' => mb_substr((string) ($result['error'] ?? 'failed'), 0, 500)]);

            return;
        }

        $proposal->update(['status' => ActionProposal::EXECUTED, 'executed_at' => now(), 'error' => null]);
        $this->applyLocally($proposal, $result['created_external_id'] ?? null);
    }

    /** The platform said yes, so our copy says the same thing without waiting for the next sync. */
    private function applyLocally(ActionProposal $proposal, ?string $created): void
    {
        $entity = $proposal->ad ?? $proposal->adSet ?? $proposal->campaign;
        $payload = $proposal->payload ?? [];

        match ($proposal->action) {
            'pause' => $entity?->update(['status' => 'paused']),
            'resume' => $entity?->update(['status' => 'active']),
            'update_budget' => $entity && ! $proposal->ad ? $entity->update(['daily_budget_minor' => (int) $payload['daily_budget_minor']]) : null,
            'update_targeting' => $proposal->adSet?->update(['targeting' => $payload['targeting'] ?? null]),
            // What the platform created, on our side at once and paused — the
            // next sync fills in the rest.
            'create_campaign' => $created ? Campaign::query()->firstOrCreate(
                ['platform' => $proposal->platform, 'external_id' => $created],
                ['name' => (string) ($payload['name'] ?? $created), 'origin' => Campaign::ORIGIN_SYNC, 'status' => 'paused', 'project_id' => $proposal->project_id],
            ) : null,
            'duplicate_ad_set' => $created && $proposal->adSet ? AdSet::query()->firstOrCreate(
                ['campaign_id' => $proposal->adSet->campaign_id, 'external_id' => $created],
                ['name' => $proposal->adSet->name.' (copy)', 'status' => 'paused'],
            ) : null,
            default => null,
        };
    }

    private function verdict(ActionProposal $proposal, bool $executing = false): array
    {
        $projectId = $proposal->project_id ?? $proposal->campaign?->project_id;

        return $this->policy->check(
            $proposal,
            ClientRule::for($projectId),
            $this->registry->get($proposal->platform),
            AdConnection::for($proposal->platform),
            $executing,
        );
    }

    private function mustBe(ActionProposal $proposal, array $statuses): void
    {
        if (! in_array($proposal->status, $statuses, true)) {
            throw new InvalidArgumentException("This proposal is {$proposal->status} and can no longer be changed.");
        }
    }
}
