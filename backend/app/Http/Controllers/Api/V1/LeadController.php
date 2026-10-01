<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\IncomingLead;
use App\Enums\ActivityType;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeLeadStatusRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Http\Resources\LeadActivityResource;
use App\Http\Resources\LeadListResource;
use App\Http\Resources\LeadNoteResource;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LostReason;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadAssignmentService;
use App\Services\Leads\LeadIngestionService;
use App\Services\Leads\LeadQueryBuilder;
use App\Services\Leads\LeadScorer;
use App\Services\Leads\PhoneNormalizer;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    public function __construct(
        private LeadQueryBuilder $queries,
        private LeadIngestionService $ingestion,
        private LeadAssignmentService $assignment,
        private ActivityLogger $activities,
        private LeadScorer $scorer,
        private PhoneNormalizer $phones,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $perPage = min((int) $request->input('per_page', 25), 100);

        $leads = $this->queries->build($request, $request->user())
            ->with(['assignee:id,uuid,name', 'project:id,uuid,name'])
            ->paginate($perPage)
            ->withQueryString();

        return ApiResponse::paginated($leads, LeadListResource::class);
    }

    public function show(Request $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('view', $lead);

        $lead->load([
            'assignee:id,uuid,name',
            'project:id,uuid,name',
            'lostReason:id,uuid,label',
            'customFieldValues.field',
        ]);

        return ApiResponse::success([
            'lead' => new LeadResource($lead),
            'activities' => LeadActivityResource::collection(
                $lead->activities()->with('user:id,uuid,name')->limit(100)->get()
            ),
            'notes' => LeadNoteResource::collection(
                $lead->notes()->with('user:id,uuid,name')->limit(50)->get()
            ),
            'follow_ups' => \App\Http\Resources\FollowUpResource::collection(
                $lead->followUps()->with('assignee:id,uuid,name')->orderBy('due_at')->get()
            ),
        ]);
    }

    /**
     * Manual creation still goes through the ingestion pipeline, so duplicate
     * detection and assignment behave identically to a webhook lead.
     */
    public function store(StoreLeadRequest $request)
    {
        $data = $request->validated();

        $data['project_id'] = $this->resolveProjectId($data['project_id'] ?? null);
        $data['assigned_to'] = $this->resolveUserId($data['assigned_to'] ?? null);

        $result = $this->ingestion->ingest(
            $request->user()->tenant,
            IncomingLead::fromArray(IntegrationProvider::Manual, $data),
        );

        if (! $result->wasAccepted()) {
            throw ValidationException::withMessages(['phone' => [$result->reason]]);
        }

        $lead = $result->lead;
        $lead->forceFill(['created_by' => $request->user()->id])->saveQuietly();

        // A rep adding a lead by hand owns it unless a manager said otherwise.
        if (! $lead->assigned_to && $request->user()->isSales()) {
            $this->assignment->assign($lead, $request->user(), $request->user(), notify: false);
        }

        $fresh = $lead->fresh(['assignee:id,uuid,name', 'project:id,uuid,name', 'lostReason:id,uuid,label']);

        if ($result->isDuplicate()) {
            return ApiResponse::success(
                ['lead' => new LeadResource($fresh), 'duplicate' => true],
                'This phone number already exists. The existing lead was updated and marked as re-engaged.',
            );
        }

        return ApiResponse::created(['lead' => new LeadResource($fresh), 'duplicate' => false]);
    }

    public function update(UpdateLeadRequest $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('update', $lead);

        $data = $request->validated();

        if (array_key_exists('project_id', $data)) {
            $data['project_id'] = $this->resolveProjectId($data['project_id']);
        }

        if (array_key_exists('phone', $data) && filled($data['phone'])) {
            $normalized = $this->phones->normalize(
                $data['phone'],
                $request->user()->tenant->settings?->default_country_code ?? '966'
            );

            if ($normalized === null) {
                throw ValidationException::withMessages(['phone' => ['This phone number is not valid.']]);
            }

            // Changing a phone must not collide with another lead in this tenant.
            $clash = Lead::where('phone_normalized', $normalized)->where('id', '!=', $lead->id)->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'phone' => ['Another lead in your organisation already uses this phone number.'],
                ]);
            }

            $data['phone_normalized'] = $normalized;
        }

        $custom = $data['custom'] ?? null;
        unset($data['custom']);

        $lead->fill($data)->save();

        if ($custom !== null) {
            $this->syncCustomFields($lead, $custom);
        }

        $this->activities->log($lead, ActivityType::Updated, $request->user(), null, [
            'fields' => array_keys($data),
        ]);

        return ApiResponse::success(['lead' => new LeadResource($lead->fresh(['assignee', 'project', 'lostReason']))]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('delete', $lead);

        $lead->delete(); // soft delete — nothing is destroyed
        $this->audit->log('lead.deleted', $request->user(), $lead);

        return ApiResponse::success(null, 'Lead deleted.');
    }

    public function assign(Request $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('assign', $lead);

        $request->validate(['assigned_to' => ['nullable', 'uuid']]);

        $assignee = null;

        if ($request->filled('assigned_to')) {
            $assignee = User::where('tenant_id', $lead->tenant_id)
                ->where('uuid', $request->input('assigned_to'))
                ->where('is_active', true)
                ->first();

            if (! $assignee) {
                throw ValidationException::withMessages(['assigned_to' => ['This user is not available.']]);
            }
        }

        $this->assignment->assign($lead, $assignee, $request->user());

        return ApiResponse::success(['lead' => new LeadResource($lead->fresh(['assignee', 'project']))]);
    }

    public function changeStatus(ChangeLeadStatusRequest $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('update', $lead);

        $new = LeadStatus::from($request->input('status'));
        $previous = $lead->status;

        if ($new === $previous) {
            return ApiResponse::success(['lead' => new LeadResource($lead->fresh(['assignee', 'project', 'lostReason']))]);
        }

        $lead->status = $new;

        // Status changes carry side effects the reports depend on.
        if ($new === LeadStatus::Lost) {
            $reason = LostReason::where('uuid', $request->input('lost_reason_id'))->first();

            if (! $reason) {
                throw ValidationException::withMessages(['lost_reason_id' => ['Unknown lost reason.']]);
            }

            $lead->lost_reason_id = $reason->id;
            $lead->lost_note = $request->input('lost_note');
            $lead->lost_at = now();
            $lead->won_at = null;
        } elseif ($new === LeadStatus::Won) {
            $lead->won_at = now();
            $lead->lost_at = null;
            $lead->lost_reason_id = null;
            $lead->next_follow_up_at = null;
        } else {
            $lead->won_at = null;
            $lead->lost_at = null;
            $lead->lost_reason_id = null;
        }

        if (in_array($new, [LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::Appointment], true)) {
            $lead->last_contact_at = now();
        }

        $lead->score = $this->scorer->rescore($lead);
        $lead->temperature = LeadTemperature::fromScore(
            $lead->score,
            $lead->tenant->settings?->score_warm_threshold ?? 40,
            $lead->tenant->settings?->score_hot_threshold ?? 70,
        );

        $lead->save();

        $this->activities->log($lead, ActivityType::StatusChanged, $request->user(), null, [
            'from' => $previous?->value,
            'to' => $new->value,
        ]);

        if ($new === LeadStatus::Won) {
            $this->activities->log($lead, ActivityType::Won, $request->user());
        } elseif ($new === LeadStatus::Lost) {
            $this->activities->log($lead, ActivityType::Lost, $request->user(), null, [
                'reason' => $lead->lostReason?->label,
            ]);
        } elseif ($new === LeadStatus::Appointment) {
            $this->activities->log($lead, ActivityType::AppointmentCreated, $request->user());
        }

        return ApiResponse::success(['lead' => new LeadResource($lead->fresh(['assignee', 'project', 'lostReason']))]);
    }

    public function changeTemperature(Request $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('update', $lead);

        $request->validate([
            'temperature' => ['required', \Illuminate\Validation\Rule::in(LeadTemperature::values())],
        ]);

        $previous = $lead->temperature;
        $lead->temperature = LeadTemperature::from($request->input('temperature'));
        $lead->save();

        $this->activities->log($lead, ActivityType::TemperatureChanged, $request->user(), null, [
            'from' => $previous?->value,
            'to' => $lead->temperature->value,
        ]);

        return ApiResponse::success(['lead' => new LeadResource($lead->fresh(['assignee', 'project']))]);
    }

    public function addNote(Request $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('update', $lead);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $note = LeadNote::create([
            'lead_id' => $lead->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $this->activities->log($lead, ActivityType::NoteAdded, $request->user(), \Illuminate\Support\Str::limit($data['body'], 120));

        return ApiResponse::created(['note' => new LeadNoteResource($note->load('user:id,uuid,name'))]);
    }

    /**
     * Records that a rep opened WhatsApp or placed a call. The client performs
     * the actual wa.me / tel: navigation; we only keep the trail.
     */
    public function logContact(Request $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('update', $lead);

        $data = $request->validate([
            'channel' => ['required', 'in:call,whatsapp,email,no_answer'],
        ]);

        $type = match ($data['channel']) {
            'call' => ActivityType::Called,
            'whatsapp' => ActivityType::WhatsappClicked,
            'email' => ActivityType::EmailSent,
            'no_answer' => ActivityType::NoAnswer,
        };

        if ($data['channel'] !== 'no_answer') {
            $lead->last_contact_at = now();

            // First real contact moves the lead out of "new" automatically.
            if ($lead->status === LeadStatus::New) {
                $lead->status = LeadStatus::Contacted;
                $this->activities->log($lead, ActivityType::StatusChanged, $request->user(), null, [
                    'from' => LeadStatus::New->value,
                    'to' => LeadStatus::Contacted->value,
                    'automatic' => true,
                ]);
            }

            $lead->save();
        }

        $this->activities->log($lead, $type, $request->user());

        return ApiResponse::success(['lead' => new LeadResource($lead->fresh(['assignee', 'project']))]);
    }

    /** CSV export of the current filter selection. Streamed so large tenants work. */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', Lead::class);

        $query = $this->queries->build($request, $request->user())
            ->with(['assignee:id,uuid,name', 'project:id,uuid,name']);

        $filename = 'leads-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel renders Arabic correctly

            fputcsv($out, [
                'Name', 'Phone', 'Email', 'City', 'Project', 'Platform', 'Campaign',
                'Status', 'Temperature', 'Score', 'Assigned To', 'Next Follow-up', 'Created At',
            ]);

            $query->chunk(500, function ($leads) use ($out) {
                foreach ($leads as $lead) {
                    fputcsv($out, [
                        $lead->full_name,
                        $lead->phone_normalized ?? $lead->phone,
                        $lead->email,
                        $lead->city,
                        $lead->project?->name,
                        $lead->platform,
                        $lead->campaign_name,
                        $lead->status?->value,
                        $lead->temperature?->value,
                        $lead->score,
                        $lead->assignee?->name,
                        $lead->next_follow_up_at?->toDateTimeString(),
                        $lead->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function findLead(string $uuid): Lead
    {
        // The global tenant scope means a uuid from another tenant simply isn't here.
        return Lead::where('uuid', $uuid)->firstOrFail();
    }

    private function resolveProjectId(?string $uuid): ?int
    {
        return $uuid ? Project::where('uuid', $uuid)->value('id') : null;
    }

    private function resolveUserId(?string $uuid): ?int
    {
        return $uuid ? User::where('tenant_id', app(\App\Support\TenantContext::class)->id())
            ->where('uuid', $uuid)->value('id') : null;
    }

    private function syncCustomFields(Lead $lead, array $custom): void
    {
        $fields = \App\Models\CustomField::where('is_active', true)
            ->whereIn('key', array_keys($custom))->get()->keyBy('key');

        foreach ($custom as $key => $value) {
            if (! $fields->has($key)) {
                continue;
            }

            \App\Models\CustomFieldValue::updateOrCreate(
                ['custom_field_id' => $fields[$key]->id, 'lead_id' => $lead->id],
                ['value' => $value === null ? null : (string) $value],
            );
        }
    }
}
