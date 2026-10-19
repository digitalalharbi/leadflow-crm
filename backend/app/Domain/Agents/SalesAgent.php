<?php

namespace App\Domain\Agents;

use App\Enums\Role;
use App\Models\AutomationActionLog;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\AutomationNotification;
use Illuminate\Support\Facades\Cache;

/**
 * The best leads must not wait.
 *
 * Every half hour: a lead that is hot, a priority or handed to a person, and
 * that nobody has contacted since it became their turn, is raised again — with
 * its owner first and, after an hour, with the managers. Once per lead per
 * day, so a slow afternoon is one reminder, not twelve.
 */
class SalesAgent
{
    /** @return array{owners:int, managers:int} */
    public function hotLeadReview(): array
    {
        $raised = ['owners' => 0, 'managers' => 0];
        $closed = ['won', 'lost', 'invalid'];

        Lead::query()
            ->whereNotIn('status', $closed)
            ->where(fn ($q) => $q->where('is_priority', true)->orWhere('temperature', 'hot')->orWhere('automation_state', Lead::AUTOMATION_HANDED_OFF))
            ->where(fn ($q) => $q->whereNull('needs_human_reason')->orWhere('needs_human_reason', '!=', 'unreached'))
            ->where(fn ($q) => $q->whereNull('handed_off_at')->orWhere('handed_off_at', '<=', now()->subMinutes(30)))
            ->whereRaw('(last_contact_at is null or last_contact_at <= coalesce(handed_off_at, updated_at))')
            ->with('assignee:id,name,is_active')
            ->limit(200)
            ->get()
            ->each(function (Lead $lead) use (&$raised) {
                $key = 'hot-review:'.$lead->id.':'.now()->toDateString();

                if (! Cache::add($key, true, now()->endOfDay())) {
                    return;
                }

                $waited = (int) ($lead->handed_off_at ?? $lead->updated_at)->diffInMinutes(now(), true);
                $message = "{$lead->full_name} ينتظر منذ {$waited} دقيقة — عميل ساخن لم يتواصل معه أحد.";

                if ($lead->assignee?->is_active) {
                    $lead->assignee->notify(new AutomationNotification($lead, $message, 'وكيل المبيعات'));
                    $raised['owners']++;
                }

                if ($waited >= 60 || ! $lead->assignee) {
                    User::query()->where('tenant_id', $lead->tenant_id)->whereIn('role', [Role::Admin->value, Role::Manager->value])->where('is_active', true)->get()
                        ->each(fn (User $u) => $u->notify(new AutomationNotification($lead, $message, 'وكيل المبيعات')));
                    $raised['managers']++;
                }

                AutomationActionLog::create([
                    'lead_id' => $lead->id, 'rule_name' => 'وكيل المبيعات: مراجعة الساخنين', 'trigger' => 'routine.hot_lead_review',
                    'action_type' => 'notify', 'channel' => 'team', 'mode' => 'auto', 'result' => AutomationActionLog::NEEDS_HUMAN,
                    'description' => $message, 'happened_at' => now(),
                ]);
            });

        return $raised;
    }
}
