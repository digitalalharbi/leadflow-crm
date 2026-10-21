<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reports\ReportTemplates;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ReportShare;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'templates' => collect(ReportTemplates::TEMPLATES)->map(fn ($_, $key) => ['key' => $key, 'sections' => ReportTemplates::sectionsFor($key)])->values(),
            'sections' => ReportTemplates::SECTIONS,
            'money' => ReportTemplates::MONEY,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', ReportShare::class);

        $data = $this->validated($request);
        $share = new ReportShare([
            'name' => $data['name'],
            'template' => $data['template'],
            'sections' => isset($data['sections']) ? ReportTemplates::normalise($data['sections']) : ReportTemplates::sectionsFor($data['template']),
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

        if (isset($data['sections'])) {
            $share->sections = ReportTemplates::normalise($data['sections']);
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
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'partner_id' => ['sometimes', 'nullable', 'uuid'],
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:100'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);
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
