<?php

namespace App\Domain\Insights;

use App\Domain\Analytics\LeadFunnel;
use App\Domain\Campaigns\CampaignMetrics;
use App\Domain\Leads\InterestSla;
use App\Domain\Notifications\ImportantAlerts;
use App\Models\Anomaly;
use App\Models\CampaignSpend;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\TenantSetting;
use App\Models\TrackingEvent;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finds the numbers that moved outside their normal range, and says so once.
 *
 * Eight checks, each against what the workspace's own recent days looked like (see Baseline), never
 * against a figure somebody guessed: a lead spike, a lead drop, a cost per lead that jumped, a
 * qualified rate that fell, more leads going unanswered, tracking events that dried up, a campaign
 * spending with nothing qualified, and a sales backlog that keeps growing. A check that has too
 * little history to judge says nothing — an alert from three days of data is a coin toss.
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
    ];

    /** @return list<Anomaly> the findings of this pass — new and already known alike */
    public function run(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $found = [];

        foreach ([
            fn () => $this->leadVolume($today),
            fn () => $this->costPerLead($today),
            fn () => $this->qualifiedRate($today),
            fn () => $this->noResponse($today),
            fn () => $this->trackingEvents($today),
            fn () => $this->spendWithNothingQualified($today),
            fn () => $this->backlog($today),
        ] as $check) {
            foreach ($check() as $finding) {
                $found[] = $this->keep($finding, $today);
            }
        }

        return $found;
    }

    // ---------------------------------------------------------------- the checks

    /** @return list<array<string,mixed>> */
    private function leadVolume(CarbonImmutable $today): array
    {
        $yesterday = $today->subDay();
        $series = $this->leadsPerDay($yesterday->subDays(14), $yesterday);
        $value = (float) ($series[$yesterday->toDateString()] ?? 0);
        $base = Baseline::of(Baseline::fromFirstActivity(collect(range(15, 2))->map(fn (int $ago) => (float) ($series[$today->subDays($ago)->toDateString()] ?? 0))->all()));

        if (Baseline::isSpike($value, $base)) {
            return [$this->finding('lead_spike', '', $value, $base['mean'], $value >= $base['mean'] * 3 ? 'critical' : 'warning')];
        }

        if (Baseline::isDrop($value, $base)) {
            return [$this->finding('lead_drop', '', $value, $base['mean'], $value <= $base['mean'] * 0.2 ? 'critical' : 'warning')];
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function costPerLead(CarbonImmutable $today): array
    {
        $yesterday = $today->subDay();
        $leads = $this->leadsPerDay($yesterday->subDays(14), $yesterday);
        $spend = $this->spendPerDay($yesterday->subDays(14), $yesterday);

        $cpl = fn (CarbonImmutable $d) => ($leads[$d->toDateString()] ?? 0) >= 1 && ($spend[$d->toDateString()] ?? 0) > 0 ? $spend[$d->toDateString()] / $leads[$d->toDateString()] : null;
        $series = collect(range(2, 15))->map(fn (int $ago) => $cpl($today->subDays($ago)))->filter(fn ($v) => $v !== null)->values()->all();
        $value = $cpl($yesterday);
        $base = Baseline::of($series);

        if ($value !== null && ($leads[$yesterday->toDateString()] ?? 0) >= 3 && $base['n'] >= 7 && $value >= $base['mean'] * 1.6) {
            return [$this->finding('cpl_spike', '', round($value, 2), round($base['mean'], 2), $value >= $base['mean'] * 2.5 ? 'critical' : 'warning')];
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function qualifiedRate(CarbonImmutable $today): array
    {
        $rate = function (CarbonImmutable $from, CarbonImmutable $to) {
            $c = LeadFunnel::counts(Lead::query()->reorder()->whereBetween('leads.created_at', [$from->startOfDay(), $to->endOfDay()]));

            return [$c['steps']['leads'], $c['steps']['leads'] > 0 ? $c['steps']['qualified'] / $c['steps']['leads'] * 100 : null];
        };

        [$recentN, $recent] = $rate($today->subDays(7), $today->subDay());
        [$baseN, $base] = $rate($today->subDays(28), $today->subDays(8));

        if ($recent !== null && $base !== null && $recentN >= 30 && $baseN >= 60 && $base >= 10 && $recent <= $base * 0.7) {
            return [$this->finding('qualified_rate_drop', '', round($recent, 1), round($base, 1), $recent <= $base * 0.5 ? 'critical' : 'warning', ['leads' => $recentN])];
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function noResponse(CarbonImmutable $today): array
    {
        $share = function (CarbonImmutable $from, CarbonImmutable $to) {
            $row = Lead::query()->reorder()->whereBetween('leads.created_at', [$from->startOfDay(), $to->endOfDay()])
                ->selectRaw("count(*) as total, count(*) filter (where first_contact_at is null and status = 'new') as silent")->first();

            return [(int) $row->total, (int) $row->total > 0 ? (int) $row->silent / (int) $row->total * 100 : null];
        };

        [$recentN, $recent] = $share($today->subDays(3), $today->subDay());
        [$baseN, $base] = $share($today->subDays(17), $today->subDays(4));

        if ($recent !== null && $base !== null && $recentN >= 8 && $baseN >= 20 && $recent >= 30 && $recent >= max(10, $base) * 2) {
            return [$this->finding('no_response_spike', '', round($recent, 1), round($base, 1), $recent >= 60 ? 'critical' : 'warning', ['leads' => $recentN])];
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function trackingEvents(CarbonImmutable $today): array
    {
        $yesterday = $today->subDay();
        $rows = TrackingEvent::query()->whereBetween('occurred_at', [$yesterday->subDays(14)->startOfDay(), $yesterday->endOfDay()])
            ->selectRaw('occurred_at::date as d, count(*) as n')->groupBy('d')->pluck('n', 'd');

        $value = (float) ($rows[$yesterday->toDateString()] ?? 0);
        $base = Baseline::of(Baseline::fromFirstActivity(collect(range(15, 2))->map(fn (int $ago) => (float) ($rows[$today->subDays($ago)->toDateString()] ?? 0))->all()));

        if (Baseline::isDrop($value, $base, 20)) {
            return [$this->finding('tracking_drop', '', $value, $base['mean'], $value <= $base['mean'] * 0.15 ? 'critical' : 'warning')];
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function spendWithNothingQualified(CarbonImmutable $today): array
    {
        $metrics = new CampaignMetrics(null, $today->subDays(7)->startOfDay(), $today->subDay()->endOfDay());
        $out = [];

        foreach ($metrics->rows() as $row) {
            $recentSpend = collect($row['daily'])->filter(fn ($d) => $d['date'] >= $today->subDays(3)->toDateString())->sum('spend');

            // Spending now, with five or more leads in a week and not one of them qualified.
            if ($recentSpend >= 300 && $row['leads'] >= 5 && $row['qualified'] === 0) {
                $out[] = $this->finding('spend_no_qualified', (string) $row['name'], round((float) $recentSpend, 2), 0.0, (float) $recentSpend >= 2000 ? 'critical' : 'warning', ['leads' => $row['leads']]);
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function backlog(CarbonImmutable $today): array
    {
        $target = (int) (TenantSetting::query()->value('first_contact_target_minutes') ?? 30);

        $uncontacted = Lead::query()->reorder()->whereNull('first_contact_at')->where('status', 'new')->where('created_at', '<=', now()->subMinutes($target))->count();
        $overdue = FollowUp::query()->pending()->where('due_at', '<', now())->count();
        $late = Lead::query()->reorder()->whereIn('interest_level', array_keys(InterestSla::KEYS))->whereNotIn('status', ['won', 'lost', 'invalid', 'no_response'])->limit(500)
            ->get(['id', 'tenant_id', 'project_id', 'status', 'interest_level', 'interest_level_changed_at', 'interest_at', 'last_customer_message_at', 'last_contact_at', 'created_at'])
            ->filter(fn (Lead $l) => (InterestSla::for($l)['state'] ?? null) === 'breached')->count();

        $value = (float) ($uncontacted + $overdue + $late);
        $this->remember($today, 'backlog', $value);

        $history = DB::table('daily_figures')->where('key', 'backlog')->where('tenant_id', app(TenantContext::class)->id())
            ->whereBetween('on_date', [$today->subDays(8)->toDateString(), $today->subDay()->toDateString()])->pluck('value')->map(fn ($v) => (float) $v)->all();
        $base = Baseline::of($history);

        if ($base['n'] >= 3 && $value >= $base['mean'] * 1.5 && $value - $base['mean'] >= 10) {
            return [$this->finding('backlog_growth', '', $value, round($base['mean'], 2), $value >= $base['mean'] * 2.5 ? 'critical' : 'warning', ['uncontacted' => $uncontacted, 'overdue' => $overdue, 'late' => $late])];
        }

        return [];
    }

    // ---------------------------------------------------------------- plumbing

    /** @return array<string,int> date → leads */
    private function leadsPerDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Lead::query()->reorder()->whereBetween('leads.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('leads.created_at::date as d, count(*) as n')->groupBy('d')->pluck('n', 'd')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<string,float> date → money spent that day (platform snapshots plus entered spend) */
    private function spendPerDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $platform = MetricSnapshot::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->selectRaw('date, sum(spend_minor) as s')->groupBy('date')->pluck('s', 'date');
        $manual = CampaignSpend::query()->whereBetween('spend_date', [$from->toDateString(), $to->toDateString()])->selectRaw('spend_date, sum(amount_minor) as s')->groupBy('spend_date')->pluck('s', 'spend_date');

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

        $anomaly->fill(['severity' => $f['severity'], 'observed' => $f['observed'], 'expected' => $f['expected'], 'context' => $f['context']])->save();

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
        };

        return [$title.$who, $body, self::LINK[$f['kind']]];
    }
}
