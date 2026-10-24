<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permissions;
use App\Domain\Billing\Entitlements;
use App\Domain\Links\ShortLinks;
use App\Domain\Reports\LiveReport;
use App\Domain\Reports\ReportActivities;
use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\ReportRevisions;
use App\Http\Controllers\Controller;
use App\Models\ReportNote;
use App\Models\ReportShare;
use App\Models\ShortLink;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The rest of the library: a report's life (publish, archive, duplicate), what the team wrote on it, how it was set up
 * over time, what happened to it, the links it has been given (one per client, each with its own protection), the file
 * it can be exported as, and the one place that lists every link a workspace has handed out.
 */
class ReportLibraryController extends Controller
{
    public function __construct(private AuditLogger $audit, private ShortLinks $links) {}

    public function publish(Request $request, string $uuid)
    {
        $share = $this->report($request, $uuid, 'update');
        abort_if($share->state === 'live', 422, 'The report is already live.');
        $before = ReportRevisions::configuration($share);

        $share->forceFill(['state' => 'live', 'published_at' => now(), 'archived_at' => null])->save();
        ReportRevisions::capture($share, $request->user(), $before, ['state']);
        ReportActivities::record($share, 'published', $request->user());
        $this->audit->log('report_share.published', $request->user(), $share, ['after' => ['name' => $share->name]]);

        return ApiResponse::success(['state' => 'live']);
    }

    /** Archived: out of the library's working list, and every link answers "not found" until it is restored. */
    public function archive(Request $request, string $uuid)
    {
        $share = $this->report($request, $uuid, 'update');
        $before = ReportRevisions::configuration($share);

        $share->forceFill(['state' => 'archived', 'archived_at' => now()])->save();
        ReportRevisions::capture($share, $request->user(), $before, ['state']);
        ReportActivities::record($share, 'archived', $request->user());
        $this->audit->log('report_share.archived', $request->user(), $share, ['after' => ['name' => $share->name]]);

        return ApiResponse::success(['state' => 'archived']);
    }

    public function unarchive(Request $request, string $uuid)
    {
        $share = $this->report($request, $uuid, 'update');
        abort_unless($share->state === 'archived', 422, 'The report is not archived.');

        $share->forceFill(['state' => 'draft', 'archived_at' => null])->save();
        ReportActivities::record($share, 'unarchived', $request->user());
        $this->audit->log('report_share.unarchived', $request->user(), $share, ['after' => ['name' => $share->name]]);

        return ApiResponse::success(['state' => 'draft']);
    }

    /** The same configuration under a new name, as a draft, with no link: sharing it is a separate, deliberate step. */
    public function duplicate(Request $request, string $uuid)
    {
        $source = $this->report($request, $uuid, 'view');
        $this->authorize('create', ReportShare::class);

        $copy = $source->replicate(['uuid', 'revision', 'published_at', 'archived_at', 'view_count', 'last_viewed_at', 'token_hash', 'token_enc', 'password_hash']);
        $copy->forceFill(['name' => mb_substr($source->name.' (نسخة)', 0, 120), 'state' => 'draft', 'created_by' => $request->user()->id, 'revision' => 1])->save();
        $copy->syncProjects($source->projectIds());

        foreach ($source->notes as $note) {
            ReportNote::create(['tenant_id' => $copy->tenant_id, 'report_share_id' => $copy->id, 'kind' => $note->kind, 'body' => $note->body, 'revision' => 1, 'is_current' => true, 'author_id' => $request->user()->id]);
        }

        ReportRevisions::capture($copy, $request->user(), []);
        ReportActivities::record($copy, 'created', $request->user(), ['duplicated_from' => $source->uuid]);
        ReportActivities::record($source, 'duplicated', $request->user(), ['copy' => $copy->uuid]);
        $this->audit->log('report_share.duplicated', $request->user(), $copy, ['after' => ['from' => $source->uuid]]);

        return ApiResponse::created(['id' => $copy->uuid]);
    }

    public function notes(Request $request, string $uuid)
    {
        $share = $this->report($request, $uuid, 'view');

        return ApiResponse::success([
            'notes' => $share->notes()->with('author:id,uuid,name')->get()->map(fn (ReportNote $n) => $this->presentNote($n))->values(),
            'history' => ReportNote::where('report_share_id', $share->id)->where('is_current', false)->with('author:id,uuid,name')->latest('id')->limit(50)->get()->map(fn (ReportNote $n) => $this->presentNote($n))->values(),
        ]);
    }

    /** Writes a note. The one it replaces stays, marked not current — who wrote what, and when, is never lost. */
    public function saveNote(Request $request, string $uuid)
    {
        $share = $this->report($request, $uuid, 'update');
        $data = $request->validate(['kind' => ['required', Rule::in(ReportNote::KINDS)], 'body' => ['present', 'nullable', 'string', 'max:2000']]);
        $body = trim(strip_tags((string) ($data['body'] ?? '')));
        $before = ReportRevisions::configuration($share);

        $previous = ReportNote::where('report_share_id', $share->id)->where('kind', $data['kind'])->where('is_current', true)->first();
        $previous?->forceFill(['is_current' => false])->save();

        if ($body !== '') {
            ReportNote::create(['tenant_id' => $share->tenant_id, 'report_share_id' => $share->id, 'kind' => $data['kind'], 'body' => $body, 'revision' => ($previous?->revision ?? 0) + 1, 'is_current' => true, 'author_id' => $request->user()->id]);
        }

        ReportRevisions::capture($share, $request->user(), $before, ['notes']);
        ReportActivities::record($share, 'note_added', $request->user(), ['kind' => $data['kind']]);

        return $this->notes($request, $uuid);
    }

    public function revisions(Request $request, string $uuid)
    {
        $share = $this->report($request, $uuid, 'view');

        return ApiResponse::success(['revisions' => $share->revisions()->with('author:id,uuid,name')->limit(100)->get()->map(fn ($r) => [
            'revision' => $r->revision, 'changed' => $r->changed ?? [], 'configuration' => $r->configuration,
            'by' => $r->author?->name, 'at' => $r->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function activity(Request $request, string $uuid)
    {
        $share = $this->report($request, $uuid, 'view');

        return ApiResponse::success(['activity' => $share->activities()->with('actor:id,uuid,name')->limit(200)->get()->map(fn ($a) => [
            'action' => $a->action, 'by' => $a->actor?->name, 'meta' => $a->meta ?? (object) [], 'at' => $a->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function links(Request $request, string $uuid)
    {
        $share = $this->report($request, $uuid, 'view');

        return ApiResponse::success(['links' => $share->links()->get()->map(fn (ShortLink $l) => $this->presentLink($l, $share))->values()]);
    }

    /** Another address for the same report: its own client, password, expiry and protection. */
    public function addLink(Request $request, string $uuid)
    {
        $share = $this->report($request, $uuid, 'update');
        $data = $this->linkRules($request);
        $this->assertProtectionAllowed($request, $data);

        [$link] = $this->links->issue('report', $share->id, $share->project_id, $request->user(), $this->linkAttributes($data, $share), $data['password'] ?? null);
        ReportActivities::record($share, 'link_created', $request->user(), ['link' => $link->uuid]);
        $this->audit->log('report_share.link_created', $request->user(), $share, ['after' => ['link' => $link->uuid, 'client' => $link->client_name]]);

        return ApiResponse::created(['link' => $this->presentLink($link, $share)]);
    }

    public function updateLink(Request $request, string $uuid, string $link)
    {
        $share = $this->report($request, $uuid, 'update');
        $model = $this->link($share, $link);
        $data = $this->linkRules($request, partial: true);
        $this->assertProtectionAllowed($request, $data);

        $model->fill(array_intersect_key($this->linkAttributes($data, $share, $model), array_flip(array_keys($data)) + ['allowed_emails' => 1, 'allowed_domains' => 1]));

        if (array_key_exists('password', $data)) {
            $model->setPassword($data['password']);
            ReportActivities::record($share, 'password_changed', $request->user(), ['link' => $model->uuid]);
        }

        $model->save();
        ReportActivities::record($share, 'edited', $request->user(), ['link' => $model->uuid]);
        $this->audit->log('report_share.link_updated', $request->user(), $share, ['after' => ['link' => $model->uuid, 'fields' => array_keys($data)]]);

        return ApiResponse::success(['link' => $this->presentLink($model, $share)]);
    }

    public function revokeLink(Request $request, string $uuid, string $link)
    {
        $share = $this->report($request, $uuid, 'update');
        $model = $this->link($share, $link);

        $this->links->revoke($model, $request->user());
        ReportActivities::record($share, 'revoked', $request->user(), ['link' => $model->uuid]);
        $this->audit->log('report_share.link_revoked', $request->user(), $share, ['after' => ['link' => $model->uuid]]);

        return ApiResponse::success(['link' => $this->presentLink($model->refresh(), $share)]);
    }

    public function regenerateLink(Request $request, string $uuid, string $link)
    {
        $share = $this->report($request, $uuid, 'update');
        $model = $this->link($share, $link);

        $this->links->regenerate($model, $request->user());
        ReportActivities::record($share, 'token_regenerated', $request->user(), ['link' => $model->uuid]);
        $this->audit->log('report_share.link_regenerated', $request->user(), $share, ['after' => ['link' => $model->uuid]]);

        return ApiResponse::success(['link' => $this->presentLink($model->refresh(), $share)]);
    }

    /**
     * The report as a file for the team: the sections it has switched on, less whatever this person may not see —
     * the same rule the preview uses, so a file never carries what the screen would hide. Audited, and entitlement-gated.
     */
    public function export(Request $request, string $uuid)
    {
        $share = $this->report($request, $uuid, 'view');
        $format = $request->validate(['format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])]])['format'];
        $entitlements = Entitlements::for($request->user()->tenant);

        abort_unless($entitlements->has('exports'), 403, 'Your plan does not include exports.');
        abort_if($format === 'xlsx' && ! $entitlements->has('report_excel'), 403, 'Your plan does not include Excel reports.');
        abort_if($format === 'pdf' && ! $entitlements->has('report_pdf'), 403, 'Your plan does not include PDF reports.');

        $sees = fn (string $section) => Permissions::sees($request->user(), "report.section.{$section}");
        $data = app(LiveReport::class)->build($share->setRelation('tenant', $request->user()->tenant), 'team', $sees);

        ReportActivities::exported($share, $request->user(), $format, 'team');
        $this->audit->log('report_share.exported', $request->user(), $share, ['after' => ['format' => $format]]);

        return app(ReportExporter::class)->download($share, $data, $format, $request);
    }

    /** Every link the workspace has handed out for a report — the client access center. */
    public function clientAccess(Request $request)
    {
        $this->authorize('viewAny', ReportShare::class);

        $shares = ReportShare::query()->with('creator:id,uuid,name')->get()->filter(fn (ReportShare $s) => $request->user()->can('view', $s))->keyBy('id');
        $rows = ShortLink::query()->where('type', 'report')->whereIn('resource_id', $shares->keys())->latest('id')->limit(500)->get();

        return ApiResponse::success(['links' => $rows->map(fn (ShortLink $l) => $this->presentLink($l, $shares[$l->resource_id]) + [
            'report' => ['id' => $shares[$l->resource_id]->uuid, 'name' => $shares[$l->resource_id]->name, 'state' => $shares[$l->resource_id]->state ?: 'live'],
            'projects' => $shares[$l->resource_id]->projectNames(),
        ])->values()]);
    }

    private function report(Request $request, string $uuid, string $ability): ReportShare
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize($ability, $share);

        return $share;
    }

    private function link(ReportShare $share, string $uuid): ShortLink
    {
        return $share->links()->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function linkRules(Request $request, bool $partial = false): array
    {
        $s = $partial ? 'sometimes' : 'nullable';

        return $request->validate([
            'label' => [$s, 'nullable', 'string', 'max:120'],
            'client_name' => [$s, 'nullable', 'string', 'max:160'],
            'client_email' => [$s, 'nullable', 'email:rfc', 'max:190'],
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:100'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'is_active' => ['sometimes', 'boolean'],
            'allow_export' => ['sometimes', 'boolean'],
            'require_otp' => ['sometimes', 'boolean'],
            'allowed_emails' => ['sometimes', 'nullable', 'array', 'max:50'],
            'allowed_emails.*' => ['email:rfc', 'max:190'],
            'allowed_domains' => ['sometimes', 'nullable', 'array', 'max:20'],
            'allowed_domains.*' => ['string', 'max:120', 'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/i'],
        ]);
    }

    /** Email verification of a client is a plan feature. */
    private function assertProtectionAllowed(Request $request, array $data): void
    {
        $restricts = ! empty($data['require_otp']) || ! empty($data['allowed_emails']) || ! empty($data['allowed_domains']);
        abort_if($restricts && ! Entitlements::for($request->user()->tenant)->has('client_otp'), 403, 'Your plan does not include client email verification.');
    }

    /** @return array<string,mixed> */
    private function linkAttributes(array $data, ReportShare $share, ?ShortLink $current = null): array
    {
        $out = array_intersect_key($data, array_flip(['label', 'client_name', 'client_email', 'expires_at', 'is_active', 'allow_export', 'require_otp']));

        foreach (['allowed_emails', 'allowed_domains'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = array_values(array_unique(array_map('strtolower', (array) $data[$key]))) ?: null;
            }
        }

        if ($current === null) {
            $out['client_name'] ??= $share->client_name;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function presentNote(ReportNote $n): array
    {
        return ['id' => $n->uuid, 'kind' => $n->kind, 'body' => $n->body, 'revision' => $n->revision, 'author' => $n->author?->name, 'at' => $n->created_at?->toIso8601String()];
    }

    /** @return array<string,mixed> */
    private function presentLink(ShortLink $l, ReportShare $share): array
    {
        return [
            'id' => $l->uuid, 'label' => $l->label, 'client_name' => $l->client_name ?: $share->client_name, 'client_email' => $l->client_email,
            'url' => $l->url(), 'status' => $l->status(), 'is_active' => (bool) $l->is_active,
            'has_password' => $l->hasPassword(), 'require_otp' => (bool) $l->require_otp, 'restricted' => $l->isRestrictedToPeople(),
            'allowed_emails' => $l->allowed_emails ?? [], 'allowed_domains' => $l->allowed_domains ?? [], 'allow_export' => (bool) $l->allow_export,
            'expires_at' => $l->expires_at?->toIso8601String(), 'revoked_at' => $l->revoked_at?->toIso8601String(),
            'view_count' => (int) $l->view_count, 'last_viewed_at' => $l->last_viewed_at?->toIso8601String(),
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }
}
