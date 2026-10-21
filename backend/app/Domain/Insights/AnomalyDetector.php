<?php

namespace App\Domain\Insights;

use App\Domain\Analytics\LeadFunnel;
use App\Domain\Campaigns\CampaignMetrics;
use App\Domain\Leads\InterestSla;
use App\Domain\Notifications\ImportantAlerts;
use App\Models\Anomaly;
use App\Models\Campaign;
use App\Models\CampaignSpend;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\TenantSetting;
use App\Models\TrackingEvent;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finds the numbers that moved outside their normal range, and says so once.
 *
 * Nine checks, each against what the workspace's own recent days looked like (see Baseline), never
 * against a figure somebody guessed: a lead spike, a lead drop, a cost per lead that jumped, a
 * qualified rate that fell, more leads going unanswered, tracking events that dried up, a campaign
 * spending with nothing qualified, a campaign costed by hand whose spend nobody has entered for days,
 * and a sales backlog that keeps growing. A check that has too little history to judge says
 * nothing — an alert from three days of data is a coin toss.
 *
 * Each finding is stored once per day (the record is the unique key) and alerts the managers only
 * when it is new, so running the pass twice, or every hour, never repeats itself.
 */
class AnomalyDetector
{
    /** @var array<string,string> kind → where to look */
    private const LINK = [
        'lead_spike' => '/leads', 'lead_drop' => '/leads', 'cpl_spike' => '/campaigns', 'qualified_rate_drop' => '/reports',
        'no_response_spike' => '/work', 'tracking_drop' => '/tracking', 'spend_no_qualified' => '/campaigns', 'backlog_growth' => '/work',
        'manual_spend_stale' => '/campaigns',
    ];

    /**
     * The findings of this pass — new and already known alike.
     *
     * The workspace is judged by the workspace's lines. A project that asked for lines of its own is
     * judged separately, by them, over its own leads and campaigns (and its campaigns are left out of
     * the workspace pass, so nothing is judged twice by two different standards).
     *
     * @return list<Anomaly>
     */
    public function run(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $settings = TenantSetting::query()->first();
        $found = [];

        $own = Project::query()->whereNotNull('anomaly_settings')->get()->filter(fn (Project $p) => AnomalySettings::for($settings, $p)->overrides())->values();

        foreach ($this->pass($today, AnomalySettings::for($settings), null, $own->pluck('uuid')->all()) as $finding) {
            $found[] = $this->keep($finding, $today);
        }

        foreach ($own as $project) {
            foreach ($this->pass($today, AnomalySettings::for($settings, $project), $project, []) as $finding) {
                $found[] = $this->keep($finding + ['project' => $project], $today);
            }
        }

        return $found;
    }

    /**
     * One judgement of one scope: every alert that is on, by the lines in force.
     *
     * @param  list<string>  $leaveOut  project uuids whose campaigns another pass will judge
     * @return list<array<string,mixed>>
     */
    private function pass(CarbonImmutable $today, AnomalySettings $cfg, ?Project $project, array $leaveOut): array
    {
        $id = $project?->id;
        $out = [];

        foreach ([
            ['lead_spike', fn () => $this->leadVolume($today, $cfg, $id, 'spike')],
            ['lead_drop', fn () => $this->leadVolume($today, $cfg, $id, 'drop')],
            ['cpl_spike', fn () => $this->costPerLead($today, $cfg, $id)],
            ['qualified_rate_drop', fn () => $this->qualifiedRate($today, $cfg, $id)],
            ['no_response_spike', fn () => $this->noResponse($today, $cfg, $id)],
            // An event carries the project it was recorded for; one recorded for no project is the workspace's alone.
            ['tracking_drop', fn () => $this->trackingEvents($today, $cfg, $id)],
            ['spend_no_qualified', fn () => $this->spendWithNothingQualified($today, $cfg, $id, $leaveOut)],
            ['manual_spend_stale', fn () => $this->manualSpendStale($today, $cfg, $id, $leaveOut)],
            ['backlog_growth', fn () => $this->backlog($today, $cfg, $id)],
        ] as [$kind, $check]) {
            if (! $cfg->enabled($kind)) {
                continue;
            }

            foreach ($check() as $finding) {
                $out[] = $finding;
            }
        }

        return array_map(fn (array $f) => $project !== null && $f['subject'] === '' ? ['subject' => $project->name] + $f : $f, $out);
    }

    // ---------------------------------------------------------------- the checks

    /** @return list<array<string,mixed>> */
    private function leadVolume(CarbonImmutable $today, AnomalySettings $cfg, ?int $projectId, string $direction): array
    {
        $yesterday = $today->subDay();
        $series = $this->leadsPerDay($yesterday->subDays(14), $yesterday, $projectId);
        $value = (float) ($series[$yesterday->toDateString()] ?? 0);
        $base = Baseline::of(Baseline::fromFirstActivity(collect(range(15, 2))->map(fn (int $ago) => (float) ($series[$today->subDays($ago)->toDateString()] ?? 0))->all()));

        if ($direction === 'spike' && Baseline::isSpike($value, $base)) {
            return [$this->finding('lead_spike', '', $value, $base['mean'], $value >= $base['mean'] * 3 ? 'critical' : 'warning')];
        }

        // A drop of `drop_pct`% or more: the lead line the workspace set (60% by default, as it always was).
        $ratio = 1 - $cfg->value('lead_drop') / 100;

        if ($direction === 'drop' && Baseline::isDrop($value, $base, 5, $ratio)) {
            return [$this->finding('lead_drop', '', $value, $base['mean'], $value <= $base['mean'] * $ratio * 0.5 ? 'critical' : 'warning')];
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function costPerLead(CarbonImmutable $today, AnomalySettings $cfg, ?int $projectId): array
    {
        $yesterday = $today->subDay();
        $leads = $this->leadsPerDay($yesterday->subDays(14), $yesterday, $projectId);
        $spend = $this->spendPerDay($yesterday->subDays(14), $yesterday, $projectId, $cfg->enabled('manual_spend_counts'));

        $cpl = fn (CarbonImmutable $d) => ($leads[$d->toDateString()] ?? 0) >= 1 && ($spend[$d->toDateString()] ?? 0) > 0 ? $spend[$d->toDateString()] / $leads[$d->toDateString()] : null;
        $series = collect(range(2, 15))->map(fn (int $ago) => $cpl($today->subDays($ago)))->filter(fn ($v) => $v !== null)->values()->all();
        $value = $cpl($yesterday);
        $base = Baseline::of($series);
        $rise = $cfg->value('cpl_spike') / 100;

        if ($value !== null && ($leads[$yesterday->toDateString()] ?? 0) >= 3 && $base['n'] >= 7 && $value >= $base['mean'] * (1 + $rise)) {
            return [$this->finding('cpl_spike', '', round($value, 2), round($base['mean'], 2), $value >= $base['mean'] * (1 + $rise * 2.5) ? 'critical' : 'warning')];
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function qualifiedRate(CarbonImmutable $today, AnomalySettings $cfg, ?int $projectId): array
    {
        $rate = function (CarbonImmutable $from, CarbonImmutable $to) use ($projectId) {
            $c = LeadFunnel::counts(Lead::query()->reorder()->whereBetween('leads.created_at', [$from->startOfDay(), $to->endOfDay()])->when($projectId, fn ($q) => $q->where('leads.project_id', $projectId)));

            return [$c['steps']['leads'], $c['steps']['leads'] > 0 ? $c['steps']['qualified'] / $c['steps']['leads'] * 100 : null];
        };

        [$recentN, $recent] = $rate($today->subDays(7), $today->subDay());
        [$baseN, $base] = $rate($today->subDays(28), $today->subDays(8));
        $ratio = 1 - $cfg->value('qualified_rate_drop') / 100;

        if ($recent !== null && $base !== null && $recentN >= 30 && $baseN >= 60 && $base >= 10 && $recent <= $base * $ratio) {
            return [$this->finding('qualified_rate_drop', '', round($recent, 1), round($base, 1), $recent <= $base * max(0.05, $ratio - 0.2) ? 'critical' : 'warning', ['leads' => $recentN])];
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function noResponse(CarbonImmutable $today, AnomalySettings $cfg, ?int $projectId): array
    {
        $share = function (CarbonImmutable $from, CarbonImmutable $to) use ($projectId) {
            $row = Lead::query()->reorder()->whereBetween('leads.created_at', [$from->startOfDay(), $to->endOfDay()])->when($projectId, fn ($q) => $q->where('leads.project_id', $projectId))
                ->selectRaw("count(*) as total, count(*) filter (where first_contact_at is null and status = 'new') as silent")->first();

            return [(int) $row->total, (int) $row->total > 0 ? (int) $row->silent / (int) $row->total * 100 : null];
        };

        [$recentN, $recent] = $share($today->subDays(3), $today->subDay());
        [$baseN, $base] = $share($today->subDays(17), $today->subDays(4));
        $line = $cfg->value('no_response_spike');

        if ($recent !== null && $base !== null && $recentN >= 8 && $baseN >= 20 && $recent >= $line && $recent >= max(10, $base) * 2) {
            return [$this->finding('no_response_spike', '', round($recent, 1), round($base, 1), $recent >= min(100, $line * 2) ? 'critical' : 'warning', ['leads' => $recentN])];
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function trackingEvents(CarbonImmutable $today, AnomalySettings $cfg, ?int $projectId = null): array
    {
        $yesterday = $today->subDay();
        $rows = TrackingEvent::query()->whereBetween('occurred_at', [$yesterday->subDays(14)->startOfDay(), $yesterday->endOfDay()])
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->selectRaw('occurred_at::date as d, count(*) as n')->groupBy('d')->pluck('n', 'd');

        $value = (float) ($rows[$yesterday->toDateString()] ?? 0);
        $base = Baseline::of(Baseline::fromFirstActivity(collect(range(15, 2))->map(fn (int $ago) => (float) ($rows[$today->subDays($ago)->toDateString()] ?? 0))->all()));
        $ratio = 1 - $cfg->value('tracking_drop') / 100;

        if (Baseline::isDrop($value, $base, 20, $ratio)) {
            return [$this->finding('tracking_drop', '', $value, $base['mean'], $value <= $base['mean'] * $ratio * 0.375 ? 'critical' : 'warning')];
        }

        return [];
    }

    /**
     * @param  list<string>  $leaveOut  project uuids another pass judges by their own lines
     * @return list<array<string,mixed>>
     */
    private function spendWithNothingQualified(CarbonImmutable $today, AnomalySettings $cfg, ?int $projectId, array $leaveOut): array
    {
        $metrics = new CampaignMetrics(null, $today->subDays(7)->startOfDay(), $today->subDay()->endOfDay());
        $out = [];
        $line = $cfg->value('spend_no_qualified');

        foreach ($metrics->rows($projectId ? ['project_id' => $projectId] : []) as $row) {
            if ($projectId === null && ($row['project']['id'] ?? null) !== null && in_array($row['project']['id'], $leaveOut, true)) {
                continue;
            }

            // Spend somebody typed in only counts when the workspace says it does.
            if (($row['spend_source'] ?? null) === 'manual' && ! $cfg->enabled('manual_spend_counts')) {
                continue;
            }

            // The last three days' spend: the platform's daily figures, or — for a campaign costed by hand — the entries.
            $recentSpend = ($row['spend_source'] ?? null) === 'manual'
                ? (float) CampaignSpend::query()->where('platform', $row['platform'])->where('campaign_name', $row['name'])->where('spend_date', '>=', $today->subDays(3)->toDateString())->sum('amount_minor') / 100
                : collect($row['daily'])->filter(fn ($d) => $d['date'] >= $today->subDays(3)->toDateString())->sum('spend');

            // Spending now, with five or more leads in a week and not one of them qualified.
            if ($recentSpend >= $line && $row['leads'] >= 5 && $row['qualified'] === 0) {
                $out[] = $this->finding('spend_no_qualified', (string) $row['name'], round((float) $recentSpend, 2), 0.0, (float) $recentSpend >= $line * 6.67 ? 'critical' : 'warning', ['leads' => $row['leads']]);
            }
        }

        return $out;
    }

    /**
     * A campaign costed by hand — no platform reports on it — that keeps bringing leads while nobody
     * has entered its spend for more than the allowed days: every cost figure for it is stale.
     *
     * @param  list<string>  $leaveOut  project uuids another pass judges by their own lines
     * @return list<array<string,mixed>>
     */
    private function manualSpendStale(CarbonImmutable $today, AnomalySettings $cfg, ?int $projectId, array $leaveOut): array
    {
        $line = (int) $cfg->value('manual_spend_stale');
        $leaveOutIds = $leaveOut === [] ? [] : Project::query()->whereIn('uuid', $leaveOut)->pluck('id')->all();
        $out = [];

        $campaigns = Campaign::query()->whereNull('archived_at')->where('status', 'active')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($projectId === null && $leaveOutIds !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('project_id')->orWhereNotIn('project_id', $leaveOutIds)))
            ->get(['id', 'name', 'platform', 'project_id']);

        foreach ($campaigns as $campaign) {
            // The platform reports on it: its spend is not anybody's to type in.
            if (MetricSnapshot::query()->where('campaign_id', $campaign->id)->where('date', '>=', $today->subDays(14)->toDateString())->exists()) {
                continue;
            }

            $last = CampaignSpend::query()->where('platform', $campaign->platform)->where('campaign_name', $campaign->name)->max('spend_date');

            // Never costed is a different thing from costed and then forgotten.
            if ($last === null) {
                continue;
            }

            $leads = Lead::query()->reorder()->where('campaign_ref_id', $campaign->id)->where('leads.created_at', '>=', $today->subDays(7)->startOfDay())->count();
            $age = (int) CarbonImmutable::parse($last)->diffInDays($today);

            if ($leads >= 1 && $age > $line) {
                $out[] = $this->finding('manual_spend_stale', (string) $campaign->name, (float) $age, (float) $line, $age > $line * 2 ? 'critical' : 'warning', ['leads' => $leads, 'last_spend_date' => (string) $last]);
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function backlog(CarbonImmutable $today, AnomalySettings $cfg, ?int $projectId): array
    {
        $target = (int) (TenantSetting::query()->value('first_contact_target_minutes') ?? 30);
        $mine = fn ($q) => $q->when($projectId, fn ($w) => $w->where('leads.project_id', $projectId));

        $uncontacted = $mine(Lead::query()->reorder())->whereNull('first_contact_at')->where('status', 'new')->where('leads.created_at', '<=', now()->subMinutes($target))->count();
        $overdue = FollowUp::query()->pending()->where('due_at', '<', now())->when($projectId, fn ($q) => $q->whereHas('lead', fn ($l) => $l->where('leads.project_id', $projectId)))->count();
        $late = $mine(Lead::query()->reorder())->whereIn('interest_level', array_keys(InterestSla::KEYS))->whereNotIn('status', ['won', 'lost', 'invalid', 'no_response'])->limit(500)
            ->get(['id', 'tenant_id', 'project_id', 'status', 'interest_level', 'interest_level_changed_at', 'interest_at', 'last_customer_message_at', 'last_contact_at', 'created_at'])
            ->filter(fn (Lead $l) => (InterestSla::for($l)['state'] ?? null) === 'breached')->count();

        $value = (float) ($uncontacted + $overdue + $late);
        $key = $projectId ? "backlog:{$projectId}" : 'backlog';
        $this->remember($today, $key, $value);

        $history = DB::table('daily_figures')->where('key', $key)->where('tenant_id', app(TenantContext::class)->id())
            ->whereBetween('on_date', [$today->subDays(8)->toDateString(), $today->subDay()->toDateString()])->pluck('value')->map(fn ($v) => (float) $v)->all();
        $base = Baseline::of($history);
        $rise = $cfg->value('backlog_growth') / 100;

        if ($base['n'] >= 3 && $value >= $base['mean'] * (1 + $rise) && $value - $base['mean'] >= 10) {
            return [$this->finding('backlog_growth', '', $value, round($base['mean'], 2), $value >= $base['mean'] * 2.5 ? 'critical' : 'warning', ['uncontacted' => $uncontacted, 'overdue' => $overdue, 'late' => $late])];
        }

        return [];
    }

    // ---------------------------------------------------------------- plumbing

    /** @return array<string,int> date → leads */
    private function leadsPerDay(CarbonImmutable $from, CarbonImmutable $to, ?int $projectId = null): array
    {
        return Lead::query()->reorder()->whereBetween('leads.created_at', [$from->startOfDay(), $to->endOfDay()])->when($projectId, fn ($q) => $q->where('leads.project_id', $projectId))
            ->selectRaw('leads.created_at::date as d, count(*) as n')->groupBy('d')->pluck('n', 'd')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<string,float> date → money spent that day (platform snapshots plus entered spend, when it counts) */
    private function spendPerDay(CarbonImmutable $from, CarbonImmutable $to, ?int $projectId = null, bool $manualCounts = true): array
    {
        $platform = MetricSnapshot::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($projectId, fn ($q) => $q->whereIn('campaign_id', Campaign::query()->where('project_id', $projectId)->select('id')))
            ->selectRaw('date, sum(spend_minor) as s')->groupBy('date')->pluck('s', 'date');

        // Entered spend is kept by platform and campaign name; a project's own judgement takes the
        // entries that name its campaigns.
        $manual = collect();

        if ($manualCounts) {
            $manual = CampaignSpend::query()->whereBetween('spend_date', [$from->toDateString(), $to->toDateString()])
                ->when($projectId, function ($q) use ($projectId) {
                    $pairs = Campaign::query()->where('project_id', $projectId)->get(['platform', 'name']);

                    $q->where(function ($w) use ($pairs) {
                        $w->whereRaw('1 = 0');

                        foreach ($pairs as $c) {
                            $w->orWhere(fn ($x) => $x->where('platform', $c->platform)->where('campaign_name', $c->name));
                        }
                    });
                })
                ->selectRaw('spend_date, sum(amount_minor) as s')->groupBy('spend_date')->pluck('s', 'spend_date');
        }

        $out = [];
        foreach ($platform as $d => $s) {
            $out[Carbon::parse($d)->toDateString()] = ($out[Carbon::parse($d)->toDateString()] ?? 0) + (float) $s / 100;
        }
        foreach ($manual as $d => $s) {
            $out[Carbon::parse($d)->toDateString()] = ($out[Carbon::parse($d)->toDateString()] ?? 0) + (float) $s / 100;
        }

        return $out;
    }

    private function remember(CarbonImmutable $today, string $key, float $value): void
    {
        DB::table('daily_figures')->upsert(
            [['tenant_id' => app(TenantContext::class)->id(), 'on_date' => $today->toDateString(), 'key' => $key, 'value' => $value]],
            ['tenant_id', 'on_date', 'key'], ['value'],
        );
    }

    /** @param  array<string,mixed>  $context @return array<string,mixed> */
    private function finding(string $kind, string $subject, float $observed, float $expected, string $severity, array $context = []): array
    {
        return compact('kind', 'subject', 'observed', 'expected', 'severity', 'context');
    }

    /** Stored once a day; a manager is told the first time and not again. @param  array<string,mixed>  $f */
    private function keep(array $f, CarbonImmutable $today): Anomaly
    {
        $anomaly = Anomaly::firstOrNew(['kind' => $f['kind'], 'subject' => $f['subject'], 'detected_on' => $today->toDateString()]);
        $isNew = ! $anomaly->exists;

        $anomaly->fill(['severity' => $f['severity'], 'observed' => $f['observed'], 'expected' => $f['expected'], 'context' => $f['context'], 'project_id' => ($f['project'] ?? null)?->id])->save();

        if ($isNew && $anomaly->alerted_at === null) {
            ImportantAlerts::budgetAnomaly("{$f['kind']}:{$f['subject']}", ...$this->words($f));
            $anomaly->forceFill(['alerted_at' => now()])->save();
        }

        return $anomaly;
    }

    /** The alert's title and body, and where it points. @param  array<string,mixed>  $f @return array{0:string,1:string,2:string} */
    private function words(array $f): array
    {
        $o = $f['observed'];
        $e = $f['expected'];
        $who = $f['subject'] !== '' ? " — {$f['subject']}" : '';

        [$title, $body] = match ($f['kind']) {
            'lead_spike' => ['قفزة في العملاء', "وصل أمس {$o} عميلًا بينما المعتاد نحو ".round($e).' — تحقق من المصدر.'],
            'lead_drop' => ['هبوط في العملاء', "وصل أمس {$o} فقط بينما المعتاد نحو ".round($e).' — تحقق من الحملات والنماذج.'],
            'cpl_spike' => ['ارتفاع تكلفة العميل', "تكلفة العميل أمس {$o} مقابل المعتاد {$e}."],
            'qualified_rate_drop' => ['انخفاض نسبة التأهيل', "نسبة المؤهلين {$o}% هذا الأسبوع مقابل {$e}% قبله."],
            'no_response_spike' => ['عملاء بلا تواصل', "{$o}% من عملاء آخر ٣ أيام بلا أي تواصل (المعتاد {$e}%)."],
            'tracking_drop' => ['انخفاض أحداث التتبع', "سُجّل أمس {$o} حدثًا بينما المعتاد نحو ".round($e).'.'],
            'spend_no_qualified' => ['إنفاق بلا عملاء مؤهلين', "أُنفق {$o} ر.س. في آخر ٣ أيام دون عميل مؤهل{$who}."],
            'backlog_growth' => ['تراكم في المبيعات', "المتأخر الآن {$o} مقابل المعتاد ".round($e).' — وزّع العمل.'],
            'manual_spend_stale' => ['إنفاق يدوي متوقف', 'آخر إنفاق مُدخَل قبل '.round($o).' أيام لحملة ما زالت تجلب عملاء — تكلفة العميل فيها غير موثوقة حتى يُدخل الإنفاق.'],
        };

        return [$title.$who, $body, self::LINK[$f['kind']]];
    }
}
