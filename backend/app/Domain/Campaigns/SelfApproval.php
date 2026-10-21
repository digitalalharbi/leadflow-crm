<?php

namespace App\Domain\Campaigns;

use App\Domain\Access\Permissions;
use App\Domain\Actions\ActionPolicy;
use App\Models\ActionProposal;
use App\Models\Project;
use App\Models\TenantSetting;
use App\Models\User;

/**
 * Safe self-approve: a person's own low-risk change to a campaign runs at once instead of waiting
 * in the inbox for a second look — when the workspace (or the project) has said so.
 *
 * Off by default. Only a pause, a resume, and — behind a guard of its own — a budget change within
 * so many percent can ever be self-approved; everything else in the vocabulary (a new campaign, a
 * copy, a creative, targeting, a schedule) always waits for approval, and so does anything an
 * agent proposed, whatever is switched on. The person must hold the approve permission: the mode
 * saves them a click, it does not give them a power. Sync reads and never needed approval.
 *
 * The project's setting lays over the workspace's, key by key, like the anomaly lines.
 */
class SelfApproval
{
    /** What a workspace may let run at once. Deliberately short. */
    public const CONFIGURABLE = ['pause', 'resume', 'update_budget'];

    /** @return list<string> the actions that always wait for a person */
    public static function alwaysManual(): array
    {
        return array_values(array_diff(ActionPolicy::ACTIONS, self::CONFIGURABLE));
    }

    /** @param  array<string,mixed>  $workspace @param  array<string,mixed>  $project */
    public function __construct(private array $workspace = [], private array $project = []) {}

    public static function for(?TenantSetting $settings, ?Project $project = null): self
    {
        return new self((array) ($settings?->self_approval ?? []), (array) ($project?->self_approval ?? []));
    }

    public function enabled(): bool
    {
        return (bool) ($this->project['enabled'] ?? $this->workspace['enabled'] ?? false);
    }

    /** @return list<string> */
    public function actions(): array
    {
        $list = (array) ($this->project['actions'] ?? $this->workspace['actions'] ?? []);

        return array_values(array_intersect(self::CONFIGURABLE, $list));
    }

    /** The most a budget may move, in percent, and still run at once. Zero means a budget change always waits. */
    public function maxBudgetChangePct(): int
    {
        return (int) ($this->project['max_budget_change_pct'] ?? $this->workspace['max_budget_change_pct'] ?? 0);
    }

    public function overrides(): bool
    {
        return array_filter($this->project, fn ($v) => $v !== null && $v !== []) !== [];
    }

    /**
     * May this proposal run without a second look? The answer carries why not, and the rule it was
     * judged by, so the audit trail can say exactly what let it through.
     *
     * @return array{allowed:bool, reason:?string, rule:array<string,mixed>}
     */
    public function decide(ActionProposal $proposal, ?User $user): array
    {
        $rule = ['enabled' => $this->enabled(), 'actions' => $this->actions(), 'max_budget_change_pct' => $this->maxBudgetChangePct(), 'source' => $this->overrides() ? 'project' : 'workspace'];
        $no = fn (string $why) => ['allowed' => false, 'reason' => $why, 'rule' => $rule];

        if (! $this->enabled()) {
            return $no('off');
        }

        if ($proposal->proposed_by !== 'user' || $user === null) {
            return $no('agent');
        }

        if ($proposal->status !== ActionProposal::PENDING) {
            return $no('not_pending');
        }

        if (! in_array($proposal->action, self::CONFIGURABLE, true)) {
            return $no('high_risk');
        }

        if (! in_array($proposal->action, $this->actions(), true)) {
            return $no('not_enabled');
        }

        if (! Permissions::has($user, 'campaigns.approve', $proposal->project_id)) {
            return $no('cannot_approve');
        }

        if ($proposal->action === 'update_budget') {
            $guard = $this->maxBudgetChangePct();
            $current = $proposal->ad_set_id ? $proposal->adSet?->daily_budget_minor : $proposal->campaign?->daily_budget_minor;
            $next = (int) (($proposal->payload ?? [])['daily_budget_minor'] ?? 0);

            if ($guard <= 0 || ! $current || $next <= 0) {
                return $no('budget_guard');
            }

            $change = abs($next - $current) / $current * 100;

            if ($change > $guard) {
                return $no('budget_guard');
            }

            $rule['budget_change_pct'] = round($change, 1);
        }

        return ['allowed' => true, 'reason' => null, 'rule' => $rule];
    }

    /**
     * The operations the table may run at once for this person on this campaign, in the table's own
     * words (set_status when both halves are on; set_budget only with a guard to stand behind it).
     *
     * @param  list<string>  $offered  what the platform lets the table do
     * @return list<string>
     */
    public function instant(?User $user, array $offered): array
    {
        if (! $this->enabled() || $user === null || ! Permissions::has($user, 'campaigns.approve')) {
            return [];
        }

        $on = $this->actions();
        $pause = in_array('pause', $on, true) && in_array('pause', $offered, true);
        $resume = in_array('resume', $on, true) && in_array('resume', $offered, true);

        return array_values(array_filter([
            $pause ? 'pause' : null,
            $resume ? 'resume' : null,
            $pause && $resume && in_array('set_status', $offered, true) ? 'set_status' : null,
            in_array('update_budget', $on, true) && $this->maxBudgetChangePct() > 0 && in_array('set_budget', $offered, true) ? 'set_budget' : null,
        ]));
    }

    /** What is in force and where each part came from, for the settings screen. @return array<string,mixed> */
    public function describe(): array
    {
        $source = fn (string $key) => array_key_exists($key, $this->project) && $this->project[$key] !== null ? 'project' : (array_key_exists($key, $this->workspace) && $this->workspace[$key] !== null ? 'workspace' : 'default');

        return [
            'enabled' => $this->enabled(), 'enabled_source' => $source('enabled'),
            'actions' => $this->actions(), 'actions_source' => $source('actions'),
            'max_budget_change_pct' => $this->maxBudgetChangePct(), 'max_budget_change_pct_source' => $source('max_budget_change_pct'),
            'configurable' => self::CONFIGURABLE,
            'always_manual' => self::alwaysManual(),
        ];
    }

    /**
     * Cleans what was sent into what is kept: a switch, a list of known actions, a guard within
     * 0–100. A null clears a setting (back to what is inherited).
     *
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>  $given
     * @return array<string,mixed>
     */
    public static function merge(array $current, array $given): array
    {
        if (array_key_exists('enabled', $given)) {
            $given['enabled'] === null ? $current = array_diff_key($current, ['enabled' => 1]) : $current['enabled'] = (bool) $given['enabled'];
        }

        if (array_key_exists('actions', $given)) {
            $given['actions'] === null
                ? $current = array_diff_key($current, ['actions' => 1])
                : $current['actions'] = array_values(array_intersect(self::CONFIGURABLE, (array) $given['actions']));
        }

        if (array_key_exists('max_budget_change_pct', $given)) {
            $given['max_budget_change_pct'] === null
                ? $current = array_diff_key($current, ['max_budget_change_pct' => 1])
                : $current['max_budget_change_pct'] = max(0, min(100, (int) $given['max_budget_change_pct']));
        }

        return $current;
    }
}
