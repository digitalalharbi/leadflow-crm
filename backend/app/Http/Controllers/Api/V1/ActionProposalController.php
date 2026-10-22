<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Actions\ActionGateway;
use App\Domain\Actions\ActionPolicy;
use App\Domain\Actions\ProposalPresenter;
use App\Domain\Campaigns\AdPlatform;
use App\Http\Controllers\Controller;
use App\Models\ActionProposal;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\Lead;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The approval inbox: what agents and people want changed on the ad accounts,
 * and the decision on each.
 */
class ActionProposalController extends Controller
{
    public function __construct(private ActionGateway $gateway) {}

    public function index(Request $request)
    {
        $this->authorize('export', Lead::class);

        $proposals = ActionProposal::query()
            ->with(['campaign:id,uuid,name,platform', 'adSet:id,uuid,name', 'ad:id,uuid,name', 'proposer:id,name', 'decider:id,name', 'log'])
            ->when($request->input('status'), fn ($q, $s) => $q->whereIn('status', (array) $s))
            ->when($request->input('campaign_id'), fn ($q, $uuid) => $q->whereHas('campaign', fn ($c) => $c->where('uuid', $uuid)))
            ->latest('id')
            ->limit(100)
            ->get();

        return ApiResponse::success([
            'proposals' => $proposals->map(fn (ActionProposal $p) => $this->present($p))->all(),
            'counts' => ActionProposal::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    /** A person proposes a change — it still waits for approval like an agent's would. */
    public function store(Request $request)
    {
        $this->authorize('export', Lead::class);

        $data = $request->validate([
            'action' => ['required', Rule::in(ActionPolicy::ACTIONS)],
            'campaign_id' => ['nullable', 'uuid'],
            'ad_set_id' => ['nullable', 'uuid'],
            'ad_id' => ['nullable', 'uuid'],
            'platform' => ['nullable', Rule::in(AdPlatform::values())],
            'payload' => ['nullable', 'array'],
            'payload.daily_budget_minor' => ['nullable', 'integer', 'min:1'],
            // Every key the vocabulary uses is named, so nothing unvalidated rides along.
            'payload.name' => ['nullable', 'string', 'max:255'],
            'payload.headline' => ['nullable', 'string', 'max:500'],
            'payload.body' => ['nullable', 'string', 'max:5000'],
            'payload.primary_text' => ['nullable', 'string', 'max:5000'],
            'payload.status' => ['nullable', 'string', 'in:paused,active'],
            'payload.starts_at' => ['nullable', 'date'],
            'payload.ends_at' => ['nullable', 'date'],
            'payload.objective' => ['nullable', 'string', 'max:64'],
            'payload.channel' => ['nullable', 'string', 'max:32'],
            'payload.creative_id' => ['nullable', 'string', 'max:64'],
            'payload.funding_instrument_id' => ['nullable', 'string', 'max:64'],
            'payload.targeting' => ['nullable', 'array'],
            'account_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
            'expected_impact' => ['nullable', 'string', 'max:2000'],
        ]);

        $campaign = isset($data['campaign_id']) ? Campaign::query()->where('uuid', $data['campaign_id'])->firstOrFail() : null;
        $adSet = isset($data['ad_set_id']) ? AdSet::query()->where('uuid', $data['ad_set_id'])->firstOrFail() : null;
        $ad = isset($data['ad_id']) ? Ad::query()->where('uuid', $data['ad_id'])->firstOrFail() : null;
        $campaign ??= $adSet?->campaign ?? $ad?->campaign;

        // A new campaign lives in an ad account, not under another campaign.
        $account = isset($data['account_id']) ? AdAccount::query()->where('uuid', $data['account_id'])->firstOrFail() : null;
        $platform = $data['platform'] ?? $campaign?->platform ?? $account?->platform;
        abort_if($platform === null, 422, 'A platform or a campaign is required.');

        if ($account) {
            $data['payload'] = ($data['payload'] ?? []) + ['account_external_id' => $account->external_id];
        }

        $proposal = $this->gateway->propose([
            'action' => $data['action'],
            'platform' => $platform,
            'campaign_id' => $campaign?->id,
            'ad_set_id' => $adSet?->id,
            'ad_id' => $ad?->id,
            'project_id' => $campaign?->project_id,
            'payload' => $data['payload'] ?? [],
            'reason' => $data['reason'],
            'expected_impact' => $data['expected_impact'] ?? null,
        ], $request->user());

        return ApiResponse::created(['proposal' => $this->present($proposal->fresh(['campaign', 'adSet', 'ad', 'proposer', 'decider']))]);
    }

    public function update(Request $request, string $uuid)
    {
        $this->authorize('export', Lead::class);
        $data = $request->validate([
            'payload' => ['required', 'array'],
            'payload.daily_budget_minor' => ['nullable', 'integer', 'min:1'],
            'payload.name' => ['nullable', 'string', 'max:255'],
            'payload.headline' => ['nullable', 'string', 'max:500'],
            'payload.body' => ['nullable', 'string', 'max:5000'],
            'payload.primary_text' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->decide(fn ($p) => $this->gateway->edit($p, $request->user(), $data['payload']), $uuid);
    }

    public function approve(Request $request, string $uuid)
    {
        $this->authorize('export', Lead::class);

        $response = $this->decide(fn ($p) => $this->gateway->approve($p, $request->user(), $request->input('note')), $uuid);
        $this->decided('campaign.proposal_approved', $request, $uuid, $response);

        return $response;
    }

    public function reject(Request $request, string $uuid)
    {
        $this->authorize('export', Lead::class);

        $response = $this->decide(fn ($p) => $this->gateway->reject($p, $request->user(), $request->input('note')), $uuid);
        $this->decided('campaign.proposal_rejected', $request, $uuid, $response);

        return $response;
    }

    /** A change to an ad account is only ever made by a person saying yes: who said it, to what, and when. */
    private function decided(string $action, Request $request, string $uuid, $response): void
    {
        if ($response->getStatusCode() >= 400) {
            return;
        }

        $proposal = ActionProposal::query()->where('uuid', $uuid)->first();
        app(AuditLogger::class)->log($action, $request->user(), $proposal, ['action' => $proposal?->action, 'platform' => $proposal?->platform, 'note' => $request->input('note')]);
    }

    private function decide(callable $do, string $uuid)
    {
        $proposal = ActionProposal::query()->where('uuid', $uuid)->firstOrFail();

        try {
            $proposal = $do($proposal);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), null, 409);
        }

        return ApiResponse::success(['proposal' => $this->present($proposal->fresh(['campaign', 'adSet', 'ad', 'proposer', 'decider']))]);
    }

    private function present(ActionProposal $p): array
    {
        return ProposalPresenter::present($p);
    }
}
