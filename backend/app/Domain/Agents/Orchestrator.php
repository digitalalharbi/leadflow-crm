<?php

namespace App\Domain\Agents;

use App\Domain\Campaigns\CampaignResolver;
use App\Domain\Campaigns\CampaignSyncer;
use App\Domain\Notifications\AlertGate;
use App\Enums\Role;
use App\Models\AdConnection;
use App\Models\AgentThread;
use App\Models\AutomationActionLog;
use App\Models\Campaign;
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
        'low_qualified_rate' => 'تحذير جودة: نسبة المؤهلين أقل من 10%',
        'many_invalid' => 'عملاء غير صالحين كثيرون — راجِع الحملة',
        'spend_spike' => 'قفزة في الصرف',
        'over_ceiling' => 'ميزانية فوق السقف',
        'fatigue' => 'إرهاق الإعلان',
        'weak_creative' => 'إعلان ضعيف',
        'winner_creative' => 'الإعلان الأفضل',
    ];

    public function __construct(
        private AgentDirectory $directory,
        private Analyst $analyst,
        private CampaignAgent $campaigns,
        private CampaignSyncer $syncer,
        private CampaignResolver $resolver,
        private LeadsAgent $leads,
        private CreativeAgent $creative,
        private ReportsAgent $reports,
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
                'lead_quality_review' => $this->leadQuality($thread),
                'creative_review' => $this->creativeReview($thread),
                'weekly_report' => $this->weeklyReport($thread, $projectId),
                'hot_lead_review' => $this->hotLeads($thread),
                'stale_lead_review' => $this->staleLeads($thread),
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

        foreach (AdConnection::active() as $integration) {
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

    private function leadQuality(AgentThread $thread): string
    {
        $drops = $this->leads->review();
        $scored = Campaign::query()->whereNotNull('quality_scored_at')->where('quality_scored_at', '>=', now()->subMinutes(5))->count();

        $thread->say('agent', 'leads',
            $drops === [] ? sprintf('قيّمتُ جودة %d حملة. لا تراجع ملحوظ.', $scored) : $this->describe($drops),
            ['scored' => $scored, 'findings' => array_map(fn (Finding $f) => $f->toArray(), $drops)],
        );

        if ($drops !== []) {
            $this->alert('leads', 'جودة العملاء', $this->describe($drops));

            foreach ($drops as $finding) {
                AutomationActionLog::create([
                    'rule_name' => 'جودة عملاء الحملة', 'trigger' => 'routine.lead_quality_review',
                    'action_type' => 'campaign_quality_warning', 'channel' => 'team', 'mode' => 'recommend',
                    'result' => AutomationActionLog::NEEDS_HUMAN,
                    'after' => $finding->campaign->name ?? null,
                    'description' => $this->describe([$finding]), 'happened_at' => now(),
                ]);
            }
        }

        return sprintf('%d حملة · %d تراجع', $scored, count($drops));
    }

    private function hotLeads(AgentThread $thread): string
    {
        $raised = app(SalesAgent::class)->hotLeadReview();
        $summary = sprintf('نُبّه %d مندوب · %d للإدارة', $raised['owners'], $raised['managers']);
        $thread->say('agent', 'sales', $summary, $raised);

        return $summary;
    }

    private function staleLeads(AgentThread $thread): string
    {
        $revived = app(FollowUpAgent::class)->staleLeadReview();
        $summary = sprintf('عاد %d عميل راكد إلى الرعاية الآلية', $revived);
        $thread->say('agent', 'follow_up', $summary, ['revived' => $revived]);

        return $summary;
    }

    private function creativeReview(AgentThread $thread): string
    {
        $findings = $this->creative->review();
        $thread->say('agent', 'creative', $this->describe($findings), ['findings' => array_map(fn (Finding $f) => $f->toArray(), $findings)]);

        $tired = array_filter($findings, fn (Finding $f) => $f->key === 'fatigue');
        if ($tired !== []) {
            $this->alert('creative', 'مراجعة الإعلانات', sprintf('%d إعلان يحتاج تحديثًا', count($tired)));
        }

        return sprintf('%d ملاحظة', count($findings));
    }

    private function weeklyReport(AgentThread $thread, ?int $projectId): string
    {
        $report = $this->reports->weekly($projectId);
        $t = $report->data['totals'];
        $summary = sprintf('عملاء %d · مؤهلون %d · صفقات %d', $t['leads'], $t['qualified'], $t['won']);
        $thread->say('agent', 'reports', $summary, ['report' => $report->uuid]);
        $this->alert('reports', 'التقرير الأسبوعي جاهز', $summary, '/reports');

        return $summary;
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

    private function alert(string $agent, string $title, string $body, string $link = '/campaigns'): void
    {
        // The same finding is announced once a day, however often the agent looks.
        if (! AlertGate::once("agent:{$agent}:".md5($title.$body).':'.now()->toDateString())) {
            return;
        }

        User::query()->whereIn('role', [Role::Admin->value, Role::Manager->value])->where('is_active', true)->get()
            ->each(fn (User $u) => $u->notify(new AgentAlertNotification($agent, $title, $body, $link)));
    }
}
