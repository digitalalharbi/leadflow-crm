<?php

namespace App\Domain\Tracking\Forms;

use App\Domain\Tracking\EventType;
use App\Models\CampaignSpend;
use App\Models\LandingForm;
use App\Models\LandingFormVersion;
use App\Models\Lead;
use App\Models\TrackingEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How a form is doing, from the trail — and never from what anybody typed.
 *
 * Views, starts, step by step who went on, who finished, how long it took, where the others stopped (a step, a field's key) and what a
 * matrix said once everybody's answers are added up. The events of THIS form's links in THIS period; the numbers are the same events the
 * rest of the tracking hub counts, so the form's screen and the link's screen cannot disagree.
 */
class FormAnalytics
{
    private const SAMPLE = 5000;

    /** @return array<string,mixed> */
    public function for(LandingForm $form, Carbon $from, Carbon $to): array
    {
        $linkIds = $form->links()->pluck('id')->all();
        $snapshot = $form->published_version_id ? $form->publishedVersion?->snapshot : null;
        $snapshot ??= FormDefinition::snapshot($form);
        $steps = FormDefinition::steps($snapshot);

        $base = fn () => TrackingEvent::query()->whereIn('tracking_link_id', $linkIds)->whereBetween('occurred_at', [$from, $to]);

        $views = $linkIds === [] ? 0 : (clone $base())->where('event_type', 'visit')->count();
        $starts = $linkIds === [] ? 0 : (int) (clone $base())->where('event_type', 'form_start')->distinct()->count(DB::raw('coalesce(session_id, visitor_id)'));
        $submits = $linkIds === [] ? 0 : (clone $base())->where('event_type', 'form_submit')->count();

        $done = $linkIds === [] ? [] : (clone $base())->where('event_type', 'form_step')
            ->selectRaw("meta->>'step' as step, count(distinct coalesce(session_id, visitor_id)) as n")->groupBy(DB::raw("meta->>'step'"))->pluck('n', 'step')->all();

        $funnel = [];

        for ($n = 1; $n <= $steps; $n++) {
            $entered = $n === 1 ? $starts : (int) ($done[(string) ($n - 1)] ?? 0);
            $completed = $n === $steps ? $submits : (int) ($done[(string) $n] ?? 0);
            $funnel[] = ['step' => $n, 'entered' => $entered, 'completed' => $completed, 'drop_off' => $entered > 0 ? round(max(0, $entered - $completed) / $entered * 100, 1) : null];
        }

        return [
            'totals' => [
                'views' => $views,
                'starts' => $starts,
                'submits' => $submits,
                'completion_rate' => $starts > 0 ? round($submits / $starts * 100, 1) : null,
                'abandonment_rate' => $starts > 0 ? round(max(0, $starts - $submits) / $starts * 100, 1) : null,
                'avg_completion_seconds' => $linkIds === [] ? null : $this->averageSeconds($base()),
            ],
            'steps' => $funnel,
            'abandoned_at' => $linkIds === [] ? [] : $this->abandoned($base()),
            'matrix' => $linkIds === [] ? [] : $this->matrix($base(), $snapshot),
            'versions' => $this->versions($form, $from, $to),
            'page' => $form->page === null ? null : $this->pagePerformance($form, $linkIds, $from, $to, $views),
        ];
    }

    /** From the first "started" of a visitor's session to their submit, averaged over those who did both. */
    private function averageSeconds($events): ?int
    {
        $submits = (clone $events)->where('event_type', 'form_submit')->whereNotNull('session_id')->limit(self::SAMPLE)->get(['session_id', 'occurred_at']);

        if ($submits->isEmpty()) {
            return null;
        }

        $starts = (clone $events)->where('event_type', 'form_start')->whereIn('session_id', $submits->pluck('session_id'))
            ->selectRaw('session_id, min(occurred_at) as at')->groupBy('session_id')->pluck('at', 'session_id');

        $seconds = $submits->map(fn ($s) => isset($starts[$s->session_id]) ? Carbon::parse($starts[$s->session_id])->diffInSeconds($s->occurred_at, false) : null)
            ->filter(fn ($v) => $v !== null && $v >= 0);

        return $seconds->isEmpty() ? null : (int) round($seconds->avg());
    }

    /** @return list<array{field:?string, step:?int, count:int}> */
    private function abandoned($events): array
    {
        return (clone $events)->where('event_type', 'form_abandon')
            ->selectRaw("meta->>'field' as field, meta->>'step' as step, count(*) as n")->groupBy(DB::raw("meta->>'field'"), DB::raw("meta->>'step'"))
            ->orderByDesc('n')->limit(10)->get()
            ->map(fn ($r) => ['field' => $r->field, 'step' => $r->step !== null ? (int) $r->step : null, 'count' => (int) $r->n])->all();
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return list<array<string,mixed>>
     */
    private function matrix($events, array $snapshot): array
    {
        $fields = collect($snapshot['fields'])->where('type', 'matrix')->keyBy('key');

        if ($fields->isEmpty()) {
            return [];
        }

        $submits = (clone $events)->where('event_type', 'form_submit')->whereNotNull('meta')->latest('occurred_at')->limit(self::SAMPLE)->get(['meta']);
        $out = [];

        foreach ($fields as $key => $f) {
            $rows = [];

            foreach ($f['matrix']['rows'] as $row) {
                $rows[$row['value']] = ['row' => $row['value'], 'label' => $row['label'], 'counts' => array_fill_keys(array_column($f['matrix']['columns'], 'value'), 0), 'answered' => 0];
            }

            foreach ($submits as $e) {
                foreach ((array) ($e->meta['matrix'][$key] ?? []) as $row => $answer) {
                    if (! isset($rows[$row])) {
                        continue;
                    }

                    $rows[$row]['answered']++;

                    foreach ((array) $answer as $col) {
                        if (isset($rows[$row]['counts'][$col])) {
                            $rows[$row]['counts'][$col]++;
                        }
                    }
                }
            }

            $out[] = ['field' => $key, 'label' => $f['label'], 'columns' => $f['matrix']['columns'], 'rows' => array_values($rows)];
        }

        return $out;
    }

    /** @return list<array{version:int, variant:string, submits:int, is_live:bool}> */
    private function versions(LandingForm $form, Carbon $from, Carbon $to): array
    {
        $versions = LandingFormVersion::query()->where('landing_form_id', $form->id)->orderByDesc('version')->get();
        $submits = TrackingEvent::query()->whereIn('form_version_id', $versions->pluck('id'))->where('event_type', 'form_submit')->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('form_version_id, count(*) as n')->groupBy('form_version_id')->pluck('n', 'form_version_id');

        return $versions->map(fn ($v) => ['version' => $v->version, 'variant' => $v->variant, 'submits' => (int) ($submits[$v->id] ?? 0), 'is_live' => $v->id === $form->published_version_id])->all();
    }

    /**
     * A page's own numbers: who came, who acted, and what became of them, read from the same leads the pipeline holds. Cost is
     * shown only when spend is recorded for a campaign this page's leads came from, shared by that campaign's share of the leads;
     * with no recorded spend the cost lines stay null rather than reading as free.
     *
     * @return array<string,mixed>
     */
    private function pagePerformance(LandingForm $form, array $linkIds, Carbon $from, Carbon $to, int $views): array
    {
        $events = TrackingEvent::query()->whereIn('tracking_link_id', $linkIds)->whereBetween('occurred_at', [$from, $to]);
        $count = fn (EventType $type) => (clone $events)->where('event_type', $type->value)->count();
        $leads = Lead::query()->where('landing_form_id', $form->id)->whereBetween('leads.created_at', [$from, $to]);
        $n = (clone $leads)->count();
        $ids = (clone $leads)->select('leads.id');
        $qualified = (clone $leads)->whereNotNull('qualified_at')->count();
        $appointments = DB::table('appointments')->whereIn('lead_id', $ids)->whereNull('deleted_at')->distinct()->count('lead_id');
        $visits = DB::table('appointments')->whereIn('lead_id', $ids)->whereNull('deleted_at')->whereNotNull('completed_at')->distinct()->count('lead_id');
        $reservations = DB::table('reservations')->whereIn('lead_id', $ids)->distinct()->count('lead_id');
        $sales = (clone $leads)->whereNotNull('won_at')->count();
        $revenue = (int) (clone $leads)->whereNotNull('won_at')->sum('deal_value_minor');
        $starts = $count(EventType::FormStart);

        $stages = [['key' => 'view', 'n' => $views], ['key' => 'form_start', 'n' => $starts], ['key' => 'lead', 'n' => $n], ['key' => 'qualified', 'n' => $qualified],
            ['key' => 'appointment', 'n' => $appointments], ['key' => 'visit', 'n' => $visits], ['key' => 'reservation', 'n' => $reservations], ['key' => 'sale', 'n' => $sales]];

        foreach ($stages as $i => &$stage) {
            $before = $i === 0 ? null : $stages[$i - 1]['n'];
            $stage['rate'] = $before ? round($stage['n'] / $before * 100, 1) : null;
        }
        unset($stage);

        $spend = $this->spendShare($form, $from, $to);
        $per = fn (int $count) => $spend !== null && $count > 0 ? (int) round($spend / $count) : null;

        return [
            'unique_visitors' => (clone $events)->where('event_type', EventType::Visit->value)->distinct()->count('visitor_id'),
            'whatsapp_clicks' => $count(EventType::WhatsappClick),
            'call_clicks' => $count(EventType::CallClick),
            'leads' => $n, 'qualified' => $qualified, 'appointments' => $appointments, 'visits' => $visits, 'reservations' => $reservations, 'sales' => $sales, 'revenue_minor' => $revenue,
            'funnel' => $stages,
            'cost' => [
                'spend_minor' => $spend, 'cpl' => $per($n), 'cpql' => $per($qualified), 'per_appointment' => $per($appointments), 'per_reservation' => $per($reservations), 'cpa' => $per($sales),
                'roas' => $spend ? round($revenue / $spend, 2) : null,
            ],
        ];
    }

    private function spendShare(LandingForm $form, Carbon $from, Carbon $to): ?int
    {
        $share = 0;
        $found = false;
        $campaigns = Lead::query()->where('landing_form_id', $form->id)->whereBetween('leads.created_at', [$from, $to])->whereNotNull('campaign_name')
            ->selectRaw('campaign_name, count(*) as n')->groupBy('campaign_name')->pluck('n', 'campaign_name');

        foreach ($campaigns as $campaign => $n) {
            $spend = (int) CampaignSpend::query()->where('campaign_name', $campaign)->whereBetween('spend_date', [$from->toDateString(), $to->toDateString()])->sum('amount_minor');

            if ($spend > 0) {
                $total = Lead::query()->where('campaign_name', $campaign)->whereBetween('leads.created_at', [$from, $to])->count();
                $share += (int) round($spend * $n / max(1, $total));
                $found = true;
            }
        }

        return $found ? $share : null;
    }
}
