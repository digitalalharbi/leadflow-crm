<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessResolver;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCompanyContext;
use App\Jobs\RunDataExport;
use App\Models\DataExport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/** Exports that run in the background: ask, watch the status, open the file through a short signed link. */
class DataExportController extends Controller
{
    /** At most this many unfinished exports per person — one click, not a queue flood. */
    private const MAX_PENDING = 3;

    /** The filters a lead export may carry; anything else is dropped. */
    private const LEAD_FILTERS = [
        'search', 'status', 'temperature', 'interest_level', 'classification', 'platform', 'project_id', 'assigned_to',
        'date_from', 'date_to', 'overdue', 'priority', 'wrong_fit', 'unassigned', 'data_issue', 'awaiting_contact',
        'replied', 'view', 'campaign_name', 'follow_up', 'tag', 'source', 'qualification_status', 'sort', 'direction',
    ];

    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $rows = DataExport::where('user_id', $request->user()->id)->latest('id')->limit(20)->get();

        return ApiResponse::success(['exports' => $rows->map(fn (DataExport $e) => $this->present($e))->values()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(DataExport::KINDS))],
            'format' => ['required', 'string'],
            'filters' => ['sometimes', 'array'],
        ]);

        abort_unless(in_array($data['format'], DataExport::KINDS[$data['kind']], true), 422, 'That format is not available for this export.');

        $user = $request->user();
        abort_unless($data['kind'] === 'company' ? self::mayExportCompany($user) : self::mayExportLeads($user), 403, 'Your role does not include this.');

        $pending = DataExport::where('user_id', $user->id)->whereIn('status', [DataExport::QUEUED, DataExport::RUNNING])->count();
        if ($pending >= self::MAX_PENDING) {
            return ApiResponse::error('Wait for your exports in progress to finish.', null, 429);
        }

        $filters = null;
        if ($data['kind'] === 'leads') {
            $filters = array_intersect_key((array) ($data['filters'] ?? []), array_flip(self::LEAD_FILTERS));
            if (filled($request->header(ResolveCompanyContext::HEADER))) {
                $filters['_company'] = (string) $request->header(ResolveCompanyContext::HEADER);
            }
        }

        $export = DataExport::create([
            'user_id' => $user->id,
            'kind' => $data['kind'],
            'format' => $data['format'],
            'filters' => $filters,
            'status' => DataExport::QUEUED,
        ]);

        $this->audit->log('data.exported', $user, $export, ['kind' => $export->kind, 'format' => $export->format]);
        RunDataExport::dispatch($export->id, $export->tenant_id);

        return ApiResponse::created(['export' => $this->present($export->fresh())]);
    }

    /** A five-minute link to the file, for the person who made it. */
    public function link(Request $request, string $uuid)
    {
        $export = DataExport::where('uuid', $uuid)->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($export->isDownloadable(), 410, 'This export is no longer available.');

        return ApiResponse::success([
            'url' => URL::temporarySignedRoute('files.export', now()->addMinutes(5), ['export' => $export->uuid, 'tenant' => $export->tenant_id]),
        ]);
    }

    public static function mayExportLeads(User $user): bool
    {
        return $user->usesMatrix() ? AccessResolver::resolve($user, null)->has('leads.export') : $user->seesAllLeads();
    }

    /**
     * The whole company's data, unfiltered: only for someone who already sees the whole company — an admin, or a
     * settings-rights role that belongs to no particular company, is not held to some projects and is not a broker.
     * Settings rights alone are not a way past the limits a person's screens run under.
     */
    public static function mayExportCompany(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->usesMatrix()
            && AccessResolver::resolve($user, null)->has('settings.edit')
            && $user->role !== Role::BrokerViewer
            && ! $user->isProjectScoped()
            && ! $user->companies()->exists();
    }

    private function present(DataExport $e): array
    {
        return [
            'id' => $e->uuid,
            'kind' => $e->kind,
            'format' => $e->format,
            'status' => $e->isDownloadable() || $e->status !== DataExport::READY ? $e->status : DataExport::EXPIRED,
            'rows' => $e->rows,
            'bytes' => $e->bytes,
            'filename' => $e->filename,
            'error' => $e->error,
            'created_at' => $e->created_at?->toIso8601String(),
            'finished_at' => $e->finished_at?->toIso8601String(),
            'expires_at' => $e->expires_at?->toIso8601String(),
        ];
    }
}
