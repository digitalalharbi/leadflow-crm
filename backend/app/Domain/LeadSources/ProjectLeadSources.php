<?php

namespace App\Domain\LeadSources;

use App\Domain\Tracking\EventType;
use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\LandingForm;
use App\Models\Lead;
use App\Models\LeadFormMapping;
use App\Models\Project;
use App\Models\SheetSource;
use App\Models\TrackingEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every way a lead reaches one project, on one list: its landing pages, platform lead forms, Google Sheets, WhatsApp, calls, API
 * and imports. They look alike because they are alike: each ends in the same pipeline, and each line's numbers are read from
 * the same leads. Status says only what is known: a page is live, a connection is connected or waiting, a source has a problem.
 */
class ProjectLeadSources
{
    public const CONNECTED = 'connected';

    public const AWAITING = 'awaiting_setup';

    public const ERROR = 'error';

    /** @return array{sources: list<array<string,mixed>>, totals: array<string,int>} */
    public function for(Project $project): array
    {
        $stats = $this->stats($project);
        $sources = collect()
            ->concat($this->pages($project, $stats))
            ->concat($this->platforms($project, $stats))
            ->concat($this->sheets($project, $stats))
            ->concat($this->conversations($project, $stats))
            ->concat($this->feeds($project, $stats));

        return [
            'sources' => $sources->values()->all(),
            'totals' => [
                'sources' => $sources->count(),
                'connected' => $sources->where('status', self::CONNECTED)->count(),
                'leads' => (int) $sources->sum('leads'),
                'sales' => (int) $sources->sum('sales'),
            ],
        ];
    }

    /** Leads of this project grouped by what brought them, one query. */
    private function stats(Project $project): Collection
    {
        $hasAppointment = 'exists (select 1 from appointments a where a.lead_id = leads.id and a.deleted_at is null)';

        return Lead::query()->where('leads.project_id', $project->id)
            ->selectRaw("provider, form_id, landing_form_id, count(*) as leads, count(qualified_at) as qualified, sum(case when {$hasAppointment} then 1 else 0 end) as appointments, count(won_at) as sales, max(leads.created_at) as last_at")
            ->groupBy('provider', 'form_id', 'landing_form_id')->toBase()->get();
    }

    private function line(string $id, string $type, string $name, string $status, Collection $rows, array $extra = []): array
    {
        $leads = (int) $rows->sum('leads');
        $sales = (int) $rows->sum('sales');
        $last = $rows->max('last_at');

        return [
            'id' => $id, 'type' => $type, 'name' => $name, 'status' => $status,
            'leads' => $leads, 'qualified' => (int) $rows->sum('qualified'), 'appointments' => (int) $rows->sum('appointments'), 'sales' => $sales,
            'conversion_rate' => $leads > 0 ? round($sales / $leads * 100, 1) : null,
            'last_lead_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
        ] + $extra + ['tracking_health' => 'not_applicable'];
    }

    private function pages(Project $project, Collection $stats): array
    {
        $forms = LandingForm::query()->where('project_id', $project->id)->where('status', '!=', 'archived')->whereNotNull('page')->latest('id')->get();
        $viewed = TrackingEvent::query()->whereIn('tracking_link_id', $forms->pluck('default_link_id')->filter())->where('event_type', EventType::Visit->value)
            ->where('occurred_at', '>=', now()->subDays(7))->selectRaw('tracking_link_id, count(*) as n')->groupBy('tracking_link_id')->pluck('n', 'tracking_link_id');

        return $forms->map(fn (LandingForm $f) => $this->line($f->uuid, 'landing_page', $f->name, $f->status === 'published' ? self::CONNECTED : self::AWAITING, $stats->where('landing_form_id', $f->id), [
            'state' => $f->status,
            'path' => $f->slug ? '/l/'.$f->slug : null,
            'tracking_health' => $f->status !== 'published' ? 'not_applicable' : (($viewed[$f->default_link_id] ?? 0) > 0 ? 'receiving' : 'quiet'),
        ]))->all();
    }

    private function platforms(Project $project, Collection $stats): array
    {
        $names = [];

        foreach (LeadFormMapping::query()->where('project_id', $project->id)->latest('id')->get() as $m) {
            $connected = Integration::where('provider', $m->provider)->where('is_active', true)->exists();
            $names[] = $this->line($m->uuid, 'platform_form', $m->form_name ?: $m->form_ref, ! $m->is_active ? self::AWAITING : ($connected ? self::CONNECTED : self::AWAITING),
                $stats->where('provider', $m->provider)->where('form_id', $m->form_ref), ['provider' => $m->provider, 'tracking_health' => 'not_applicable']);
        }

        return $names;
    }

    private function sheets(Project $project, Collection $stats): array
    {
        $rows = SheetSource::query()->whereJsonContains('defaults->project_id', $project->id)->latest('id')->get();
        $of = $stats->where('provider', IntegrationProvider::GoogleSheets->value);

        return $rows->map(fn (SheetSource $s) => $this->line($s->uuid, 'google_sheet', (string) ($s->spreadsheet_name ?: $s->sheet_name), ! $s->is_active ? self::AWAITING : (filled($s->last_error ?? null) ? self::ERROR : self::CONNECTED),
            // A project's sheets share these numbers: a lead keeps the provider, not which of its sheets.
            $rows->count() === 1 ? $of : collect()))->all();
    }

    /** WhatsApp and calls are the workspace's; every project has them as a way in, connected or not. */
    private function conversations(Project $project, Collection $stats): array
    {
        $wa = Integration::where('provider', IntegrationProvider::Whatsapp->value)->latest('id')->first();
        $voice = Integration::where('provider', IntegrationProvider::TwilioVoice->value)->latest('id')->first();
        $clicks = TrackingEvent::query()->where('project_id', $project->id)->whereIn('event_type', [EventType::WhatsappClick->value, EventType::CallClick->value])
            ->selectRaw('event_type, count(*) as n')->groupBy('event_type')->pluck('n', 'event_type');

        return [
            $this->line('whatsapp', 'whatsapp', 'WhatsApp', $wa?->is_active ? self::CONNECTED : self::AWAITING, $stats->where('provider', IntegrationProvider::Whatsapp->value), ['clicks' => (int) ($clicks[EventType::WhatsappClick->value] ?? 0)]),
            $this->line('call', 'call', 'Call', $voice?->is_active ? self::CONNECTED : self::AWAITING, collect(), ['clicks' => (int) ($clicks[EventType::CallClick->value] ?? 0)]),
        ];
    }

    private function feeds(Project $project, Collection $stats): array
    {
        $api = $stats->where('provider', IntegrationProvider::GenericWebhook->value);
        $imports = $stats->where('provider', IntegrationProvider::CsvImport->value);

        return [
            $this->line('api', 'api', 'API / Webhooks', $api->isNotEmpty() ? self::CONNECTED : self::AWAITING, $api),
            $this->line('import', 'import', 'Import CSV / Excel', $imports->isNotEmpty() ? self::CONNECTED : self::AWAITING, $imports),
        ];
    }

    /** The project's pages side by side, over the same leads and the same events. @return list<array<string,mixed>> */
    public function compare(Project $project, int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $forms = LandingForm::query()->where('project_id', $project->id)->whereNotNull('page')->where('status', '!=', 'draft')->get();
        $links = DB::table('tracking_links')->whereIn('landing_form_id', $forms->pluck('id'))->pluck('landing_form_id', 'id');
        $views = TrackingEvent::query()->whereIn('tracking_link_id', $links->keys())->where('event_type', EventType::Visit->value)->where('occurred_at', '>=', $from)
            ->selectRaw('tracking_link_id, count(*) as n')->groupBy('tracking_link_id')->pluck('n', 'tracking_link_id');
        $leads = Lead::query()->whereIn('landing_form_id', $forms->pluck('id'))->where('leads.created_at', '>=', $from)
            ->selectRaw('landing_form_id, count(*) as leads, count(qualified_at) as qualified, sum(case when exists (select 1 from appointments a where a.lead_id = leads.id and a.deleted_at is null) then 1 else 0 end) as appointments, count(won_at) as sales, coalesce(sum(deal_value_minor), 0) as revenue_minor')
            ->groupBy('landing_form_id')->toBase()->get()->keyBy('landing_form_id');

        return $forms->map(function (LandingForm $f) use ($links, $views, $leads) {
            $seen = $links->filter(fn ($form) => $form === $f->id)->keys()->sum(fn ($id) => (int) ($views[$id] ?? 0));
            $row = $leads[$f->id] ?? null;
            $n = (int) ($row->leads ?? 0);

            return [
                'id' => $f->uuid, 'name' => $f->name, 'status' => $f->status, 'path' => $f->slug ? '/l/'.$f->slug : null,
                'views' => $seen, 'leads' => $n, 'conversion_rate' => $seen > 0 ? round($n / $seen * 100, 1) : null,
                'qualified' => (int) ($row->qualified ?? 0), 'appointments' => (int) ($row->appointments ?? 0), 'sales' => (int) ($row->sales ?? 0), 'revenue_minor' => (int) ($row->revenue_minor ?? 0),
            ];
        })->sortByDesc('leads')->values()->all();
    }
}
