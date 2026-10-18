<?php

namespace App\Domain\Agents;

use App\Models\Agent;
use App\Models\Routine;

/**
 * Who the agents are and what each runs on a schedule.
 *
 * Defined here, materialised per workspace on first use, so a workspace can
 * switch one off without a deploy. Only agents and routines that are actually
 * built are listed — a routine that would run nothing is not shown as running.
 */
class AgentDirectory
{
    /** key => [name, role, tools] */
    public const AGENTS = [
        'orchestrator' => ['المنسّق', 'يستقبل الطلب أو الـ Routine ويوزعه على الوكيل المختص، ويجمع الردود. لا ينفذ شيئًا بنفسه.', []],
        'analyst' => ['المحلل', 'تحليل يومي، مقارنة بأهداف العميل، كشف الشذوذ وإرهاق الإعلانات. قراءة فقط.', ['get_metrics', 'get_history', 'compare_targets']],
        'leads' => ['وكيل العملاء', 'يقيس جودة العملاء لكل حملة، يرفع أو يخفض تقييمهم، وينبّه عند تراجع الجودة.', ['get_leads', 'score_campaign_quality', 'notify']],
        'campaign' => ['وكيل الحملات', 'يقترح الإيقاف والتشغيل وتعديل الميزانية. كل اقتراح ينتظر موافقة بشرية.', ['list_campaigns', 'get_metrics', 'propose_pause', 'propose_resume', 'propose_budget']],
    ];

    /** key => [agent, cron (in the workspace's timezone)] */
    public const ROUTINES = [
        'hourly_sync' => ['orchestrator', '0 * * * *'],
        'daily_analysis' => ['analyst', '45 8 * * *'],
        'budget_guard' => ['analyst', '0 */2 * * *'],
        'lead_quality_review' => ['leads', '0 9 * * *'],
    ];

    public function ensure(): void
    {
        foreach (static::AGENTS as $key => [$name, $role, $tools]) {
            Agent::query()->firstOrCreate(['key' => $key], ['name' => $name, 'role' => $role, 'tools' => $tools, 'is_active' => true]);
        }

        foreach (static::ROUTINES as $key => [$agent, $cron]) {
            Routine::query()->firstOrCreate(['key' => $key], ['agent' => $agent, 'cron' => $cron, 'is_active' => true]);
        }
    }
}
