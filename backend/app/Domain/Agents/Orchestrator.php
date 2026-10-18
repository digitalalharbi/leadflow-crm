<?php

namespace App\Domain\Agents;

use App\Domain\Campaigns\CampaignResolver;
use App\Domain\Campaigns\CampaignSyncer;
use App\Enums\Role;
use App\Models\AgentThread;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Routine;
use App\Models\User;
use App\Notifications\AgentAlertNotification;
use InvalidArgumentException;
use Throwable;

/**
 * Hands a routine to the agent that does it and writes down what came back.
 *
 * It never acts on an ad account itself — it has no gateway. Every run is a
 * thread: what was asked, what each agent found, what was proposed; so "why is
 * there a pause waiting in my inbox" always has an answer one click away.
 */
class Orchestrator
{
    private const LABEL = [
        'no_results' => 'صرف بلا عملاء مؤهلين',
        'over_target' => 'تكلفة المؤهل فوق الهدف',
        'cpl_over_target' => 'تكلفة العميل فوق الهدف',
        'outperforming' => 'أفضل من الهدف',
        'quality_drop' => 'تراجع جودة العملاء',
        'spend_spike' => 'قفزة في الصرف',
        'over_ceiling' => 'ميزانية فوق السقف',
        'fatigue' => 'إرهاق الإعلان',
    ];

    public function __construct(
        private AgentDirectory $directory,
        private Analyst $analyst,
        private CampaignAgent $campaigns,
        private CampaignSyncer $syncer,
        private CampaignResolver $resolver,
    ) {}

    public function run(string $routine, ?User $by = null, ?int $projectId = null): AgentThread
    {
        $this->directory->ensure();

        if (! array_key_exists($routine, AgentDirectory::ROUTINES)) {
            throw new InvalidArgumentException("Unknown routine {$routine}.");
        }

        $thread = AgentThread::create([
            'agent' => 'orchestrator',
            'routine' => $routine,
            'project_id' => $projectId,
            'started_by' => $by?->id,
            'title' => $routine,
        ]);

        $thread->say($by ? 'user' : 'agent', $by ? null : 'orchestrator', $by ? 'تشغيل يدوي' : 'تشغيل مجدول', ['routine' => $routine]);

        try {
            $summary = match ($routine) {
                'hourly_sync' => $this->sync($thread),
                'daily_analysis' => $this->dailyAnalysis($thread, $projectId),
                'budget_guard' => $this->budgetGuard($thread, $projectId),
            };

            Routine::query()->where('key', $routine)->update(['last_run_at' => now(), 'last_status' => 'ok', 'last_summary' => $summary]);
        } catch (Throwable $e) {
            $thread->say('agent', 'orchestrator', 'تعذّر إكمال التشغيل: '.mb_substr($e->getMessage(), 0, 300));
            Routine::query()->where('key', $routine)->update(['last_run_at' => now(), 'last_status' => 'failed', 'last_summary' => mb_substr($e->getMessage(), 0, 300)]);
            report($e);
        }

        return $thread;
    }

    private function sync(AgentThread $thread): string
    {
        $totals = ['accounts' => 0, 'campaigns' => 0, 'snapshots' => 0];

        foreach (Integration::query()->where('is_active', true)->get() as $integration) {
            $result = $this->syncer->sync($integration);
            foreach (array_keys($totals) as $k) {
                $totals[$k] += $result[$k];
            }
        }

        $linked = 0;
        Lead::query()->whereNull('campaign_ref_id')
            ->where(fn ($q) => $q->whereNotNull('campaign_name')->orWhereNotNull('campaign_id'))
            ->chunkById(200, function ($leads) use (&$linked) {
                foreach ($leads as $lead) {
                    $linked += $this->resolver->linkLead($lead) ? 1 : 0;
                }
            });

        $summary = sprintf('حسابات: %d · حملات: %d · لقطات: %d · عملاء رُبطوا بحملاتهم: %d', $totals['accounts'], $totals['campaigns'], $totals['snapshots'], $linked);
        $thread->say('tool', 'orchestrator', $summary, $totals + ['linked' => $linked]);

        return $summary;
    }

    private function dailyAnalysis(AgentThread $thread, ?int $projectId): string
    {
        $findings = $this->analyst->analyze($projectId);
        $thread->say('agent', 'analyst', $this->describe($findings), ['findings' => array_map(fn (Finding $f) => $f->toArray(), $findings)]);

        $proposals = $this->campaigns->act($findings);
        $thread->say('agent', 'campaign',
            $proposals === [] ? 'لا اقتراحات تغيير اليوم.' : sprintf('رفعتُ %d اقتراحًا للموافقة.', count($proposals)),
            ['proposals' => array_map(fn ($p) => $p->uuid, $proposals)],
        );

        $bad = array_filter($findings, fn (Finding $f) => $f->severity === 'bad');
        if ($bad !== [] || $proposals !== []) {
            $this->alert('analyst', 'تحليل الحملات', sprintf('%d ملاحظة مهمة · %d اقتراح ينتظر موافقتك', count($bad), count($proposals)));
        }

        return sprintf('%d ملاحظة · %d اقتراح', count($findings), count($proposals));
    }

    private function budgetGuard(AgentThread $thread, ?int $projectId): string
    {
        $findings = $this->analyst->guard($projectId);
        $thread->say('agent', 'analyst', $findings === [] ? 'الصرف ضمن الحدود.' : $this->describe($findings), ['findings' => array_map(fn (Finding $f) => $f->toArray(), $findings)]);

        if ($findings !== []) {
            $this->alert('analyst', 'حارس الميزانية', $this->describe($findings));
        }

        return sprintf('%d تنبيه', count($findings));
    }

    /** @param  list<Finding>  $findings */
    private function describe(array $findings): string
    {
        if ($findings === []) {
            return 'لا شيء يستدعي الانتباه.';
        }

        return collect($findings)->take(6)
            ->map(fn (Finding $f) => (self::LABEL[$f->key] ?? $f->key).' — '.$f->campaign->name)
            ->implode(' · ');
    }

    private function alert(string $agent, string $title, string $body): void
    {
        User::query()->whereIn('role', [Role::Admin->value, Role::Manager->value])->where('is_active', true)->get()
            ->each(fn (User $u) => $u->notify(new AgentAlertNotification($agent, $title, $body, '/campaigns')));
    }
}
