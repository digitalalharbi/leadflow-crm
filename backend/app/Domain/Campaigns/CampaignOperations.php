<?php

namespace App\Domain\Campaigns;

use App\Domain\Actions\ActionGateway;
use App\Domain\Actions\ProposalPresenter;
use App\Domain\Tracking\TrackingHealth;
use App\Models\AdConnection;
use App\Models\Campaign;
use App\Models\ConversionExport;
use App\Models\Lead;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * What a person does to a campaign from the operations table — pause, resume, change the budget,
 * adjust the status, sync, look at its tracking — each one routed the one right way.
 *
 * Changes to an ad account are never made here: they are *proposed* through the action gateway, so
 * the same limits, approval, audit and one-at-a-time execution apply as for an agent's proposal, and
 * asking twice for the same change is the same request. Sync reads, so it needs no approval, and is
 * safe to repeat. There is no delete: the vocabulary does not contain one, and an unknown action
 * is refused. Where the platform has no connected account the sandbox adapter (when it is on) is
 * what carries an approved change out — and the answer says so.
 */
class CampaignOperations
{
    public const ACTIONS = ['pause', 'resume', 'set_status', 'set_budget', 'sync'];

    public function __construct(private ActionGateway $gateway, private CampaignSyncer $syncer, private AuditLogger $audit, private AdPlatformRegistry $registry) {}

    /**
     * @param  array{reason?:string,status?:string,daily_budget?:float|int|string}  $data
     * @return array<string,mixed>
     */
    public function operate(User $user, Campaign $campaign, string $action, array $data): array
    {
        if ($action === 'sync') {
            return $this->sync($user, $campaign);
        }

        [$change, $payload] = match ($action) {
            'pause' => ['pause', []],
            'resume' => ['resume', []],
            'set_status' => [($data['status'] ?? null) === 'active' ? 'resume' : 'pause', []],
            'set_budget' => ['update_budget', ['daily_budget_minor' => (int) round(((float) ($data['daily_budget'] ?? 0)) * 100)]],
        };

        // Saying the state it is already in is a no-op, not a second proposal.
        if (($change === 'pause' && $campaign->status === 'paused') || ($change === 'resume' && $campaign->status === 'active')) {
            throw ValidationException::withMessages(['action' => ["The campaign is already {$campaign->status}."]]);
        }

        if ($change === 'update_budget' && ($payload['daily_budget_minor'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['daily_budget' => ['A budget must be more than zero.']]);
        }

        $proposal = $this->gateway->propose([
            'action' => $change,
            'platform' => $campaign->platform,
            'campaign_id' => $campaign->id,
            'project_id' => $campaign->project_id,
            'payload' => $payload,
            'reason' => trim((string) ($data['reason'] ?? '')),
        ], $user);

        return ['kind' => 'proposal', 'proposal' => ProposalPresenter::present($proposal->fresh())];
    }

    /** @return array<string,mixed> */
    private function sync(User $user, Campaign $campaign): array
    {
        $connection = AdConnection::for($campaign->platform);

        if ($connection === null || ! $connection->is_active || ! $this->registry->get($campaign->platform)->isImplemented()) {
            return ['kind' => 'sync', 'synced' => false, 'reason' => 'not_connected', 'sandbox' => $this->registry->sandboxed()];
        }

        $result = $this->syncer->sync($connection, 7);
        $this->audit->log('campaign.synced', $user, $campaign, ['platform' => $campaign->platform, 'ok' => $result['error'] === null]);

        return ['kind' => 'sync', 'synced' => $result['error'] === null, 'reason' => $result['error'], 'sandbox' => false, 'campaigns' => $result['campaigns']];
    }

    /**
     * Can we believe the numbers of this campaign? The platform's side, ours, and what has been told
     * back to the platform about what its clicks became.
     *
     * @return array<string,mixed>
     */
    public function trackingHealth(Campaign $campaign): array
    {
        $since = now()->subDays(30);
        $leads = Lead::query()->reorder()->where('campaign_ref_id', $campaign->id)->where('created_at', '>=', $since)
            ->selectRaw('count(*) as total,
                count(*) filter (where click_id is not null) as click_id,
                count(*) filter (where utm_source is not null) as utm,
                count(*) filter (where first_touch_link_id is not null) as linked,
                count(*) filter (where click_id is not null or first_touch_link_id is not null or utm_source is not null) as traced')->first();

        $exports = ConversionExport::query()->join('leads', 'leads.id', '=', 'conversion_exports.lead_id')
            ->where('leads.campaign_ref_id', $campaign->id)->where('conversion_exports.created_at', '>=', $since)
            ->selectRaw('conversion_exports.status, count(*) as n')->groupBy('conversion_exports.status')->pluck('n', 'status');

        $platform = collect(app(TrackingHealth::class)->build(7)['platforms'])->firstWhere('platform', $campaign->platform);
        $total = (int) $leads->total;
        $traced = $total > 0 ? (int) round((int) $leads->traced / $total * 100) : null;
        $links = TrackingLink::query()->where('campaign_id', $campaign->id)->where('is_active', true)->count();
        $failed = (int) ($exports['failed'] ?? 0);

        $issues = array_values(array_filter([
            $platform !== null && $platform['connected'] && $platform['status'] === 'risk' ? 'platform_risk' : null,
            $traced !== null && $total >= 5 && $traced < 60 ? 'low_trace' : null,
            $links === 0 ? 'no_links' : null,
            $failed > 0 ? 'conversions_failing' : null,
        ]));

        return [
            'platform' => $platform === null ? null : [
                'key' => $platform['platform'], 'status' => $platform['status'], 'connected' => $platform['connected'], 'receiving' => $platform['receiving'],
                'last_event_at' => $platform['last_event_at'], 'success_rate' => $platform['success_rate'],
            ],
            'leads' => ['total' => $total, 'with_click_id' => (int) $leads->click_id, 'with_utm' => (int) $leads->utm, 'with_link' => (int) $leads->linked, 'traced_percent' => $traced],
            'links' => $links,
            'conversions' => ['sent' => (int) ($exports['sent'] ?? 0), 'failed' => $failed, 'pending' => (int) ($exports['pending'] ?? 0)],
            'status' => $issues === [] ? ($total === 0 ? 'idle' : 'healthy') : (in_array('platform_risk', $issues, true) || in_array('conversions_failing', $issues, true) ? 'risk' : 'attention'),
            'issues' => $issues,
        ];
    }
}
