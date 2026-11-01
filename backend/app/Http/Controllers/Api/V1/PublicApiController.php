<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\IncomingLead;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Reservation;
use App\Services\Leads\LeadIngestionService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `/api/v1/public/*` — a company's own data for its own systems, through an API key (see AuthenticateApiKey).
 *
 * Every query runs under the key's tenant context, so the same scopes that keep companies apart in the app keep them
 * apart here: another company's id is a 404, never a 403 that confirms it exists. Responses carry UUIDs only — never an
 * internal id — and a deliberately small set of fields, so a key cannot read more than its scope names.
 */
class PublicApiController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private LeadIngestionService $ingestion, private TenantContext $context) {}

    public function leads(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(LeadStatus::class)],
            'project' => ['nullable', 'uuid'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Lead::query()->with(['project:id,uuid,name', 'assignee:id,uuid,name'])->orderByDesc('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($project = $request->input('project')) {
            $query->whereHas('project', fn ($q) => $q->where('uuid', $project));
        }

        if ($since = $request->input('updated_since')) {
            $query->where('updated_at', '>=', $since);
        }

        return $this->page($query->paginate($this->perPage($request)), fn (Lead $lead) => $this->lead($lead));
    }

    public function showLead(string $uuid)
    {
        $lead = Lead::query()->with(['project:id,uuid,name', 'assignee:id,uuid,name'])->where('uuid', $uuid)->first();

        return $lead ? ApiResponse::success($this->lead($lead)) : ApiResponse::error('Not found.', null, 404);
    }

    private function lead(Lead $lead): array
    {
        return [
            'id' => $lead->uuid,
            'full_name' => $lead->full_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'status' => $lead->status?->value,
            'source' => $lead->source,
            'platform' => $lead->platform,
            'campaign' => $lead->campaign_name,
            'project' => $lead->project ? ['id' => $lead->project->uuid, 'name' => $lead->project->name] : null,
            'owner' => $lead->assignee ? ['id' => $lead->assignee->uuid, 'name' => $lead->assignee->name] : null,
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }

    /** Through the same pipeline as every other source: deduplicated, attributed, assigned, automated. */
    public function storeLead(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:40'],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'project' => ['nullable', 'uuid'],
            'source' => ['nullable', 'string', 'max:120'],
            'campaign' => ['nullable', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:191'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
        ]);

        $projectId = null;

        if (! empty($data['project'])) {
            $projectId = Project::query()->where('uuid', $data['project'])->value('id');

            if ($projectId === null) {
                return ApiResponse::error('Unknown project.', ['project' => ['No such project in this workspace.']], 422);
            }
        }

        $incoming = IncomingLead::fromArray(IntegrationProvider::GenericWebhook, [
            'full_name' => $data['full_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'city' => $data['city'] ?? null,
            'project_id' => $projectId,
            'source' => $data['source'] ?? 'api',
            'campaign_name' => $data['campaign'] ?? null,
            'provider_lead_id' => isset($data['external_id']) ? 'api:'.$data['external_id'] : null,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
        ]);

        $result = $this->ingestion->ingest($this->context->get(), $incoming);

        if (! $result->wasAccepted()) {
            return ApiResponse::error('The lead was not accepted.', ['lead' => [$result->reason]], 422);
        }

        $lead = $result->lead->fresh(['project:id,uuid,name', 'assignee:id,uuid,name']);

        return ApiResponse::success(['lead' => $this->lead($lead), 'duplicate' => $result->isDuplicate()], null, $result->isDuplicate() ? 200 : 201);
    }

    public function projects(Request $request)
    {
        return $this->page(Project::query()->orderBy('name')->paginate($this->perPage($request)), fn (Project $p) => [
            'id' => $p->uuid,
            'name' => $p->name,
            'created_at' => $p->created_at?->toIso8601String(),
        ]);
    }

    public function visits(Request $request)
    {
        $query = Appointment::query()->with(['lead:id,uuid', 'project:id,uuid,name'])->orderByDesc('scheduled_at');

        return $this->page($query->paginate($this->perPage($request)), fn (Appointment $a) => [
            'id' => $a->uuid,
            'lead' => $a->lead?->uuid,
            'project' => $a->project ? ['id' => $a->project->uuid, 'name' => $a->project->name] : null,
            'status' => is_object($a->status) ? $a->status->value : $a->status,
            'scheduled_at' => $a->scheduled_at?->toIso8601String(),
        ]);
    }

    public function reservations(Request $request)
    {
        $query = Reservation::query()->with(['lead:id,uuid', 'project:id,uuid,name'])->orderByDesc('id');

        return $this->page($query->paginate($this->perPage($request)), fn (Reservation $r) => [
            'id' => $r->uuid,
            'lead' => $r->lead?->uuid,
            'project' => $r->project ? ['id' => $r->project->uuid, 'name' => $r->project->name] : null,
            'status' => is_object($r->status) ? $r->status->value : $r->status,
            'amount_minor' => $r->amount_minor,
            'reserved_at' => $r->reserved_at?->toIso8601String(),
            'converted_at' => $r->converted_at?->toIso8601String(),
        ]);
    }

    /** Won deals with a recorded value. No value recorded is null — "not available" — never a zero that reads as a result. */
    public function revenue(Request $request)
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $won = Lead::query()->where('status', LeadStatus::Won)->where('deal_value_minor', '>', 0)
            ->when($request->input('from'), fn ($q, $from) => $q->where('won_at', '>=', $from))
            ->when($request->input('to'), fn ($q, $to) => $q->where('won_at', '<=', $to));

        $count = (clone $won)->count();

        return ApiResponse::success([
            'won_deals' => $count,
            'revenue_minor' => $count > 0 ? (int) (clone $won)->sum('deal_value_minor') : null,
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->input('per_page', self::PER_PAGE)));
    }

    private function page(LengthAwarePaginator $page, callable $map)
    {
        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map($map)->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'message' => null,
            'errors' => null,
        ]);
    }
}
