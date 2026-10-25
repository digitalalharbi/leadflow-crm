<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permissions;
use App\Domain\Billing\Entitlements;
use App\Domain\Links\ShortLinks;
use App\Domain\Mail\EmailDeliveries;
use App\Domain\Mail\OutboundEmail;
use App\Domain\Mail\ReportEmail;
use App\Domain\Reports\LiveReport;
use App\Domain\Reports\ReportActivities;
use App\Domain\Reports\ReportBranding;
use App\Domain\Reports\ReportPresets;
use App\Domain\Reports\ReportRevisions;
use App\Domain\Reports\ReportTemplates;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ReportShare;
use App\Models\ReportShareView;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\ProjectContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The links a workspace hands out to its live reports, and what each is allowed to show.
 *
 * Making, changing, revoking and regenerating a link are all audited: a link is a way into the
 * numbers, and "who opened that door, and when was it shut" has to have an answer.
 */
class ReportShareController extends Controller
{
    public function __construct(private AuditLogger $audit, private ShortLinks $links) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ReportShare::class);

        return ApiResponse::success([
            'shares' => ReportShare::query()->with(['project:id,uuid,name', 'partner:id,uuid,name,show_revenue', 'schedules', 'links', 'creator:id,uuid,name'])->latest('id')->get()->filter(fn (ReportShare $s) => $request->user()->can('view', $s))->values()->map(fn (ReportShare $s) => $this->present($s)),
            // The reports a workspace starts from, ready-made: who each is for, how far back it looks, what it carries.
            'presets' => ReportPresets::all(),
            'templates' => collect(ReportTemplates::TEMPLATES)->map(fn ($_, $key) => ['key' => $key, 'sections' => ReportTemplates::sectionsFor($key), 'layout' => ReportTemplates::layoutFor($key)])->values(),
            'sections' => ReportTemplates::SECTIONS,
            'money' => ReportTemplates::MONEY,
            // The sections this person may not put in a report: they cannot see what they are built from.
            'unavailable' => array_values(array_filter(ReportTemplates::SECTIONS, fn (string $s) => ! Permissions::sees($request->user(), "report.section.{$s}"))),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', ReportShare::class);

        $data = $this->validated($request);
        // A preset fills in what the form leaves out; anything the form says wins.
        $preset = isset($data['preset']) ? ReportPresets::find($data['preset']) : null;
        $template = $data['template'] ?? $preset['template'];
        $this->assertModelAndCurrency(['template' => $template] + $data);
        abort_if(($preset['needs_partner'] ?? false) && empty($data['partner_id']), 422, 'A partner report needs a partner.');
        [$layout, $sections] = $this->shape($data, $template, null);
        $this->assertMaySee($request, $sections);

        $projectIds = $this->projectIds($data);
        abort_if($projectIds === [] && app(ProjectContext::class)->ids() !== null, 422, 'Choose the project this report covers.');
        $this->assertModelAndCurrency($data);

        $share = new ReportShare([
            'name' => $data['name'],
            'state' => $data['state'] ?? 'live',
            'template' => $template,
            'sections' => $sections,
            'layout' => $layout,
            'compare' => (bool) ($data['compare'] ?? $preset['compare'] ?? false),
            'refresh_seconds' => $data['refresh_seconds'] ?? $preset['refresh_seconds'] ?? 60,
            'days' => $data['days'] ?? $preset['days'] ?? 30,
            'partner_id' => $this->partnerId($data),
            'created_by' => $request->user()->id,
        ] + $this->libraryFields($request, $data));
        $share->save();
        $share->syncProjects($projectIds);
        $this->links->issue('report', $share->id, $share->project_id, $request->user(), ['expires_at' => $data['expires_at'] ?? null, 'client_name' => $share->client_name], $data['password'] ?? null);
        ReportActivities::record($share, 'created', $request->user());
        if ($share->state === 'live') {
            ReportActivities::record($share, 'published', $request->user());
        }
        ReportRevisions::capture($share, $request->user(), []);

        $this->audit->log('report_share.created', $request->user(), $share, ['after' => $this->trail($share)]);

        return ApiResponse::created(['share' => $this->present($share->load('project', 'partner', 'links'))]);
    }

    public function update(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $share);

        $data = $this->validated($request, partial: true);
        $before = $this->trail($share);
        $config = ReportRevisions::configuration($share);
        $this->assertModelAndCurrency($data);

        $share->fill(array_filter([
            'name' => $data['name'] ?? null,
            'template' => $data['template'] ?? null,
            'days' => $data['days'] ?? null,
        ], fn ($v) => $v !== null));

        if (isset($data['layout']) || isset($data['sections'])) {
            [$layout, $sections] = $this->shape($data, $share->template, $share);
            $this->assertMaySee($request, $sections, array_filter($share->sections ?? []));
            $share->layout = $layout;
            $share->sections = $sections;
        }

        foreach (['compare', 'refresh_seconds'] as $field) {
            if (array_key_exists($field, $data)) {
                $share->{$field} = $data[$field];
            }
        }

        $share->fill($this->libraryFields($request, $data));

        if (array_key_exists('partner_id', $data)) {
            $share->partner_id = $this->partnerId($data);
        }

        $link = $share->primaryLink();

        if ($link !== null && array_key_exists('expires_at', $data)) {
            $link->expires_at = $data['expires_at'];
        }

        // A password is set by giving one and removed by sending null; leaving it out changes nothing.
        if ($link !== null && array_key_exists('password', $data)) {
            $link->setPassword($data['password']);
        }

        $link?->save();
        $share->save();

        if (array_key_exists('project_id', $data) || array_key_exists('project_ids', $data)) {
            $ids = $this->projectIds($data);
            abort_if($ids === [] && app(ProjectContext::class)->ids() !== null, 422, 'Choose the project this report covers.');
            $share->syncProjects($ids);
        }
        ReportRevisions::capture($share, $request->user(), $config);
        ReportActivities::record($share, 'edited', $request->user(), ['fields' => array_values(array_keys($data))]);
        if (array_key_exists('password', $data)) {
            ReportActivities::record($share, 'password_changed', $request->user());
        }
        $this->audit->log('report_share.updated', $request->user(), $share, ['before' => $before, 'after' => $this->trail($share)]);

        return ApiResponse::success(['share' => $this->present($share->load('project', 'partner', 'links'))]);
    }

    /** Shut the door: the link answers "gone" from now on, and the record stays. */
    public function revoke(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $share);

        $link = $share->primaryLink();
        abort_if($link === null, 404);
        $this->links->revoke($link, $request->user());
        ReportActivities::record($share, 'revoked', $request->user(), ['link' => $link->uuid]);
        $this->audit->log('report_share.revoked', $request->user(), $share, ['after' => ['name' => $share->name]]);

        return ApiResponse::success(['share' => $this->present($share->load('project', 'partner', 'links'))]);
    }

    /** A new link in place of the old: whoever has the old one is locked out, and a revoked report is open again. */
    public function regenerate(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $share);

        $link = $share->primaryLink();
        abort_if($link === null, 404);
        $this->links->regenerate($link, $request->user());
        ReportActivities::record($share, 'token_regenerated', $request->user(), ['link' => $link->uuid]);
        $this->audit->log('report_share.regenerated', $request->user(), $share, ['after' => ['name' => $share->name]]);

        return ApiResponse::success(['share' => $this->present($share->load('project', 'partner', 'links'))]);
    }

    /**
     * Sends the link to a report by email — one queued delivery per recipient, the same message never
     * twice to the same person in the same hour. The email carries the link, not the numbers.
     */
    public function email(Request $request, string $uuid, EmailDeliveries $deliveries)
    {
        $share = ReportShare::where('uuid', $uuid)->with(['tenant.branding', 'tenant.settings'])->firstOrFail();
        $this->authorize('update', $share);
        abort_if($share->status() !== 'active', 422, 'Only an active link can be sent.');

        $data = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email:rfc', 'max:190'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $queued = [];

        foreach (array_values(array_unique(array_map('strtolower', $data['recipients']))) as $address) {
            $mail = ReportEmail::compose($share, $address, $data['message'] ?? null, $request->user());
            $queued[] = $deliveries->queue(
                new OutboundEmail($address, null, $mail['subject'], $mail['html'], null, '', '', hash('sha256', "{$share->id}|{$address}|".now()->format('Y-m-d H')), ['kind' => 'report']),
                ['kind' => 'report', 'subject_type' => 'report_share', 'subject_id' => $share->id],
                $request->user(),
            );
        }

        ReportActivities::record($share, 'shared', $request->user(), ['via' => 'email', 'recipients' => count($queued)]);
        $this->audit->log('report_share.emailed', $request->user(), $share, ['recipients' => count($queued)]);

        return ApiResponse::created(['deliveries' => collect($queued)->map(fn ($d) => ['id' => $d->uuid, 'to' => $d->to_email, 'status' => $d->status, 'provider' => $d->provider])->all()]);
    }

    /**
     * The report as the team sees it: every section that is on, including the ones kept for the team —
     * less whatever this viewer may not see (a person without spend may not read the cost section
     * through a preview any more than through the page it is built from). What was withheld is named.
     */
    public function preview(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->with('partner')->firstOrFail();
        $this->authorize('view', $share);

        $sees = fn (string $section) => Permissions::sees($request->user(), "report.section.{$section}");
        $withheld = array_values(array_filter(array_keys(array_filter($share->sections ?? [])), fn (string $s) => ! $sees($s)));

        return ApiResponse::success(app(LiveReport::class)->build($share->setRelation('tenant', $request->user()->tenant), 'team', $sees) + ['withheld' => $withheld]);
    }

    /** Who opened a link and when: the views over time, how many different visitors, and the last few. */
    public function analytics(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $share);

        $link = $share->primaryLink();
        $views = ReportShareView::query()->where('report_share_id', $share->id);
        $since = now()->subDays(29)->startOfDay();
        $daily = (clone $views)->where('viewed_at', '>=', $since)->selectRaw('viewed_at::date as day, count(*) as n')->groupBy('day')->pluck('n', 'day');

        return ApiResponse::success([
            'total' => (int) $share->links()->sum('view_count'),
            'unique' => (int) (clone $views)->distinct()->count('viewer_hash'),
            'last_viewed_at' => $share->links()->max('last_viewed_at') ? Carbon::parse($share->links()->max('last_viewed_at'))->toIso8601String() : null,
            'daily' => collect(range(0, 29))->map(fn (int $i) => $since->copy()->addDays($i)->toDateString())->map(fn (string $d) => ['date' => $d, 'views' => (int) ($daily[$d] ?? 0)])->all(),
            'recent' => (clone $views)->latest('viewed_at')->limit(8)->get(['viewed_at', 'device'])->map(fn ($v) => ['at' => $v->viewed_at->toIso8601String(), 'device' => $v->device])->all(),
        ]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $share);

        $this->audit->log('report_share.deleted', $request->user(), $share, ['before' => $this->trail($share)]);
        $share->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'preset' => ['sometimes', Rule::in(array_keys(ReportPresets::PRESETS))],
            'template' => [$partial ? 'sometimes' : 'required_without:preset', Rule::in(array_keys(ReportTemplates::TEMPLATES))],
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['boolean'],
            'layout' => ['sometimes', 'array', 'max:40'],
            'layout.*.key' => ['required', 'string', 'max:32'],
            'layout.*.on' => ['required', 'boolean'],
            'layout.*.visible_to' => ['sometimes', Rule::in(ReportTemplates::VISIBILITY)],
            'layout.*.days' => ['sometimes', 'nullable', Rule::in(ReportTemplates::PERIODS)],
            'compare' => ['sometimes', 'boolean'],
            'refresh_seconds' => ['sometimes', 'integer', Rule::in([30, 60, 300, 900])],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'project_ids' => ['sometimes', 'array', 'max:50'],
            'project_ids.*' => ['uuid', 'distinct'],
            'state' => ['sometimes', Rule::in(['draft', 'live'])],
            'client_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'period' => ['sometimes', 'nullable', Rule::in(ReportShare::RANGES)],
            'period_from' => ['required_if:period,custom', 'nullable', 'date'],
            'period_to' => ['required_if:period,custom', 'nullable', 'date', 'after_or_equal:period_from'],
            'compare_mode' => ['sometimes', Rule::in(['previous_period', 'previous_month', 'custom'])],
            'compare_from' => ['required_if:compare_mode,custom', 'nullable', 'date'],
            'compare_to' => ['required_if:compare_mode,custom', 'nullable', 'date', 'after_or_equal:compare_from'],
            'attribution_model' => ['sometimes', Rule::in(ReportShare::MODELS)],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'filters' => ['sometimes', 'nullable', 'array'],
            'filters.platforms' => ['sometimes', 'array', 'max:20'],
            'filters.platforms.*' => ['string', 'max:32'],
            'filters.campaigns' => ['sometimes', 'array', 'max:100'],
            'filters.campaigns.*' => ['uuid'],
            'filters.sources' => ['sometimes', 'array', 'max:50'],
            'filters.sources.*' => ['string', 'max:64'],
            'filters.sales_users' => ['sometimes', 'array', 'max:100'],
            'filters.sales_users.*' => ['uuid'],
            'branding' => ['sometimes', 'nullable', 'array'],
            'branding.name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'branding.color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'branding.logo_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'branding.project_logo_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'branding.footer' => ['sometimes', 'nullable', 'string', 'max:200'],
            'branding.hide_mohtam' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'nullable', 'array'],
            'options.expose_team' => ['sometimes', 'boolean'],
            'options.show_unit_prices' => ['sometimes', 'boolean'],
            'options.show_names' => ['sometimes', 'boolean'],
            'partner_id' => ['sometimes', 'nullable', 'uuid'],
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:100'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);
    }

    /**
     * The layout and the on/off map of a report, from whichever the caller sent: a layout is kept as
     * given (cleaned), a plain map applies its switches to the template's order — or to the layout
     * the report already has.
     *
     * @return array{0:list<array<string,mixed>>,1:array<string,bool>}
     */
    private function shape(array $data, string $template, ?ReportShare $current): array
    {
        if (isset($data['layout'])) {
            $layout = ReportTemplates::normaliseLayout($data['layout'], $template);
        } elseif (isset($data['sections'])) {
            $base = $current ? ReportTemplates::layoutOf($current) : ReportTemplates::layoutFor($template);
            $layout = array_map(fn (array $row) => ['on' => (bool) ($data['sections'][$row['key']] ?? false)] + $row, $base);
        } else {
            $layout = ReportTemplates::layoutFor($template);
        }

        return [$layout, collect($layout)->mapWithKeys(fn (array $row) => [$row['key'] => $row['on']])->all()];
    }

    /**
     * Nobody puts a section in a report that is built from what they may not see: a person who
     * cannot see spend cannot hand out a cost section. Sections already on are left alone.
     *
     * @param  array<string,bool>  $sections
     * @param  array<string,bool>  $already
     */
    private function assertMaySee(Request $request, array $sections, array $already = []): void
    {
        foreach (array_keys(array_filter($sections)) as $section) {
            if (! isset($already[$section]) && ! Permissions::sees($request->user(), "report.section.{$section}")) {
                throw ValidationException::withMessages(['sections' => ["You cannot add the {$section} section."]]);
            }
        }
    }

    private function partnerId(array $data): ?int
    {
        return empty($data['partner_id']) ? null : Partner::where('uuid', $data['partner_id'])->firstOrFail()->id;
    }

    /** @return list<int> the projects a request names — `project_ids`, or the one `project_id` — each one this person may open. */
    private function projectIds(array $data): array
    {
        $uuids = array_values(array_filter((array) ($data['project_ids'] ?? (empty($data['project_id']) ? [] : [$data['project_id']]))));

        if ($uuids === []) {
            return [];
        }

        $ids = Project::whereIn('uuid', $uuids)->pluck('id')->map(fn ($i) => (int) $i)->all();
        abort_if(count($ids) !== count(array_unique($uuids)), 404);

        return $ids;
    }

    /**
     * The settings of the library that the form carries, cleaned: dates, model, currency, the filters (checked to be this
     * workspace's own campaigns and people), branding (a colour, https images, plain text) and options. White-label needs the plan.
     *
     * @return array<string,mixed>
     */
    private function libraryFields(Request $request, array $data): array
    {
        $out = [];

        foreach (['client_name', 'period', 'period_from', 'period_to', 'compare_mode', 'compare_from', 'compare_to', 'attribution_model', 'state'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }

        if (array_key_exists('currency', $data)) {
            $out['currency'] = $data['currency'] ? strtoupper($data['currency']) : null;
        }

        if (array_key_exists('filters', $data)) {
            $f = (array) $data['filters'];
            $campaigns = array_values(array_unique((array) ($f['campaigns'] ?? [])));
            $users = array_values(array_unique((array) ($f['sales_users'] ?? [])));
            abort_if($campaigns !== [] && Campaign::whereIn('uuid', $campaigns)->count() !== count($campaigns), 404);
            abort_if($users !== [] && User::whereIn('uuid', $users)->count() !== count($users), 404);
            $out['filters'] = array_filter(['platforms' => array_values(array_unique((array) ($f['platforms'] ?? []))), 'campaigns' => $campaigns, 'sources' => array_values(array_unique((array) ($f['sources'] ?? []))), 'sales_users' => $users]);
        }

        if (array_key_exists('branding', $data)) {
            $b = (array) $data['branding'];
            $white = Entitlements::for($request->user()->tenant)->has('white_label_reports');
            abort_if((! empty($b['hide_mohtam']) || ! empty($b['logo_url']) || ! empty($b['name'])) && ! $white, 403, 'Your plan does not include branded reports.');
            $out['branding'] = array_filter([
                'name' => isset($b['name']) ? strip_tags((string) $b['name']) : null,
                'color' => $b['color'] ?? null,
                'logo_url' => ReportBranding::url($b['logo_url'] ?? null),
                'project_logo_url' => ReportBranding::url($b['project_logo_url'] ?? null),
                'footer' => isset($b['footer']) ? strip_tags((string) $b['footer']) : null,
                'hide_mohtam' => ! empty($b['hide_mohtam']) ?: null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        if (array_key_exists('options', $data)) {
            $out['options'] = array_map('boolval', array_intersect_key((array) $data['options'], array_flip(['expose_team', 'show_unit_prices', 'show_names'])));
        }

        return $out;
    }

    /** A model other than last-touch belongs to the plans that include advanced attribution; some templates, to advanced templates. */
    private function assertModelAndCurrency(array $data): void
    {
        if (isset($data['template']) && in_array($data['template'], ReportTemplates::ADVANCED, true)) {
            abort_unless(Entitlements::for(request()->user()->tenant)->has('advanced_templates'), 403, 'Your plan does not include this report template.');
        }

        if (isset($data['attribution_model']) && $data['attribution_model'] !== 'last') {
            abort_unless(Entitlements::for(request()->user()->tenant)->has('advanced_attribution'), 403, 'Your plan does not include attribution models.');
        }
    }

    /** What the audit trail remembers: never the token, never the password. */
    private function trail(ReportShare $share): array
    {
        return [
            'name' => $share->name, 'template' => $share->template, 'sections' => $share->sections, 'days' => $share->days,
            'project_id' => $share->project_id, 'partner_id' => $share->partner_id, 'expires_at' => $share->primaryLink()?->expires_at?->toIso8601String(), 'has_password' => $share->hasPassword(),
        ];
    }

    /** @return array<string,mixed> */
    private function present(ReportShare $share): array
    {
        return [
            'id' => $share->uuid,
            'name' => $share->name,
            'state' => $share->state ?: 'live',
            'client_name' => $share->client_name,
            'owner' => $share->relationLoaded('creator') && $share->creator ? ['id' => $share->creator->uuid, 'name' => $share->creator->name] : null,
            'period' => $share->period ?: 'last_n',
            'period_from' => $share->period_from?->toDateString(),
            'period_to' => $share->period_to?->toDateString(),
            'compare_mode' => $share->compare_mode ?: 'previous_period',
            'compare_from' => $share->compare_from?->toDateString(),
            'compare_to' => $share->compare_to?->toDateString(),
            'attribution_model' => $share->attribution_model ?: 'last',
            'currency' => $share->currency,
            'filters' => (object) ($share->filters ?? []),
            'branding' => (object) ($share->branding ?? []),
            'options' => (object) ($share->options ?? []),
            'revision' => (int) $share->revision,
            'projects' => $share->projectNames(),
            'published_at' => $share->published_at?->toIso8601String(),
            'archived_at' => $share->archived_at?->toIso8601String(),
            'links_count' => $share->relationLoaded('links') ? $share->links->count() : null,
            'template' => $share->template,
            // What it really shows: for a partner's report, less than was switched on.
            'sections' => ReportTemplates::effective($share->sections, $share->partner_id ? $share->partner : null),
            'layout' => ReportTemplates::layoutOf($share),
            'compare' => (bool) $share->compare,
            'refresh_seconds' => (int) ($share->refresh_seconds ?: 60),
            'partner' => $share->partner_id ? ['id' => $share->partner?->uuid, 'name' => $share->partner?->name, 'show_revenue' => (bool) $share->partner?->show_revenue] : null,
            'days' => $share->days,
            'project' => $share->relationLoaded('project') && $share->project ? ['id' => $share->project->uuid, 'name' => $share->project->name] : null,
            'url' => $share->url(),
            'status' => $share->status(),
            'has_password' => $share->hasPassword(),
            'expires_at' => $share->primaryLink()?->expires_at?->toIso8601String(),
            'revoked_at' => $share->primaryLink()?->revoked_at?->toIso8601String(),
            'view_count' => (int) $share->links->sum('view_count'),
            'last_viewed_at' => $share->links->max('last_viewed_at')?->toIso8601String(),
            'created_at' => $share->created_at?->toIso8601String(),
            'updated_at' => $share->updated_at?->toIso8601String(),
            // How it sends itself, at a glance; the schedules themselves are managed on their own endpoint.
            'schedules' => $share->relationLoaded('schedules') ? $share->schedules->map(fn ($s) => ['id' => $s->uuid, 'frequency' => $s->frequency, 'enabled' => $s->enabled, 'next_run_at' => $s->next_run_at?->toIso8601String(), 'recipients' => count((array) $s->recipients)])->values()->all() : [],
        ];
    }
}
