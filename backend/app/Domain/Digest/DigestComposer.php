<?php

namespace App\Domain\Digest;

use App\Domain\Analytics\LeadFunnel;
use App\Domain\Campaigns\CampaignRevenue;
use App\Domain\Leads\InterestEngine;
use App\Domain\Leads\InterestSla;
use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\NotificationPrefs;
use App\Domain\Tracking\TrackingHealth;
use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The three summaries, and the roll-up of alerts somebody asked to hear about later.
 *
 *   daily_sales        what is waiting for a person today — and what happened yesterday
 *   weekly_marketing   the week's spend and what it brought, against the week before
 *   weekly_executive   the funnel, the money, and what is on fire
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
            default => throw new \InvalidArgumentException("Unknown digest {$kind}"),
        };
    }

    private function dailySales(User $user): array
    {
        $yesterday = CarbonImmutable::now()->subDay()->startOfDay();
        $today = $yesterday->addDay();
        $leads = fn () => Lead::query()->visibleTo($user)->reorder();
        $open = ['won', 'lost', 'invalid', 'no_response'];

        $arrived = $leads()->whereBetween('leads.created_at', [$yesterday, $today])->count();
        $won = $leads()->whereBetween('leads.won_at', [$yesterday, $today])->count();
        $interested = $leads()->whereNotIn('leads.status', $open)->whereIn('leads.interest_level', InterestEngine::INTERESTED)->orderBy('leads.interest_level_changed_at')->limit(300)->get(['leads.id', 'leads.tenant_id', 'leads.project_id', 'leads.status', 'leads.full_name', 'leads.interest_level', 'leads.interest_level_changed_at', 'leads.interest_at', 'leads.last_customer_message_at', 'leads.last_contact_at', 'leads.created_at']);
        $late = $interested->filter(fn (Lead $l) => (InterestSla::for($l)['state'] ?? null) === 'breached');

        $followUps = FollowUp::query()->pending()->where('due_at', '<', now())->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))->count();
        $visits = Appointment::query()->whereIn('status', Appointment::BOOKED)->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))->count();
        $requests = Appointment::query()->requested()->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))->count();
        $holds = Reservation::query()->where('status', 'pending')->when(! $user->seesAllLeads(), fn ($q) => $q->whereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)))->count();

        return [
            'kind' => 'daily_sales',
            'title' => 'ملخص المبيعات اليومي',
            'period' => $yesterday->toDateString(),
            'stats' => [
                $this->stat('عملاء مهتمون ينتظرون', $interested->count(), null, $late->isNotEmpty() ? 'bad' : null),
                $this->stat('تجاوزوا مهلة الرد', $late->count(), null, $late->isNotEmpty() ? 'bad' : null),
                $this->stat('متابعات متأخرة', $followUps, null, $followUps > 0 ? 'bad' : null),
                $this->stat('طلبات زيارة', $requests),
                $this->stat('زيارات اليوم', $visits),
                $this->stat('حجوزات بانتظار التأكيد', $holds),
                $this->stat('وصلوا أمس', $arrived),
                $this->stat('صفقات أمس', $won),
            ],
            'lists' => array_values(array_filter([
                $late->isEmpty() ? null : ['title' => 'ابدأ بهؤلاء', 'items' => $late->take(5)->map(fn (Lead $l) => $l->full_name.' — '.$this->level($l->interest_level))->values()->all()],
            ])),
        ];
    }

    private function weeklyMarketing(User $user): array
    {
        $to = CarbonImmutable::now()->endOfDay();
        $report = (new CampaignRevenue($user))->build($to->subDays(6)->startOfDay(), $to);
        $c = $report['comparison'];

        return [
            'kind' => 'weekly_marketing',
            'title' => 'ملخص التسويق الأسبوعي',
            'period' => $report['from'].' → '.$report['to'],
            'stats' => [
                $this->stat('الإنفاق', $c['spend']['current'], $c['spend']['change'], null, true),
                $this->stat('العملاء', $c['leads']['current'], $c['leads']['change']),
                $this->stat('تكلفة العميل', $c['cpl']['current'], $c['cpl']['change'], null, true),
                $this->stat('مهتمون', $c['interested']['current'], $c['interested']['change']),
                $this->stat('زيارات', $c['visits']['current'], $c['visits']['change']),
                $this->stat('حجوزات', $c['reservations']['current'], $c['reservations']['change']),
                $this->stat('صفقات', $c['won']['current'], $c['won']['change']),
                $this->stat('العائد على الإنفاق', $c['roas']['current'] !== null ? $c['roas']['current'].'x' : null, $c['roas']['change']),
            ],
            'lists' => array_values(array_filter([
                $report['rankings']['roas'] === [] ? null : ['title' => 'أفضل عائد', 'items' => collect($report['rankings']['roas'])->map(fn ($r) => "{$r['name']} — {$r['roas']}x")->all()],
                $report['rankings']['wasted'] === [] ? null : ['title' => 'إنفاق بلا تأهيل — راجعه', 'items' => collect($report['rankings']['wasted'])->map(fn ($r) => "{$r['name']} — ".number_format((float) $r['spend']).' ر.س.')->all()],
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
        $marketing = (new CampaignRevenue($user))->build($now->subDays(6)->startOfDay(), $now->endOfDay())['totals'];
        $risk = collect(app(TrackingHealth::class)->build(7)['platforms'])->filter(fn ($p) => $p['connected'] && $p['status'] === 'risk')->count();

        return [
            'kind' => 'weekly_executive',
            'title' => 'الملخص التنفيذي الأسبوعي',
            'period' => $now->subDays(6)->toDateString().' → '.$now->toDateString(),
            'stats' => [
                $this->stat('عملاء جدد', $this_['leads'], $change('leads')),
                $this->stat('مؤهلون', $this_['qualified'], $change('qualified')),
                $this->stat('مهتمون', $this_['interested'], $change('interested')),
                $this->stat('زاروا', $this_['visit'], $change('visit')),
                $this->stat('حجزوا', $this_['reservation'], $change('reservation')),
                $this->stat('اشتروا', $this_['won'], $change('won')),
                $this->stat('الإيراد', number_format($revenue).' ر.س.', null),
                $this->stat('العائد على الإنفاق', $marketing['spend'] ? round($revenue / $marketing['spend'], 2).'x' : null, null),
                $this->stat('منصات في خطر', $risk, null, $risk > 0 ? 'bad' : null),
            ],
            'lists' => [],
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

    /** @return array{label:string,value:string|int|float|null,change:?float,tone:?string,cost:bool} */
    private function stat(string $label, string|int|float|null $value, ?float $change = null, ?string $tone = null, bool $cost = false): array
    {
        return ['label' => $label, 'value' => $value, 'change' => $change, 'tone' => $tone, 'cost' => $cost];
    }

    private function level(?string $level): string
    {
        return ['hot' => 'ساخن', 'interested' => 'مهتم', 'visit_ready' => 'جاهز للزيارة', 'reservation_ready' => 'جاهز للحجز'][$level] ?? '';
    }
}
