<?php

namespace App\Domain\Agents;

use App\Domain\Actions\ActionGateway;
use App\Domain\Actions\ActionPolicy;
use App\Domain\Actions\ProposalPresenter;
use App\Domain\Campaigns\CampaignMetrics;
use App\Models\Ad;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\ClientRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The tools, and who may use which.
 *
 * Every tool runs inside the caller's workspace — the tenant scope applies to
 * every query, so a tool cannot see another workspace however it is asked. Only
 * two tools change anything, and neither touches an ad account: propose_action
 * files a proposal through the gateway, and build_weekly_report saves a report.
 *
 * Each tool returns what the model reads (`result`, wrapped as data) and what
 * the screen draws (`blocks`: figures, tables, rankings, proposals).
 */
final class AgentToolbox
{
    /** tool => agents allowed to use it */
    private const PERMISSIONS = [
        'get_performance' => ['orchestrator', 'campaign', 'analyst', 'creative', 'leads', 'reports'],
        'list_campaigns' => ['campaign', 'analyst', 'creative', 'leads', 'reports'],
        'get_campaign' => ['campaign', 'analyst', 'creative'],
        'analyze_campaigns' => ['campaign', 'analyst'],
        'get_client_rules' => ['orchestrator', 'campaign', 'analyst', 'creative', 'leads', 'reports'],
        'review_creatives' => ['creative', 'analyst'],
        'lead_quality' => ['leads', 'analyst', 'campaign'],
        'propose_action' => ['campaign', 'creative'],
        'draft_creative' => ['creative'],
        'build_weekly_report' => ['reports'],
        'ask_agent' => ['orchestrator'],
    ];

    /** The Creative Agent may only propose changes to ads, never to money. */
    private const CREATIVE_ACTIONS = ['pause', 'create_ad', 'update_creative'];

    public function __construct(
        private Analyst $analyst,
        private CreativeAgent $creative,
        private ReportsAgent $reports,
        private ActionGateway $gateway,
    ) {}

    /** @return list<array{name: string, description: string, input_schema: array}> */
    public function definitionsFor(string $agent): array
    {
        return array_values(array_filter(array_map(
            fn (string $tool) => in_array($agent, self::PERMISSIONS[$tool], true) ? $this->definition($tool) : null,
            array_keys(self::PERMISSIONS),
        )));
    }

    public function allowed(string $agent, string $tool): bool
    {
        return in_array($agent, self::PERMISSIONS[$tool] ?? [], true);
    }

    /** @return array{result: mixed, blocks: list<array<string, mixed>>} */
    public function run(string $agent, string $tool, array $input, ?User $user = null): array
    {
        if (! $this->allowed($agent, $tool)) {
            return ['result' => ['error' => "The {$agent} agent may not use {$tool}."], 'blocks' => []];
        }

        try {
            return match ($tool) {
                'get_performance' => $this->performance($input),
                'list_campaigns' => $this->campaigns($input),
                'get_campaign' => $this->campaign($input),
                'analyze_campaigns' => $this->analyze(),
                'get_client_rules' => ['result' => ClientRule::for(null)->only(['target_cpl_minor', 'target_cpql_minor', 'target_cpa_minor', 'target_roas', 'max_budget_change_pct', 'daily_budget_ceiling_minor', 'blackout_days', 'forbidden_words']), 'blocks' => []],
                'review_creatives' => $this->creatives(),
                'lead_quality' => $this->quality($input),
                'propose_action' => $this->propose($agent, $input, $user),
                'draft_creative' => app(CreativeDrafts::class)->draft($input, $user),
                'build_weekly_report' => $this->report(),
                default => ['result' => ['error' => "No tool {$tool}."], 'blocks' => []],
            };
        } catch (InvalidArgumentException $e) {
            return ['result' => ['error' => $e->getMessage()], 'blocks' => []];
        }
    }

    // ------------------------------------------------------------- tools

    private function performance(array $in): array
    {
        $days = $this->days($in);
        $metrics = new CampaignMetrics(null, CarbonImmutable::today()->subDays($days - 1), CarbonImmutable::today());
        $rows = $metrics->rows(array_filter(['platform' => $in['platform'] ?? null]));
        $totals = $metrics->totals($rows);
        $byPlatform = collect($rows)->groupBy('platform')->map(fn ($g, $p) => ['label' => $p, 'value' => $g->sum('qualified'), 'note' => $g->sum('leads').' leads'])->values()->all();

        return [
            'result' => ['days' => $days, 'totals' => $totals, 'by_platform' => $byPlatform],
            'blocks' => [
                ['type' => 'kpis', 'items' => [
                    ['key' => 'spend', 'value' => $totals['spend'], 'format' => 'money'],
                    ['key' => 'leads', 'value' => $totals['leads'], 'format' => 'number'],
                    ['key' => 'qualified', 'value' => $totals['qualified'], 'format' => 'number'],
                    ['key' => 'cpql', 'value' => $totals['cpql'], 'format' => 'money'],
                    ['key' => 'won', 'value' => $totals['won'], 'format' => 'number'],
                    ['key' => 'roas', 'value' => $totals['roas'], 'format' => 'ratio'],
                ]],
                ['type' => 'ranking', 'title' => 'qualified_by_platform', 'items' => $byPlatform],
            ],
        ];
    }

    private function campaigns(array $in): array
    {
        $days = $this->days($in);
        $rows = (new CampaignMetrics(null, CarbonImmutable::today()->subDays($days - 1), CarbonImmutable::today()))->rows(array_filter(['platform' => $in['platform'] ?? null]));
        $sort = in_array($in['sort'] ?? null, ['qualified', 'spend', 'cpql', 'leads'], true) ? $in['sort'] : 'qualified';
        $rows = collect($rows)->sortByDesc(fn ($r) => $r[$sort] ?? -1)->take(min(20, (int) ($in['limit'] ?? 10)))->values();
        $slim = $rows->map(fn ($r) => array_intersect_key($r, array_flip(['id', 'name', 'platform', 'status', 'spend', 'leads', 'qualified', 'won', 'cpl', 'cpql', 'qualified_rate', 'roas'])))->all();

        return [
            'result' => $slim,
            'blocks' => [['type' => 'table', 'columns' => ['name', 'platform', 'spend', 'leads', 'qualified', 'cpql', 'roas'], 'rows' => $slim]],
        ];
    }

    private function campaign(array $in): array
    {
        $campaign = Campaign::query()->where('uuid', (string) ($in['campaign_id'] ?? ''))->first()
            ?? throw new InvalidArgumentException('No such campaign in this workspace.');
        $days = $this->days($in);
        $detail = (new CampaignMetrics(null, CarbonImmutable::today()->subDays($days - 1), CarbonImmutable::today()))->detail($campaign);
        $ads = array_map(fn ($a) => array_intersect_key($a, array_flip(['id', 'name', 'status', 'leads', 'qualified', 'cpql', 'ctr', 'fatigue'])), $detail['ads']);

        return [
            'result' => ['campaign' => $detail['campaign'], 'ads' => $ads, 'classification' => $detail['classification']],
            'blocks' => [['type' => 'table', 'columns' => ['name', 'leads', 'qualified', 'cpql', 'ctr', 'fatigue'], 'rows' => $ads]],
        ];
    }

    private function analyze(): array
    {
        $findings = array_map(fn (Finding $f) => $f->toArray(), $this->analyst->analyze());

        return ['result' => $findings, 'blocks' => [['type' => 'findings', 'items' => $findings]]];
    }

    private function creatives(): array
    {
        $findings = array_map(fn (Finding $f) => $f->toArray(), $this->creative->review());

        return ['result' => $findings, 'blocks' => [['type' => 'findings', 'items' => $findings]]];
    }

    private function quality(array $in): array
    {
        $rows = Campaign::query()->whereNotNull('quality_score')->orderByDesc('quality_score')->limit(15)
            ->get(['uuid', 'name', 'platform', 'quality_score', 'quality_previous']);
        $items = $rows->map(fn ($c) => ['label' => $c->name, 'value' => $c->quality_score, 'note' => $c->quality_previous !== null ? ($c->quality_score - $c->quality_previous >= 0 ? '+' : '').($c->quality_score - $c->quality_previous) : null])->all();

        return ['result' => $rows->toArray(), 'blocks' => [['type' => 'ranking', 'title' => 'quality', 'items' => $items]]];
    }

    private function propose(string $agent, array $in, ?User $user): array
    {
        $action = (string) ($in['action'] ?? '');

        if (! in_array($action, ActionPolicy::ACTIONS, true)) {
            throw new InvalidArgumentException("Unknown action {$action}.");
        }

        if ($agent === 'creative' && ! in_array($action, self::CREATIVE_ACTIONS, true)) {
            throw new InvalidArgumentException('The Creative Agent proposes changes to ads only.');
        }

        $ad = isset($in['ad_id']) ? Ad::query()->where('uuid', $in['ad_id'])->first() : null;
        $adSet = isset($in['ad_set_id']) ? AdSet::query()->where('uuid', $in['ad_set_id'])->first() : null;
        $campaign = isset($in['campaign_id']) ? Campaign::query()->where('uuid', $in['campaign_id'])->first() : null;
        $campaign ??= $adSet?->campaign ?? $ad?->campaign;

        if ($campaign === null) {
            throw new InvalidArgumentException('A proposal needs a campaign, ad set or ad from this workspace.');
        }

        $proposal = $this->gateway->propose([
            'action' => $action,
            'platform' => $campaign->platform,
            'campaign_id' => $campaign->id,
            'ad_set_id' => $adSet?->id,
            'ad_id' => $ad?->id,
            'project_id' => $campaign->project_id,
            'payload' => is_array($in['payload'] ?? null) ? $in['payload'] : [],
            'reason' => (string) ($in['reason'] ?? ''),
            'expected_impact' => $in['expected_impact'] ?? null,
        ], $user, $agent);

        $view = ProposalPresenter::present($proposal);

        return [
            'result' => ['proposal_id' => $proposal->uuid, 'status' => $proposal->status, 'blocks' => $view['policy']['blocks'], 'note' => 'Waiting for a person to approve. Nothing has changed yet.'],
            'blocks' => [['type' => 'proposal', 'proposal' => $view]],
        ];
    }

    private function report(): array
    {
        $report = $this->reports->weekly();

        return ['result' => ['report_id' => $report->uuid, 'totals' => $report->data['totals']], 'blocks' => [['type' => 'report', 'report_id' => $report->uuid]]];
    }

    private function days(array $in): int
    {
        return max(1, min(90, (int) ($in['days'] ?? 30)));
    }

    // ------------------------------------------------------------- schemas

    private function definition(string $tool): array
    {
        $days = ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'description' => 'How many days back, ending today.'];
        $platform = ['type' => 'string', 'enum' => ['meta', 'tiktok', 'google_ads', 'snapchat', 'x', 'linkedin']];

        return match ($tool) {
            'get_performance' => ['name' => $tool, 'description' => 'Totals across all campaigns — spend, leads, qualified, appointments, won, revenue, CPL, cost per qualified, CPA, ROAS — and qualified leads by platform.', 'input_schema' => ['type' => 'object', 'properties' => ['days' => $days, 'platform' => $platform]]],
            'list_campaigns' => ['name' => $tool, 'description' => 'Campaigns with their cost and what they brought, sorted.', 'input_schema' => ['type' => 'object', 'properties' => ['days' => $days, 'platform' => $platform, 'sort' => ['type' => 'string', 'enum' => ['qualified', 'spend', 'cpql', 'leads']], 'limit' => ['type' => 'integer', 'maximum' => 20]]]],
            'get_campaign' => ['name' => $tool, 'description' => 'One campaign in detail: its figures, its ads with CTR and fatigue, the quality of its leads.', 'input_schema' => ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'string'], 'days' => $days], 'required' => ['campaign_id']]],
            'analyze_campaigns' => ['name' => $tool, 'description' => 'Run the analysis against the client rules: no results, over target, beating target, quality drop, spend spike, over ceiling, fatigue.', 'input_schema' => ['type' => 'object', 'properties' => (object) []]],
            'get_client_rules' => ['name' => $tool, 'description' => 'The client\'s targets and limits.', 'input_schema' => ['type' => 'object', 'properties' => (object) []]],
            'review_creatives' => ['name' => $tool, 'description' => 'Which ads are fatigued, which are weak, which is each campaign\'s best.', 'input_schema' => ['type' => 'object', 'properties' => (object) []]],
            'lead_quality' => ['name' => $tool, 'description' => 'Each campaign\'s lead-quality score (0–100) and its change.', 'input_schema' => ['type' => 'object', 'properties' => ['days' => $days]]],
            'propose_action' => ['name' => $tool, 'description' => 'File a change for a person to approve. Nothing changes until they do. Budgets are daily_budget_minor (minor units).', 'input_schema' => ['type' => 'object', 'properties' => [
                'action' => ['type' => 'string', 'enum' => ActionPolicy::ACTIONS],
                'campaign_id' => ['type' => 'string'], 'ad_set_id' => ['type' => 'string'], 'ad_id' => ['type' => 'string'],
                'payload' => ['type' => 'object'],
                'reason' => ['type' => 'string', 'description' => 'Why, with the figures.'],
                'expected_impact' => ['type' => 'string'],
            ], 'required' => ['action', 'reason']]],
            'draft_creative' => ['name' => $tool, 'description' => 'Save draft copy, hooks or a variation for an ad, for a person to review.', 'input_schema' => ['type' => 'object', 'properties' => [
                'ad_id' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => ['copy', 'hook', 'variation']],
                'items' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 6],
                'rationale' => ['type' => 'string'],
            ], 'required' => ['ad_id', 'kind', 'items']]],
            'build_weekly_report' => ['name' => $tool, 'description' => 'Write and save this week\'s client report.', 'input_schema' => ['type' => 'object', 'properties' => (object) []]],
            'ask_agent' => ['name' => $tool, 'description' => 'Ask a specialist agent and get its answer.', 'input_schema' => ['type' => 'object', 'properties' => [
                'agent' => ['type' => 'string', 'enum' => ['campaign', 'analyst', 'creative', 'leads', 'reports']],
                'question' => ['type' => 'string'],
            ], 'required' => ['agent', 'question']]],
        };
    }
}
