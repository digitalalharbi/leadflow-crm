<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permissions;
use App\Domain\Reports\LiveReport;
use App\Domain\Reports\ReportTemplates;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ReportShare;
use App\Models\ReportShareView;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
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
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ReportShare::class);

        return ApiResponse::success([
            'shares' => ReportShare::query()->with(['project:id,uuid,name', 'partner:id,uuid,name,show_revenue'])->latest('id')->get()->map(fn (ReportShare $s) => $this->present($s)),
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
        [$layout, $sections] = $this->shape($data, $data['template'], null);
        $this->assertMaySee($request, $sections);

        $share = new ReportShare([
            'name' => $data['name'],
            'template' => $data['template'],
            'sections' => $sections,
            'layout' => $layout,
            'compare' => (bool) ($data['compare'] ?? false),
            'refresh_seconds' => $data['refresh_seconds'] ?? 60,
            'days' => $data['days'] ?? 30,
            'project_id' => $this->projectId($data),
            'partner_id' => $this->partnerId($data),
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $share->setPassword($data['password'] ?? null);
        $share->rotateToken();
        $share->save();

        $this->audit->log('report_share.created', $request->user(), $share, ['after' => $this->trail($share)]);

        return ApiResponse::created(['share' => $this->present($share->load('project', 'partner'))]);
    }

    public function update(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $share);

        $data = $this->validated($request, partial: true);
        $before = $this->trail($share);

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

        if (array_key_exists('project_id', $data)) {
            $share->project_id = $this->projectId($data);
        }

        if (array_key_exists('partner_id', $data)) {
            $share->partner_id = $this->partnerId($data);
        }

        if (array_key_exists('expires_at', $data)) {
            $share->expires_at = $data['expires_at'];
        }

        // A password is set by giving one and removed by sending null; leaving it out changes nothing.
        if (array_key_exists('password', $data)) {
            $share->setPassword($data['password']);
        }

        $share->save();
        $this->audit->log('report_share.updated', $request->user(), $share, ['before' => $before, 'after' => $this->trail($share)]);

        return ApiResponse::success(['share' => $this->present($share->load('project', 'partner'))]);
    }

    /** Shut the door: the link answers "gone" from now on, and the record stays. */
    public function revoke(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $share);

        $share->forceFill(['revoked_at' => now()])->save();
        $this->audit->log('report_share.revoked', $request->user(), $share, ['after' => ['name' => $share->name]]);

        return ApiResponse::success(['share' => $this->present($share->load('project', 'partner'))]);
    }

    /** A new link in place of the old: whoever has the old one is locked out, and a revoked report is open again. */
    public function regenerate(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $share);

        $share->rotateToken();
        $share->revoked_at = null;
        $share->save();
        $this->audit->log('report_share.regenerated', $request->user(), $share, ['after' => ['name' => $share->name]]);

        return ApiResponse::success(['share' => $this->present($share->load('project', 'partner'))]);
    }

    /** The report as the team sees it: every section that is on, including the ones kept for the team. */
    public function preview(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->with('partner')->firstOrFail();
        $this->authorize('view', $share);

        return ApiResponse::success(app(LiveReport::class)->build($share->setRelation('tenant', $request->user()->tenant), 'team'));
    }

    /** Who opened a link and when: the views over time, how many different visitors, and the last few. */
    public function analytics(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $share);

        $views = ReportShareView::query()->where('report_share_id', $share->id);
        $since = now()->subDays(29)->startOfDay();
        $daily = (clone $views)->where('viewed_at', '>=', $since)->selectRaw('viewed_at::date as day, count(*) as n')->groupBy('day')->pluck('n', 'day');

        return ApiResponse::success([
            'total' => (int) $share->view_count,
            'unique' => (int) (clone $views)->distinct()->count('viewer_hash'),
            'last_viewed_at' => $share->last_viewed_at?->toIso8601String(),
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
            'template' => [$req, Rule::in(array_keys(ReportTemplates::TEMPLATES))],
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

    private function projectId(array $data): ?int
    {
        if (empty($data['project_id'])) {
            return null;
        }

        return Project::where('uuid', $data['project_id'])->firstOrFail()->id;
    }

    /** What the audit trail remembers: never the token, never the password. */
    private function trail(ReportShare $share): array
    {
        return [
            'name' => $share->name, 'template' => $share->template, 'sections' => $share->sections, 'days' => $share->days,
            'project_id' => $share->project_id, 'partner_id' => $share->partner_id, 'expires_at' => $share->expires_at?->toIso8601String(), 'has_password' => $share->hasPassword(),
        ];
    }

    /** @return array<string,mixed> */
    private function present(ReportShare $share): array
    {
        return [
            'id' => $share->uuid,
            'name' => $share->name,
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
            'expires_at' => $share->expires_at?->toIso8601String(),
            'revoked_at' => $share->revoked_at?->toIso8601String(),
            'view_count' => $share->view_count,
            'last_viewed_at' => $share->last_viewed_at?->toIso8601String(),
            'created_at' => $share->created_at?->toIso8601String(),
        ];
    }
}
