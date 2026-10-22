<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\LeadActivity;
use Carbon\CarbonInterface;

/**
 * Who did what, to what, from what to what, when — and through which door.
 *
 * Two records already say it, written for different reasons: the audit log (people changing the
 * workspace — roles, rules, reports, spend, links) and each lead's own history (an assignment, a
 * stage, a reservation, a visit). A manager asking "who moved that?" should not have to know which
 * of the two to open, so this reads both into one shape and one list, newest first.
 *
 *   category   assignment · status · reservation · visit · deal · automation · reports · permissions · campaigns · other
 *   source     user (a person pressed it) · automation (a rule or the journey did) · system (a sweep)
 */
class AuditTrail
{
    public const CATEGORIES = ['assignment', 'status', 'reservation', 'visit', 'deal', 'automation', 'reports', 'permissions', 'campaigns', 'other'];

    /** Lead activity types this trail reports, and the category of each. */
    private const ACTIVITY = [
        'assigned' => 'assignment', 'status_changed' => 'status', 'won' => 'status', 'lost' => 'status', 'handed_off' => 'assignment', 'merged' => 'status',
        'reservation_created' => 'reservation', 'reservation_updated' => 'reservation', 'visit_updated' => 'visit', 'appointment_created' => 'visit',
        'interest_changed' => 'status',
    ];

    /**
     * @param  array{category?:?string, from?:?CarbonInterface, to?:?CarbonInterface, actor?:?int}  $filters
     * @return list<array<string,mixed>>
     */
    public function entries(array $filters = [], int $limit = 100): array
    {
        $category = $filters['category'] ?? null;
        $rows = [];

        if ($category === null || ! in_array($category, ['assignment', 'status', 'reservation', 'visit'], true)) {
            foreach ($this->fromAuditLog($filters, $limit) as $row) {
                $rows[] = $row;
            }
        }

        if ($category === null || in_array($category, ['assignment', 'status', 'reservation', 'visit'], true)) {
            foreach ($this->fromActivities($filters, $limit, $category) as $row) {
                $rows[] = $row;
            }
        }

        usort($rows, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice(array_values(array_filter($rows, fn ($r) => $category === null || $r['category'] === $category)), 0, $limit);
    }

    /** @return list<array<string,mixed>> */
    private function fromAuditLog(array $filters, int $limit): array
    {
        return AuditLog::query()->with('user:id,name')
            ->where('action', 'not like', 'auth.%')
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d))
            ->when($filters['actor'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->latest('id')->limit($limit * 2)->get()
            ->map(function (AuditLog $log) {
                $c = (array) ($log->changes ?? []);

                return [
                    'id' => 'a'.$log->id,
                    'at' => $log->created_at->toIso8601String(),
                    'category' => $this->categoryOf($log->action),
                    'action' => $log->action,
                    'actor' => $log->user?->name,
                    'subject' => $c['name'] ?? $c['lead'] ?? null,
                    'before' => $this->shown($c['before'] ?? null),
                    'after' => $this->shown($c['after'] ?? ($c['role'] ?? null)),
                    'source' => $log->user_id === null ? 'system' : 'user',
                    'lead_id' => null,
                ];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    private function fromActivities(array $filters, int $limit, ?string $category): array
    {
        $types = array_keys(array_filter(self::ACTIVITY, fn (string $c) => $category === null || $c === $category));

        return LeadActivity::query()->with(['user:id,name', 'lead:id,uuid,full_name'])
            ->whereIn('type', $types)
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d))
            ->when($filters['actor'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->latest('id')->limit($limit * 2)->get()
            ->map(function (LeadActivity $a) {
                $m = (array) ($a->meta ?? []);
                $type = $a->type->value;

                return [
                    'id' => 'l'.$a->id,
                    'at' => $a->created_at->toIso8601String(),
                    'category' => self::ACTIVITY[$type] ?? 'other',
                    'action' => "lead.{$type}",
                    'actor' => $a->user?->name,
                    'subject' => $a->lead?->full_name,
                    'before' => $this->shown($m['from'] ?? $m['from_name'] ?? null),
                    'after' => $this->shown($m['to'] ?? $m['assigned_to_name'] ?? ($m['outcome'] ?? null)),
                    // A person pressed it, a rule or the journey did, or a sweep (the SLA ladder) did.
                    'source' => $a->user_id !== null ? 'user' : (($m['by'] ?? null) === 'sla' ? 'system' : 'automation'),
                    'lead_id' => $a->lead?->uuid,
                ];
            })->all();
    }

    private function categoryOf(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'user.') => 'permissions',
            str_starts_with($action, 'report_share.') => 'reports',
            str_starts_with($action, 'automation_rule.'), str_starts_with($action, 'project.automation'), $action === 'project.scoring_changed', $action === 'project.thresholds_changed' => 'automation',
            str_starts_with($action, 'campaign'), str_starts_with($action, 'action_proposal.'), str_starts_with($action, 'self_approval.'), str_starts_with($action, 'tracking_link.'), str_starts_with($action, 'partner.'), str_starts_with($action, 'anomaly.') => 'campaigns',
            $action === 'lead.deal_value_changed' => 'deal',
            str_starts_with($action, 'lead.') => 'status',
            default => 'other',
        };
    }

    /** A before/after as something a row can show: a word, a number, or a short summary of a record. */
    private function shown(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_scalar($value)) {
            return mb_substr((string) $value, 0, 80);
        }

        $pairs = collect((array) $value)->filter(fn ($v) => is_scalar($v) && $v !== null)->map(fn ($v, $k) => is_string($k) ? "{$k}: ".(is_bool($v) ? ($v ? 'on' : 'off') : $v) : (string) $v);

        return mb_substr($pairs->take(4)->implode(' · '), 0, 120);
    }
}
