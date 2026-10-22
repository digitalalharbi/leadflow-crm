<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Automation\AutomationPack;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\QualificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectQuestion;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Project::class);

        $projects = Project::query()
            ->withCount('leads')
            // Eager, because the list is where a manager sees who works what.
            ->with(['team:id,uuid,name', 'company:id,uuid,name,color'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->input('search').'%'))
            ->orderBy('name')
            ->get();

        /*
         * A project is a business unit, not a record: the question a developer
         * has is which of their projects is actually selling. One grouped query
         * for all of them rather than a count per card — the page was going to
         * be a list of names otherwise.
         */
        /*
         * "Reached", not "sitting in" — the same definition the dashboard funnel
         * uses. Counting only leads currently in the qualified stage made a card
         * read "0 qualified · 5 appointments · 5 won", which is not a thing that
         * can happen, and drew a conversion bar with the middle segment missing.
         */
        $metrics = Lead::query()
            ->whereNotNull('project_id')
            ->visibleTo($request->user())
            ->selectRaw(
                'project_id,
                 count(*) as leads,
                 count(*) filter (where qualification_status = ? or status in (?, ?, ?)) as qualified,
                 count(*) filter (where status in (?, ?)) as appointments,
                 count(*) filter (where status = ?) as won',
                [
                    QualificationStatus::Qualified->value,
                    LeadStatus::Qualified->value, LeadStatus::Appointment->value, LeadStatus::Won->value,
                    LeadStatus::Appointment->value, LeadStatus::Won->value,
                    LeadStatus::Won->value,
                ],
            )
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        /*
         * What a manager scans the cards for: which project has somebody waiting
         * and which has gone quiet. Two more grouped passes over the same set,
         * not a query per card.
         */
        // One query, the same one every time. Reading it through the user's tenant
        // relation costs two lazy loads on the first call and none after, which
        // makes the cost of this screen depend on whether it was asked before.
        $target = (int) (TenantSetting::query()->value('first_contact_target_minutes') ?? 30);
        $open = [LeadStatus::Won->value, LeadStatus::Lost->value];

        $attention = Lead::query()
            ->whereNotNull('project_id')
            ->visibleTo($request->user())
            ->selectRaw(
                'project_id,
                 count(*) filter (where temperature = ? and status not in (?, ?)) as hot,
                 count(*) filter (where is_priority and status not in (?, ?)) as priority,
                 count(*) filter (where first_contact_at is null and status = ? and created_at <= ?) as awaiting_contact',
                [LeadTemperature::Hot->value, ...$open, ...$open, LeadStatus::New->value, now()->subMinutes($target)],
            )
            ->groupBy('project_id')->get()->keyBy('project_id');

        $since = today()->subDays(13);
        $daily = Lead::query()
            ->whereNotNull('project_id')
            ->visibleTo($request->user())
            ->where('created_at', '>=', $since)
            ->selectRaw('project_id, date(created_at) as day, count(*) as n')
            ->groupBy('project_id', 'day')->get()
            ->groupBy('project_id');

        $files = ProjectFile::where('is_active', true)
            ->selectRaw('project_id, count(*) as n')->groupBy('project_id')->pluck('n', 'project_id');
        $questions = ProjectQuestion::where('is_active', true)
            ->selectRaw('project_id, count(*) as n')->groupBy('project_id')->pluck('n', 'project_id');

        return ApiResponse::success([
            'projects' => ProjectResource::collection($projects),
            'metrics' => $projects->map(function (Project $project) use ($metrics, $attention, $daily, $files, $questions, $since) {
                $row = $metrics[$project->id] ?? null;
                $hot = $attention[$project->id] ?? null;
                $perDay = collect($daily[$project->id] ?? [])->pluck('n', 'day');
                $leads = (int) ($row->leads ?? 0);
                $qualified = (int) ($row->qualified ?? 0);

                return [
                    'id' => $project->uuid,
                    'leads' => $leads,
                    'qualified' => $qualified,
                    'appointments' => (int) ($row->appointments ?? 0),
                    'won' => (int) ($row->won ?? 0),
                    'qualification_rate' => $leads > 0 ? round($qualified / $leads * 100, 1) : 0.0,
                    'hot' => (int) ($hot->hot ?? 0),
                    'priority' => (int) ($hot->priority ?? 0),
                    'awaiting_contact' => (int) ($hot->awaiting_contact ?? 0),
                    // Fourteen days of arrivals, zero-filled, for the little line.
                    'trend' => collect(range(0, 13))
                        ->map(fn ($i) => (int) ($perDay[$since->copy()->addDays($i)->toDateString()] ?? 0))->all(),
                    'files' => (int) ($files[$project->id] ?? 0),
                    'questions' => (int) ($questions[$project->id] ?? 0),
                ];
            })->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $data = $this->validatePayload($request);

        // Not a column: a project can be created with a team, synced after it
        // exists and has an id to sync against.
        $team = $data['team'] ?? null;
        unset($data['team']);
        $data = $this->withCompany($data);

        $project = Project::create($data);

        /*
         * Every new project starts with its automation pack: a welcome on
         * WhatsApp and by email written for it, and starter questions — so its
         * first lead is answered in a minute, not after somebody remembers to
         * set it up. The journey itself is the workspace's and already applies.
         */
        app(AutomationPack::class)->complete($project, [
            'language' => $data['language'] ?? (str_starts_with((string) ($request->user()->tenant?->settings?->locale ?? 'ar'), 'en') ? 'en' : 'ar'),
        ]);

        if ($team !== null) {
            $project->team()->sync(
                User::query()
                    ->where('tenant_id', $project->tenant_id)
                    ->whereIn('uuid', $team)
                    ->pluck('id')
            );
        }

        return ApiResponse::created(['project' => new ProjectResource($project->load(['team', 'company']))]);
    }

    public function show(string $uuid)
    {
        $project = Project::withCount('leads')->where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $project);

        return ApiResponse::success(['project' => new ProjectResource($project->load('company'))]);
    }

    public function update(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $project);

        $data = $this->validatePayload($request, partial: true);

        // Not a column: the team is a relation, synced below.
        unset($data['team']);
        $data = $this->withCompany($data);

        $project->update($data);

        /*
         * The team, if the request named one. Scoped to this workspace when it
         * is resolved, because the pivot has no tenant of its own — the query
         * is the only thing keeping workspaces apart here.
         */
        if ($request->has('team')) {
            $ids = User::query()
                ->where('tenant_id', $project->tenant_id)
                ->whereIn('uuid', $request->input('team') ?: [])
                ->pluck('id');

            $project->team()->sync($ids);
        }

        return ApiResponse::success(['project' => new ProjectResource($project->fresh()->load(['team', 'company']))]);
    }

    public function destroy(string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $project);

        $project->delete(); // soft delete; existing leads keep their reference

        return ApiResponse::success(null, 'Project deleted.');
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'service' => ['sometimes', 'nullable', 'string', 'max:160'],
            'language' => ['sometimes', 'nullable', 'in:ar,en'],
            'developer_name' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'starting_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'status' => ['nullable', 'in:active,paused,sold_out'],
            'notes' => ['nullable', 'string', 'max:2000'],

            /*
             * The reps who work this project, by uuid. Validated as a shape
             * only — which users those uuids are is resolved against this
             * workspace when they are synced, so naming somebody else's rep
             * selects nobody rather than reaching into their workspace.
             */
            'team' => ['sometimes', 'nullable', 'array', 'max:50'],
            'team.*' => ['uuid'],

            // The company, by uuid. Null puts the project back with the holding.
            'company_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
    }

    /**
     * Turns the company's uuid into its id, inside this workspace.
     *
     * An unknown or foreign uuid is a validation error and not a silent null:
     * a project that quietly lands in no company is a project whose leads
     * vanish from every company's numbers.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function withCompany(array $data): array
    {
        if (! array_key_exists('company_id', $data)) {
            return $data;
        }

        if ($data['company_id'] === null) {
            return $data;
        }

        $company = Company::where('uuid', $data['company_id'])->first();

        if (! $company) {
            throw ValidationException::withMessages(['company_id' => 'That company does not exist.']);
        }

        $data['company_id'] = $company->id;

        return $data;
    }
}
