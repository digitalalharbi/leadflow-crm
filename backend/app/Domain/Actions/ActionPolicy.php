<?php

namespace App\Domain\Actions;

use App\Domain\Campaigns\Contracts\AdPlatformAdapter;
use App\Models\ActionProposal;
use App\Models\AdConnection;
use App\Models\ClientRule;
use Carbon\CarbonImmutable;

/**
 * The hard limits, in code.
 *
 * An agent's instructions can say "never raise a budget by more than 20%"; this
 * is what makes it true when the instructions are ignored, misread, or written
 * by somebody else. Checked when a proposal is made, so a person sees the
 * problem before deciding, and again immediately before anything runs, because
 * the rules or the budget may have changed in between.
 */
class ActionPolicy
{
    /** The whole vocabulary. Deliberately no delete. */
    public const ACTIONS = [
        'pause', 'resume', 'update_budget', 'duplicate_ad_set', 'create_campaign',
        'create_ad', 'update_creative', 'update_targeting', 'schedule',
    ];

    /** Actions that need an object that exists on the platform. */
    private const NEEDS_TARGET = ['pause', 'resume', 'update_budget', 'duplicate_ad_set', 'update_creative', 'update_targeting'];

    /**
     * @return array{allowed: bool, blocks: list<string>, warnings: list<string>, limits: array<string, mixed>}
     */
    public function check(ActionProposal $proposal, ClientRule $rules, AdPlatformAdapter $adapter, ?AdConnection $connection, bool $executing = false): array
    {
        $blocks = [];
        $warnings = [];
        $limits = [];
        $payload = $proposal->payload ?? [];

        if (! in_array($proposal->action, self::ACTIONS, true)) {
            $blocks[] = 'unknown_action';
        }

        if (! $adapter->isImplemented()) {
            $blocks[] = 'platform_not_built';
        } elseif (! in_array($proposal->action, $adapter->actions(), true)) {
            $blocks[] = 'not_supported';
            $limits['unsupported_reason'] = $adapter->capabilities()[$proposal->action]['reason'] ?? null;
        } elseif (! $adapter->isConfigured($connection)) {
            // Proposing against an unconnected account is allowed — it is advice.
            // Running it is not.
            $executing ? $blocks[] = 'not_connected' : $warnings[] = 'not_connected';
        }

        $target = $this->target($proposal);

        if (in_array($proposal->action, self::NEEDS_TARGET, true) && $target['external_id'] === null) {
            $blocks[] = 'no_platform_id';
        }

        if ($target['account_external_id'] === null && $proposal->action === 'create_campaign') {
            $blocks[] = 'no_account';
        }

        if ($this->isBlackout($rules->blackout_days ?? [])) {
            $blocks[] = 'blackout_day';
        }

        if ($proposal->action === 'update_budget') {
            $next = (int) ($payload['daily_budget_minor'] ?? 0);
            $current = $proposal->ad_set_id ? $proposal->adSet?->daily_budget_minor : $proposal->campaign?->daily_budget_minor;

            if ($next <= 0) {
                $blocks[] = 'invalid_budget';
            }

            if ($rules->daily_budget_ceiling_minor && $next > $rules->daily_budget_ceiling_minor) {
                $blocks[] = 'over_ceiling';
                $limits['ceiling_minor'] = $rules->daily_budget_ceiling_minor;
            }

            $pct = max(1, (int) ($rules->max_budget_change_pct ?: 20));
            $limits['max_change_pct'] = $pct;

            if ($current) {
                $limits['current_minor'] = $current;
                $limits['min_minor'] = (int) floor($current * (1 - $pct / 100));
                $limits['max_minor'] = (int) ceil($current * (1 + $pct / 100));

                if ($next < $limits['min_minor'] || $next > $limits['max_minor']) {
                    $blocks[] = 'change_too_large';
                }
            } else {
                $warnings[] = 'current_budget_unknown';
            }
        }

        if ($proposal->action === 'update_targeting' && ! is_array($payload['targeting'] ?? null)) {
            $blocks[] = 'targeting_missing';
        }

        if ($proposal->action === 'schedule' && blank($payload['starts_at'] ?? null) && blank($payload['ends_at'] ?? null)) {
            $blocks[] = 'schedule_missing';
        }

        if ($proposal->action === 'create_campaign' && ($payload['status'] ?? 'paused') !== 'paused') {
            // Never live on creation, as Meta's own agent tools insist.
            $blocks[] = 'must_start_paused';
        }

        $words = array_filter(array_map('mb_strtolower', $rules->forbidden_words ?? []));
        $text = mb_strtolower(implode(' ', array_filter([
            $payload['headline'] ?? null, $payload['body'] ?? null, $payload['primary_text'] ?? null, $payload['name'] ?? null,
        ], 'is_string')));

        if ($words !== [] && $text !== '') {
            $hits = array_values(array_filter($words, fn ($w) => str_contains($text, $w)));
            if ($hits !== []) {
                $blocks[] = 'forbidden_words';
                $limits['forbidden'] = $hits;
            }
        }

        return [
            'allowed' => $blocks === [],
            'blocks' => array_values(array_unique($blocks)),
            'warnings' => array_values(array_unique($warnings)),
            'limits' => $limits,
        ];
    }

    /**
     * What the platform needs to find the object: its level and id, the ad
     * account it lives in, and — for an ad — the ad set it belongs to, which
     * some platforms put in the ad's own address.
     *
     * @return array{level: ?string, external_id: ?string, account_external_id: ?string, parent_external_id: ?string}
     */
    public function target(ActionProposal $proposal): array
    {
        $campaign = $proposal->campaign ?? $proposal->adSet?->campaign ?? $proposal->ad?->campaign;
        $account = $campaign?->account?->external_id ?? (($proposal->payload ?? [])['account_external_id'] ?? null);

        foreach (['ad' => $proposal->ad, 'ad_set' => $proposal->adSet, 'campaign' => $proposal->campaign] as $level => $entity) {
            if ($entity !== null) {
                return [
                    'level' => $level,
                    'external_id' => $entity->external_id,
                    'account_external_id' => $account,
                    'parent_external_id' => $level === 'ad' ? $proposal->ad?->adSet?->external_id : null,
                ];
            }
        }

        return ['level' => null, 'external_id' => null, 'account_external_id' => $account, 'parent_external_id' => null];
    }

    /** @param  list<string>  $days  ISO dates ("2026-11-27") or weekday names ("friday") */
    private function isBlackout(array $days): bool
    {
        $today = CarbonImmutable::now(config('app.timezone'));

        foreach ($days as $day) {
            $day = mb_strtolower(trim((string) $day));
            if ($day === $today->toDateString() || $day === mb_strtolower($today->englishDayOfWeek)) {
                return true;
            }
        }

        return false;
    }
}
