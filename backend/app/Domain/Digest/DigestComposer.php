<?php

namespace App\Domain\Digest;

use App\Domain\Access\Permissions;
use App\Domain\Analytics\LeadFunnel;
use App\Domain\Automation\AutomationCentre;
use App\Domain\Campaigns\CampaignRevenue;
use App\Domain\Insights\AnomalyDetector;
use App\Domain\Leads\InterestEngine;
use App\Domain\Leads\InterestSla;
use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\NotificationPrefs;
use App\Domain\Sales\RepPerformance;
use App\Domain\Tracking\TrackingHealth;
use App\Enums\Platform;
use App\Models\Anomaly;
use App\Models\Appointment;
use App\Models\AutomationActionLog;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Reservation;
use App\Models\TenantSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The three summaries, and the roll-up of alerts somebody asked to hear about later.
 *
 *   daily_sales        what is waiting for a person today — and what happened yesterday
 *   weekly_marketing   the week's spend and what it brought, against the week before
 *   weekly_executive   the funnel, the money, and what is on fire
 *   monthly_executive  the month that just ended, against the one before: the funnel, the money, how the
 *                      team answered and how much the automation did — and the campaigns to keep and to stop
 *
 * Each is returned as plain data — a title, a few figures (with their change against the period
 * before where there is one), lists — so the email, a test and, later, a screen read the same
 * thing. Short on purpose: a summary that lists everything is one people stop opening.
 */
class DigestComposer
{
    /**
     * @return array{kind:string,title:string,period:string,stats:list<array{label:string,value:string|int|float|null,change:?float,tone:?string}>,lists:list<array{title:string,items:list<string>}>}
     */
    public function compose(string $kind, User $user): array
    {
        return match ($kind) {
            'daily_sales' => $this->dailySales($user),
            'weekly_marketing' => $this->weeklyMarketing($user),
            'weekly_executive' => $this->weeklyExecutive($user),
            'monthly_executive' => $this->monthlyExecutive($user),
            default => throw new \InvalidArgumentException("Unknown digest {$kind}"),
        };
    }

    private function dailySales(User $user): array
    {
        $yesterday = CarbonImmutable::now()->subDay()->startOfDay();
        $today = $yesterday->addDay();
        $leads = fn () => Lead::query()->visibleTo($user)->reorder();
        $closed = ['won', 'lost', 'invalid', 'no_response'];

        $arrived = $leads()->whereBetween('leads.created_at', [$yesterday, $today])->count();
        $won = $leads()->whereBetween('leads.won_at', [$yesterday, $today])->count();
        $revenue = round(((int) $leads()->where('leads.status', 'won')->whereBetween('leads.won_at', [$yesterday, $today])->sum('leads.deal_value_minor')) / 100, 2);
        // Nobody has written to them, past the workspace's own first-contact target — the same line the dashboard draws.
        $target = (int) (TenantSetting::query()->value('first_contact_target_minutes') ?? 30);
        $uncontacted = $leads()->whereNull('leads.first_contact_at')->where('leads.status', 'new')->where('leads.created_at', '<=', now()->subMinutes($target))->count();
        $hot = $leads()->whereNotIn('leads.status', $closed)->where('leads.temperature', 'hot')->count();
        $interested = $leads()->whereNotIn('leads.status', $closed)->whereIn('leads.interest_level', InterestEngine::INTERESTED)->orderBy('leads.interest_level_changed_at')->limit(300)->get(['leads.id', 'leads.uuid', 'leads.tenant_id', 'leads.project_id', 'leads.status', 'leads.full_name', 'leads.interest_level', 'leads.interest_level_changed_at', 'leads.interest_at', 'leads.last_customer_message_at', 'leads.last_contact_at', 'leads.created_at']);
        $late = $interested->filter(fn (Lead $l) => (InterestSla::for($l)['state'] ?? null) === 'breached');

        $followUps = FollowUp::query()->pending()->where('due_at', '<', now())->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))->count();
        $visits = Appointment::query()->whereIn('status', Appointment::BOOKED)->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))->count();
        $requests = Appointment::query()->requested()->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))->count();
        $holds = Reservation::query()->where('status', 'pending')->when(! $user->seesAllLeads(), fn ($q) => $q->whereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)))->count();

        $stats = [
            $this->stat('وصلوا أمس', $arrived, link: '/leads?view=new_today'),
            $this->stat('لم يُتواصل معهم', $uncontacted, null, $uncontacted > 0 ? 'bad' : null, link: '/leads?status=new'),
            $this->stat('ساخنون', $hot, link: '/leads?temperature=hot'),
            $this->stat('عملاء مهتمون ينتظرون', $interested->count(), null, $late->isNotEmpty() ? 'bad' : null, link: '/leads?view=interested'),
            $this->stat('تجاوزوا مهلة الرد', $late->count(), null, $late->isNotEmpty() ? 'bad' : null, link: '/work'),
            $this->stat('متابعات متأخرة', $followUps, null, $followUps > 0 ? 'bad' : null, link: '/follow-ups'),
            $this->stat('طلبات زيارة', $requests, link: '/visits'),
            $this->stat('زيارات اليوم', $visits, link: '/visits'),
            $this->stat('حجوزات بانتظار التأكيد', $holds, link: '/visits'),
            $this->stat('صفقات أمس', $won, link: '/leads?status=won'),
        ];

        if (Permissions::has($user, 'revenue.view')) {
            $stats[] = $this->stat('إيراد أمس', number_format($revenue).' ر.س.', link: '/reports');
        }

        // The machinery, for the people who may open it: a rep has no use for the workings.
        if (Permissions::has($user, 'automation.view')) {
            $failed = (int) AutomationActionLog::query()->where('result', AutomationActionLog::FAILED)->where('happened_at', '>=', now()->subDay())->count();
            $stats[] = $this->stat('فشل الأتمتة', $failed, null, $failed > 0 ? 'bad' : null, link: '/automation');
        }

        return [
            'kind' => 'daily_sales',
            'title' => 'ملخص المبيعات اليومي',
            'period' => $yesterday->toDateString(),
            'stats' => $stats,
            'lists' => array_values(array_filter([
                $late->isEmpty() ? null : [
                    'title' => 'ابدأ بهؤلاء',
                    'items' => $late->take(5)->map(fn (Lead $l) => $l->full_name.' — '.$this->level($l->interest_level))->values()->all(),
                    'links' => $late->take(5)->map(fn (Lead $l) => '/leads/'.$l->uuid)->values()->all(),
                ],
            ])),
        ];
    }

    private function weeklyMarketing(User $user): array
    {
        $to = CarbonImmutable::now()->endOfDay();
        $report = (new CampaignRevenue($user))->build($to->subDays(6)->startOfDay(), $to);
        $c = $report['comparison'];

        $stats = [];

        if ($this->sees($user, 'spend')) {
            $stats[] = $this->stat('الإنفاق', $c['spend']['current'], $c['spend']['change'], null, true, '/campaigns');
        }

        array_push(
            $stats,
            $this->stat('العملاء', $c['leads']['current'], $c['leads']['change'], link: '/leads'),
            $this->stat('مؤهلون', $c['qualified']['current'], $c['qualified']['change'], link: '/leads?view=qualified'),
            $this->stat('مهتمون', $c['interested']['current'], $c['interested']['change'], link: '/leads?view=interested'),
            $this->stat('زيارات', $c['visits']['current'], $c['visits']['change'], link: '/visits'),
            $this->stat('حجوزات', $c['reservations']['current'], $c['reservations']['change'], link: '/visits'),
            $this->stat('صفقات', $c['won']['current'], $c['won']['change'], link: '/leads?status=won'),
        );

        if ($this->sees($user, 'revenue')) {
            $stats[] = $this->stat('الإيراد', number_format((float) ($c['revenue']['current'] ?? 0)).' ر.س.', $c['revenue']['change'] ?? null, link: '/reports');
        }

        if ($this->sees($user, 'spend')) {
            $stats[] = $this->stat('تكلفة العميل', $c['cpl']['current'], $c['cpl']['change'], null, true, '/campaigns');
            $stats[] = $this->stat('تكلفة الاكتساب', $c['cac']['current'] ?? null, $c['cac']['change'] ?? null, null, true, '/campaigns');
            $stats[] = $this->stat('العائد على الإنفاق', $c['roas']['current'] !== null ? $c['roas']['current'].'x' : null, $c['roas']['change'], link: '/campaigns');
        }

        return [
            'kind' => 'weekly_marketing',
            'title' => 'ملخص التسويق الأسبوعي',
            'period' => $report['from'].' → '.$report['to'],
            'stats' => $stats,
            'lists' => array_values(array_filter([
                $report['rankings']['roas'] === [] ? null : $this->campaigns('أفضل عائد', $report['rankings']['roas'], fn ($r) => "{$r['name']} — {$r['roas']}x"),
                $report['rankings']['wasted'] === [] || ! $this->sees($user, 'spend') ? null : $this->campaigns('إنفاق بلا تأهيل — راجعه', $report['rankings']['wasted'], fn ($r) => "{$r['name']} — ".number_format((float) $r['spend']).' ر.س.'),
                $this->attribution($report),
                $this->trackingIssues(),
                $this->automationIssues($user, 7),
            ])),
        ];
    }

    private function weeklyExecutive(User $user): array
    {
        $now = CarbonImmutable::now();
        $week = fn (CarbonImmutable $from, CarbonImmutable $to) => LeadFunnel::counts(Lead::query()->visibleTo($user)->whereBetween('leads.created_at', [$from, $to]))['steps'];
        $this_ = $week($now->subDays(6)->startOfDay(), $now->endOfDay());
        $last = $week($now->subDays(13)->startOfDay(), $now->subDays(7)->endOfDay());
        $change = fn (string $k) => ($last[$k] ?? 0) > 0 ? round(($this_[$k] - $last[$k]) / $last[$k] * 100, 1) : null;

        $revenue = round(((int) Lead::query()->visibleTo($user)->reorder()->where('leads.status', 'won')->where('leads.won_at', '>=', $now->subDays(6)->startOfDay())->sum('deal_value_minor')) / 100, 2);
        $report = (new CampaignRevenue($user))->build($now->subDays(6)->startOfDay(), $now->endOfDay());
        $marketing = $report['totals'];
        $c = $report['comparison'];
        $risk = $this->platformsAtRisk();
        $failed = Permissions::has($user, 'automation.view') ? (int) (app(AutomationCentre::class)->summary(7)['cards']['failed']['value'] ?? 0) : null;

        $stats = [
            $this->stat('عملاء جدد', $this_['leads'], $change('leads'), link: '/leads'),
            $this->stat('مؤهلون', $this_['qualified'], $change('qualified'), link: '/leads?view=qualified'),
            $this->stat('مهتمون', $this_['interested'], $change('interested'), link: '/leads?view=interested'),
            $this->stat('زاروا', $this_['visit'], $change('visit'), link: '/visits'),
            $this->stat('حجزوا', $this_['reservation'], $change('reservation'), link: '/visits'),
            $this->stat('اشتروا', $this_['won'], $change('won'), link: '/leads?status=won'),
        ];

        if ($this->sees($user, 'revenue')) {
            $stats[] = $this->stat('الإيراد', number_format($revenue).' ر.س.', null, link: '/reports');
        }

        if ($this->sees($user, 'spend')) {
            $stats[] = $this->stat('الإنفاق', $c['spend']['current'], $c['spend']['change'], null, true, '/campaigns');
            $stats[] = $this->stat('تكلفة العميل', $c['cpl']['current'], $c['cpl']['change'], null, true, '/campaigns');
            $stats[] = $this->stat('تكلفة الاكتساب', $c['cac']['current'] ?? null, $c['cac']['change'] ?? null, null, true, '/campaigns');
            $stats[] = $this->stat('العائد على الإنفاق', $marketing['spend'] ? round($revenue / $marketing['spend'], 2).'x' : null, link: '/campaigns');
        }

        $stats[] = $this->stat('منصات في خطر', count($risk), null, $risk !== [] ? 'bad' : null, link: '/tracking');

        if ($failed !== null) {
            $stats[] = $this->stat('فشل الأتمتة', $failed, null, $failed > 0 ? 'bad' : null, link: '/automation');
        }

        return [
            'kind' => 'weekly_executive',
            'title' => 'الملخص التنفيذي الأسبوعي',
            'period' => $now->subDays(6)->toDateString().' → '.$now->toDateString(),
            'stats' => $stats,
            'lists' => array_values(array_filter([
                $report['rankings']['roas'] === [] || ! $this->sees($user, 'spend') ? null : $this->campaigns('أفضل الحملات', $report['rankings']['roas'], fn ($r) => "{$r['name']} — {$r['roas']}x"),
                $this->attribution($report),
                $this->trackingIssues(),
                $this->automationIssues($user, 7),
            ])),
        ];
    }

    /**
     * The calendar month that has just ended, against the month before it: a short page for the
     * person who answers for the whole workspace. Money only for somebody who may see it.
     */
    private function monthlyExecutive(User $user): array
    {
        $first = CarbonImmutable::now()->startOfMonth()->subMonth();
        $from = $first->startOfDay();
        $to = $first->endOfMonth()->endOfDay();
        $prevFrom = $first->subMonth()->startOfMonth();
        $prevTo = $first->subMonth()->endOfMonth()->endOfDay();

        $funnel = fn (CarbonImmutable $a, CarbonImmutable $b) => LeadFunnel::counts(Lead::query()->visibleTo($user)->whereBetween('leads.created_at', [$a, $b]))['steps'];
        // What was sold in the month — by the day it was won, whatever brought the customer.
        $won = fn (CarbonImmutable $a, CarbonImmutable $b) => round(((int) Lead::query()->visibleTo($user)->reorder()->where('leads.status', 'won')->whereBetween('leads.won_at', [$a, $b])->sum('deal_value_minor')) / 100, 2);
        $now = $funnel($from, $to);
        $before = $funnel($prevFrom, $prevTo);
        $change = fn (string $k) => ($before[$k] ?? 0) > 0 ? round(($now[$k] - $before[$k]) / $before[$k] * 100, 1) : null;
        // A share of the month's leads, and how it moved against the month before.
        $rate = fn (string $k) => $now['leads'] > 0 ? round($now[$k] / $now['leads'] * 100, 1) : null;
        $rateBefore = fn (string $k) => ($before['leads'] ?? 0) > 0 ? round($before[$k] / $before['leads'] * 100, 1) : null;
        $rateStat = fn (string $label, string $k, string $link) => $this->stat($label, $rate($k) !== null ? $rate($k).'%' : null, $rate($k) !== null && $rateBefore($k) ? round($rate($k) - $rateBefore($k), 1) : null, link: $link);

        $report = (new CampaignRevenue($user))->build($from, $to);
        $c = $report['comparison'];
        $team = (new RepPerformance)->build($from, $to, null, false)['team'];
        $automation = app(AutomationCentre::class)->summary(30);
        $risk = $this->platformsAtRisk();

        $stats = [
            $this->stat('عملاء جدد', $now['leads'], $change('leads'), link: '/leads'),
            $this->stat('مؤهلون', $now['qualified'], $change('qualified'), link: '/leads?view=qualified'),
            $this->stat('مهتمون', $now['interested'], $change('interested'), link: '/leads?view=interested'),
            $this->stat('زاروا', $now['visit'], $change('visit'), link: '/visits'),
            $this->stat('حجزوا', $now['reservation'], $change('reservation'), link: '/visits'),
            $this->stat('اشتروا', $now['won'], $change('won'), link: '/leads?status=won'),
            $rateStat('نسبة التأهيل', 'qualified', '/reports'),
            $rateStat('نسبة الاهتمام', 'interested', '/reports'),
            $rateStat('نسبة الإغلاق', 'won', '/reports'),
        ];

        $revenue = $won($from, $to);
        $before_ = $won($prevFrom, $prevTo);

        if ($this->sees($user, 'revenue')) {
            $stats[] = $this->stat('الإيراد', number_format($revenue).' ر.س.', $before_ > 0 ? round(($revenue - $before_) / $before_ * 100, 1) : null, link: '/reports');
        }

        if ($this->sees($user, 'spend')) {
            $stats[] = $this->stat('الإنفاق', number_format((float) ($c['spend']['current'] ?? 0)).' ر.س.', $c['spend']['change'] ?? null, null, true, '/campaigns');
            $stats[] = $this->stat('تكلفة العميل', $c['cpl']['current'] ?? null, $c['cpl']['change'] ?? null, null, true, '/campaigns');
            $stats[] = $this->stat('تكلفة الاكتساب', $c['cac']['current'] ?? null, $c['cac']['change'] ?? null, null, true, '/campaigns');
            $spent = (float) ($c['spend']['current'] ?? 0);
            $stats[] = $this->stat('العائد على الإنفاق', $spent > 0 ? round($revenue / $spent, 2).'x' : null, link: '/campaigns');
        }

        // The team: how fast it answered and how much of what arrived it reached.
        $stats[] = $this->stat('الرد في الوقت', $team['sla_compliance'] !== null ? $team['sla_compliance'].'%' : null, link: '/reports');
        $stats[] = $this->stat('نسبة التواصل', $team['contact_rate'] !== null ? $team['contact_rate'].'%' : null, link: '/reports');
        // The machinery: how much the automation did, how much of it failed, and which platforms stopped reporting.
        $stats[] = $this->stat('نجاح الأتمتة', $automation['success_rate'] !== null ? $automation['success_rate'].'%' : null, link: '/automation');
        $failed = (int) ($automation['cards']['failed']['value'] ?? 0);
        $stats[] = $this->stat('فشل الأتمتة', $failed, null, $failed > 0 ? 'bad' : null, link: '/automation');
        $stats[] = $this->stat('منصات في خطر', count($risk), null, $risk !== [] ? 'bad' : null, link: '/tracking');

        $notes = Anomaly::query()->whereBetween('detected_on', [$from->toDateString(), $to->toDateString()])->orderByRaw("case severity when 'critical' then 0 else 1 end")->latest('detected_on')->limit(5)->get();

        return [
            'kind' => 'monthly_executive',
            'title' => 'الملخص التنفيذي الشهري',
            'period' => $from->toDateString().' → '.$to->toDateString(),
            'stats' => $stats,
            'lists' => array_values(array_filter([
                ! $this->sees($user, 'spend') || $report['rankings']['roas'] === [] ? null : $this->campaigns('أفضل حملات الشهر عائدًا', $report['rankings']['roas'], fn ($r) => "{$r['name']} — {$r['roas']}x"),
                ! $this->sees($user, 'spend') || $report['rankings']['wasted'] === [] ? null : $this->campaigns('إنفاق بلا تأهيل — أوقفه أو راجعه', $report['rankings']['wasted'], fn ($r) => "{$r['name']} — ".number_format((float) $r['spend']).' ر.س.'),
                $this->attribution($report),
                $this->trackingIssues(),
                $notes->isEmpty() ? null : [
                    'title' => 'ملاحظات الشهر',
                    'items' => $notes->map(fn (Anomaly $a) => implode(' — ', array_filter([app(AnomalyDetector::class)->describe($a)['title'], app(AnomalyDetector::class)->describe($a)['body']])))->all(),
                    'links' => $notes->map(fn (Anomaly $a) => app(AnomalyDetector::class)->describe($a)['link'])->all(),
                ],
            ])),
        ];
    }

    // ------------------------------------------------------------------ the lists the summaries share

    private function sees(User $user, string $kind): bool
    {
        return Permissions::has($user, "{$kind}.view");
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{title:string, items:list<string>, links:list<string>}
     */
    private function campaigns(string $title, array $rows, callable $line): array
    {
        return ['title' => $title, 'items' => array_map($line, $rows), 'links' => array_fill(0, count($rows), '/campaigns')];
    }

    /** Where the month's or week's leads and money came from, by the platform that brought them. */
    private function attribution(array $report): ?array
    {
        $rows = collect($report['mix']['platforms'] ?? [])->sortByDesc(fn ($p) => [$p['revenue'], $p['leads']])->take(5)->values();

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'title' => 'الإسناد حسب المنصة',
            'items' => $rows->map(fn ($p) => Platform::labelFor((string) $p['key']).' — '.$p['leads'].' عميل'.($p['revenue'] > 0 ? ' · '.number_format((float) $p['revenue']).' ر.س.' : ''))->all(),
            'links' => $rows->map(fn () => '/tracking')->all(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function platformsAtRisk(): array
    {
        return collect(app(TrackingHealth::class)->build(7)['platforms'])->filter(fn ($p) => $p['connected'] && $p['status'] === 'risk')->values()->all();
    }

    private function trackingIssues(): ?array
    {
        $risk = $this->platformsAtRisk();

        return $risk === [] ? null : [
            'title' => 'مشاكل التتبع',
            'items' => array_map(fn ($p) => Platform::labelFor((string) $p['platform']).' — لا يصل منها ما يكفي، راجع الربط', $risk),
            'links' => array_fill(0, count($risk), '/tracking'),
        ];
    }

    /** What failed or waits for a person in the automation, for those who may open it. */
    private function automationIssues(User $user, int $days): ?array
    {
        if (! Permissions::has($user, 'automation.view')) {
            return null;
        }

        $cards = app(AutomationCentre::class)->summary($days)['cards'];
        $failed = (int) ($cards['failed']['value'] ?? 0);
        $human = (int) ($cards['needs_human']['value'] ?? 0);

        if ($failed === 0 && $human === 0) {
            return null;
        }

        return [
            'title' => 'مشاكل الأتمتة',
            'items' => array_values(array_filter([
                $failed > 0 ? "{$failed} إجراء لم يكتمل" : null,
                $human > 0 ? "{$human} عميل ينتظر مندوبًا بعد الأتمتة" : null,
            ])),
            'links' => array_values(array_filter([$failed > 0 ? '/automation' : null, $human > 0 ? '/leads?view=needs_human' : null])),
        ];
    }

    /**
     * The alerts a person chose to hear about later: everything in the window whose category they
     * set to this mode, newest first, so the summary carries what the instant email would have.
     *
     * @return list<array{title:string,body:string}>
     */
    public function rollup(User $user, string $mode, CarbonInterface $since): array
    {
        $categories = collect(NotificationPrefs::for($user)['alerts'])->filter(fn (string $m) => $m === $mode)->keys();

        if ($categories->isEmpty()) {
            return [];
        }

        $kinds = $categories->flatMap(fn (string $c) => AlertKinds::kindsOf($c))->all();

        return $user->notifications()->where('created_at', '>=', $since)->latest()->limit(100)->get()
            ->map(fn ($n) => $n->data)
            ->filter(fn (array $data) => in_array($data['kind'] ?? null, $kinds, true))
            ->take(20)
            ->map(fn (array $data) => ['title' => (string) ($data['title'] ?? ''), 'body' => (string) ($data['body'] ?? '')])
            ->values()->all();
    }

    /** @return array{label:string,value:string|int|float|null,change:?float,tone:?string,cost:bool,link:?string} */
    private function stat(string $label, string|int|float|null $value, ?float $change = null, ?string $tone = null, bool $cost = false, ?string $link = null): array
    {
        return ['label' => $label, 'value' => $value, 'change' => $change, 'tone' => $tone, 'cost' => $cost, 'link' => $link];
    }

    private function level(?string $level): string
    {
        return ['hot' => 'ساخن', 'interested' => 'مهتم', 'visit_ready' => 'جاهز للزيارة', 'reservation_ready' => 'جاهز للحجز'][$level] ?? '';
    }
}
