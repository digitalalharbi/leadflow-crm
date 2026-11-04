<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Access\Permissions;
use App\Domain\Automation\FollowUpPlanner;
use App\Domain\Calls\CallProviderRegistry;
use App\Domain\Calls\Exceptions\CallCannotBePlaced;
use App\Domain\Conversation\ConversationIntelligence;
use App\Domain\Exports\LeadExportColumns;
use App\Domain\Leads\DuplicateFinder;
use App\Domain\Leads\HandoffBrief;
use App\Domain\Leads\InterestEngine;
use App\Domain\Leads\InterestSla;
use App\Domain\Leads\JourneyFeed;
use App\Domain\Leads\LeadIntelligence;
use App\Domain\Leads\LeadMerger;
use App\Domain\Leads\LeadTags;
use App\Domain\Leads\LeadViews;
use App\Domain\Leads\NextBestAction;
use App\Domain\Leads\PipelineOverview;
use App\Domain\Leads\StageAnnouncer;
use App\Domain\Leads\WhyNow;
use App\Domain\Qualification\QualificationGaps;
use App\Enums\ActivityType;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Events\LeadSignal;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeLeadStatusRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\FollowUpResource;
use App\Http\Resources\LeadActivityResource;
use App\Http\Resources\LeadListResource;
use App\Http\Resources\LeadNoteResource;
use App\Http\Resources\LeadResource;
use App\Models\Call;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LostReason;
use App\Models\Project;
use App\Models\Property;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadAssignmentService;
use App\Services\Leads\LeadIngestionService;
use App\Services\Leads\LeadQueryBuilder;
use App\Services\Leads\LeadScoringService;
use App\Services\Leads\PhoneNormalizer;
use App\Support\ApiResponse;
use App\Support\CsvWriter;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    public function __construct(
        private LeadQueryBuilder $queries,
        private LeadIngestionService $ingestion,
        private LeadAssignmentService $assignment,
        private ActivityLogger $activities,
        private LeadScoringService $scoring,
        private PhoneNormalizer $phones,
        private AuditLogger $audit,
        private CallProviderRegistry $calls,
    ) {}

    /**
     * How many leads are in each of the lists people actually work from.
     *
     * The list is a table; the question that opens it is "which of these
     * needs me" — and a row of counts answers that before a single row is read.
     * One pass over the same visible set the list is built from, so a number
     * here is exactly the number of rows behind it, and a click on it lands on
     * a list of that length.
     *
     * Not filtered by what is on screen: these are the lists, not a recount of
     * the current one.
     */
    public function segments(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $user = $request->user();
        $target = (int) ($user->tenant?->settings?->first_contact_target_minutes ?? 30);
        $open = [LeadStatus::Won->value, LeadStatus::Lost->value];

        $row = Lead::query()->visibleTo($user)
            ->selectRaw(
                'count(*) as total,
                 count(*) filter (where is_priority and status not in (?, ?)) as priority,
                 count(*) filter (where temperature = ? and status not in (?, ?)) as hot,
                 count(*) filter (where first_contact_at is null and status = ? and created_at <= ?) as awaiting_contact,
                 count(*) filter (where next_follow_up_at < now() and status not in (?, ?)) as overdue,
                 count(*) filter (where assigned_to is null and status not in (?, ?)) as unassigned,
                 count(*) filter (where first_response_at is not null and status not in (?, ?)) as replied,
                 '.collect(LeadViews::sql((string) ($user->tenant?->settings?->timezone ?: 'UTC')))->map(fn ($sql, $key) => "count(*) filter (where {$sql}) as {$key}")->implode(', '),
                [
                    ...$open,
                    LeadTemperature::Hot->value, ...$open,
                    LeadStatus::New->value, now()->subMinutes($target),
                    ...$open,
                    ...$open,
                    ...$open,
                ],
            )
            ->first();

        return ApiResponse::success([
            'all' => (int) $row->total,
            'priority' => (int) $row->priority,
            'hot' => (int) $row->hot,
            'awaiting_contact' => (int) $row->awaiting_contact,
            'overdue' => (int) $row->overdue,
            'unassigned' => $user->seesAllLeads() ? (int) $row->unassigned : 0,
            'replied' => (int) $row->replied,
        ] + collect(LeadViews::KEYS)->mapWithKeys(fn (string $key) => [$key => (int) $row->{$key}])->all());
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        // The list is where "what should I do with this one" is the question;
        // other screens that reuse the row do not need the answer computed.
        $request->attributes->set('with_next_action', true);

        $perPage = min((int) $request->input('per_page', 25), 100);

        $leads = $this->queries->build($request, $request->user())
            ->with(['assignee:id,uuid,name', 'project:id,uuid,name,automation_settings'])
            ->paginate($perPage)
            ->withQueryString();

        // One pass for the whole page: a handful of grouped queries, not one per row.
        app(LeadIntelligence::class)->attach(collect($leads->items()));

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

        $intelligence = app(LeadIntelligence::class)->for($lead);
        $lead->setRelation('intelligence', $intelligence);

        return ApiResponse::success([
            'lead' => new LeadResource($lead),
            'intelligence' => $intelligence,
            // What the conversation says, as structured facts — and which engine read it.
            'conversation' => app(ConversationIntelligence::class)->summary($lead),
            // What is still unknown about what they want — and the one thing to ask next.
            'qualification_gaps' => app(QualificationGaps::class)->for($lead),
            'activities' => LeadActivityResource::collection(
                $lead->activities()->with('user:id,uuid,name')->limit(100)->get()
            ),
            'notes' => LeadNoteResource::collection(
                $lead->notes()->with('user:id,uuid,name')->limit(50)->get()
            ),
            'follow_ups' => FollowUpResource::collection(
                $lead->followUps()->with('assignee:id,uuid,name')->orderBy('due_at')->get()
            ),
            // What to do next, decided by the same rules the work queue uses, so
            // the lead screen and the queue cannot give two answers.
            'next_action' => $next = $this->nextAction($request, $lead),
            // When a person has been handed the lead: the brief, so nothing has to be reconstructed.
            'handoff' => $lead->automation_state === Lead::AUTOMATION_HANDED_OFF ? app(HandoffBrief::class)->for($lead, $next) : null,
            // The next week of automatic follow-up, and what it will do about this lead.
            'follow_up' => app(FollowUpPlanner::class)->view($lead),
            'signals' => WhyNow::signals($lead),
            // Where the reply clock stands for an interested lead — the same one the work queue shows, so a phone's lead screen can say it.
            'sla' => InterestSla::for($lead),
            // The rep's name comes from a join, not an eager load: an eager load is
            // skipped when there are no calls, which would make a quiet lead cost
            // fewer queries than a busy one and break the constant-cost guarantee.
            'calls' => $lead->calls()
                ->leftJoin('users', 'users.id', '=', 'calls.user_id')
                ->select('calls.*', 'users.name as by_name')
                ->latest('calls.id')->limit(10)->get()->map(fn ($call) => [
                    'id' => $call->uuid,
                    'direction' => $call->direction,
                    'status' => $call->status,
                    'outcome' => $call->outcome,
                    'duration_seconds' => $call->duration_seconds,
                    'by' => $call->by_name,
                    'at' => ($call->started_at ?? $call->created_at)?->toIso8601String(),
                ])->all(),
            'appointments' => AppointmentResource::collection(
                $lead->appointments()->orderByDesc('scheduled_at')->limit(10)->get()
            ),
        ]);
    }

    /**
     * The leads list as a picture: headline numbers, quality, sources, trend.
     * Honours the same filters as the list, so the picture and the rows below it
     * describe the same leads.
     */
    public function overview(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $overview = new PipelineOverview($this->queries->build($request, $request->user()));
        $health = $overview->health();
        $qualified = collect($overview->funnel())->firstWhere('key', 'qualified')['count'] ?? 0;

        // The two counts a rep's morning starts with: what arrived today, and
        // what is waiting on a follow-up somebody promised.
        $today = (clone $this->queries->build($request, $request->user()))->where('leads.created_at', '>=', now()->startOfDay())->count();
        $awaiting = (clone $this->queries->build($request, $request->user()))->whereNotNull('next_follow_up_at')->whereNotIn('status', ['won', 'lost', 'invalid'])->count();

        return ApiResponse::success([
            'health' => $health + [
                'today' => $today,
                'awaiting_follow_up' => $awaiting,
                'qualified' => $qualified,
                'qualified_rate' => $health['total'] > 0 ? round($qualified / $health['total'] * 100, 1) : null,
            ],
            'classification' => $overview->classification(),
            'sources' => $overview->sources(),
            'trend' => $overview->trend(30),
        ]);
    }

    /** Read the conversation now (again): useful after a key is added, or when the first read fell back. */
    public function analyzeConversation(Request $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('update', $lead);

        $conversation = app(ConversationIntelligence::class);
        $result = $conversation->analyse($lead, force: true);

        return ApiResponse::success(['conversation' => $conversation->summary($lead->refresh()), 'run' => $result]);
    }

    /** Other records that may be the same person as this one. */
    public function duplicates(Request $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('merge', $lead);

        return ApiResponse::success(['duplicates' => app(DuplicateFinder::class)->for($lead, viewer: request()->user())]);
    }

    /** Folds a duplicate record into this one. A manager's call: it cannot be cleanly undone. */
    public function merge(Request $request, string $uuid)
    {
        $keep = $this->findLead($uuid);
        $this->authorize('merge', $keep);

        $data = $request->validate(['duplicate_id' => ['required', 'uuid']]);
        $duplicate = $this->findLead($data['duplicate_id']);
        $this->authorize('merge', $duplicate);

        $result = app(LeadMerger::class)->merge($keep, $duplicate, $request->user());

        return ApiResponse::success([
            'lead' => new LeadResource($result['lead']->load(['assignee:id,uuid,name', 'project:id,uuid,name'])),
            'moved' => $result['moved'],
        ]);
    }

    /** Everything that has happened to this lead, as one list. */
    public function journey(Request $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('view', $lead);

        $entries = app(JourneyFeed::class)->for($lead);

        return ApiResponse::success([
            'entries' => $entries,
            'counts' => collect($entries)->countBy('kind'),
        ]);
    }

    /** @return array{action:string,urgency:string,reason:string} */
    private function nextAction(Request $request, Lead $lead): array
    {
        // A direct read rather than `$user->tenant->settings`: a lazy relation costs
        // queries only the first time it is touched, which makes cost depend on
        // what the request happened to load before.
        $target = (int) (TenantSetting::query()->value('first_contact_target_minutes') ?? 30);
        $result = app(NextBestAction::class)->for($lead, $target);

        return ['action' => $result['action'], 'urgency' => $result['urgency'], 'reason' => $result['reason']];
    }

    /**
     * Typing a number somebody else's lead holds must not hand over that lead, reopen it or restore it. The person is told
     * the number is taken, because it is, and nothing about whose it is.
     */
    private function refuseLeadOutOfReach(User $user, array $data): void
    {
        $phone = filled($data['phone'] ?? null) ? $this->phones->normalize((string) $data['phone'], TenantSetting::query()->value('default_country_code')) : null;
        $email = filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null;

        if ($phone === null && $email === null) {
            return;
        }

        $match = fn ($query) => $phone !== null ? $query->where('phone_normalized', $phone) : $query->whereRaw('lower(email) = ?', [$email]);
        $id = $match(Lead::withoutGlobalScopes()->withTrashed()->where('tenant_id', $user->tenant_id))->value('id');

        if ($id !== null && ! Lead::query()->withTrashed()->visibleTo($user)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['phone' => ['This number is already registered. Ask your manager about it.']]);
        }
    }

    /**
     * Manual creation still goes through the ingestion pipeline, so duplicate
     * detection and assignment behave identically to a webhook lead.
     */
    public function store(StoreLeadRequest $request)
    {
        $data = $request->validated();

        $data['project_id'] = $this->resolveProjectId($data['project_id'] ?? null);
        $data['assigned_to'] = $this->resolveAssignee($request->user(), $data['assigned_to'] ?? null);

        // A rep typing in a lead has just spoken to that person, so it is theirs.
        // Round-robin is for inbound traffic nobody has touched yet.
        if ($data['assigned_to'] === null && $request->user()->isSales()) {
            $data['assigned_to'] = $request->user()->id;
        }

        $this->refuseLeadOutOfReach($request->user(), $data);

        $result = $this->ingestion->ingest(
            $request->user()->tenant,
            IncomingLead::fromArray(IntegrationProvider::Manual, $data),
        );

        if (! $result->wasAccepted()) {
            throw ValidationException::withMessages(['phone' => [$result->reason]]);
        }

        $lead = $result->lead;
        $lead->forceFill(['created_by' => $request->user()->id])->saveQuietly();

        $fresh = $lead->fresh(['assignee:id,uuid,name', 'project:id,uuid,name', 'lostReason:id,uuid,label']);

        if ($result->isDuplicate()) {
            return ApiResponse::success(
                [
                    'lead' => new LeadResource($fresh),
                    'duplicate' => true,
                    // Told apart because they are different events to the person
                    // typing: one lead they can already see, one they deleted.
                    'restored' => $result->restored,
                ],
                $result->restored
                    ? 'A deleted lead held this number. It has been restored, with the name and notes it had.'
                    : 'This phone number already exists. The existing lead was updated and marked as re-engaged.',
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

        if (array_key_exists('interested_property_id', $data)) {
            $data['interested_property_id'] = $this->resolvePropertyId($data['interested_property_id']);
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
        LeadSignal::dispatch($lead, 'lead.updated', ['fields' => array_keys($data)], $request->user());

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

            if ($request->filled('deal_value')) {
                $lead->deal_value_minor = (int) round((float) $request->input('deal_value') * 100);
            }
        } else {
            $lead->won_at = null;
            $lead->lost_at = null;
            $lead->lost_reason_id = null;
            /*
             * A deal that is no longer won has no value. Leaving the figure
             * behind would keep it in the revenue a campaign is credited with,
             * which is the quiet kind of wrong: the sale is visibly gone from
             * the pipeline and still counted in the return.
             */
            $lead->deal_value_minor = null;
        }

        if (in_array($new, [LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::Appointment], true)) {
            $lead->last_contact_at = now();
            $lead->first_contact_at ??= now();
        }

        // A booked appointment is the strongest possible form of "wants to
        // visit", so the stage records the fact the score reads rather than the
        // score carrying a separate bonus for the stage.
        if ($new === LeadStatus::Appointment) {
            $lead->appointment_interest = true;
        }

        // apply() sets the score and the temperature together, reading the
        // tenant's own thresholds.
        $this->scoring->apply($lead);
        $lead->qualification_status = $this->scoring->qualificationStatus($lead);

        // The lead row and its activity rows have to land together: the reports
        // read the activities, so a half-applied change would misreport a win.
        DB::transaction(function () use ($lead, $new, $previous, $request) {
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
        });

        // After the commit: a listener must read the finished lead, and must not
        // be able to undo the change a person just made.
        app(StageAnnouncer::class)->announce($lead, $previous, $request->user(), ['via' => 'stage']);

        return ApiResponse::success(['lead' => new LeadResource($lead->fresh(['assignee', 'project', 'lostReason']))]);
    }

    public function changeTemperature(Request $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('update', $lead);

        $request->validate([
            'temperature' => ['required', Rule::in(LeadTemperature::values())],
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

    /** Replaces the lead's tags; the history says which came and went. */
    public function setTags(Request $request, string $uuid, LeadTags $tags)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('update', $lead);

        $request->validate([
            'tags' => ['present', 'array', 'max:'.LeadTags::MAX_PER_LEAD],
            'tags.*' => ['string', 'max:'.LeadTags::MAX_LENGTH],
        ]);

        $tags->set($lead, $request->input('tags', []), $request->user());

        return ApiResponse::success(['lead' => new LeadResource($lead->fresh(['assignee', 'project']))]);
    }

    /** The tags in use on the leads this person can see, most used first. */
    public function tags(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $visible = Lead::query()->visibleTo($request->user());

        return ApiResponse::success(['tags' => LeadTags::inUse($visible)]);
    }

    /**
     * A person's word on how interested the lead is, over what the numbers say.
     *
     * "Interested" is a floor the arithmetic cannot take away; "not interested" forces the lead
     * cold and says so in the history. Sending null gives the decision back to the numbers.
     */
    public function setInterest(Request $request, string $uuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('update', $lead);

        $data = $request->validate(['override' => ['present', 'nullable', Rule::in(['interested', 'not_interested'])]]);

        $before = $lead->interest_level;
        $lead->forceFill(['interest_override' => $data['override']])->saveQuietly();
        app(InterestEngine::class)->refresh($lead->refresh(), 'person');
        $lead->refresh();

        $this->activities->log($lead, ActivityType::InterestChanged, $request->user(), null, [
            'override' => $data['override'], 'from' => $before, 'to' => $lead->interest_level,
        ]);
        $this->audit->log('lead.interest_set', $request->user(), $lead, ['before' => ['level' => $before], 'after' => ['override' => $data['override'], 'level' => $lead->interest_level]]);

        return ApiResponse::success(['lead' => new LeadResource($lead->load(['assignee:id,uuid,name', 'project:id,uuid,name', 'lostReason:id,uuid,label']))]);
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

        $this->activities->log($lead, ActivityType::NoteAdded, $request->user(), Str::limit($data['body'], 120));

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
            // The response-time report measures from arrival to the first time
            // anyone reached out, whichever way they did it.
            $lead->first_contact_at ??= now();

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

        /*
         * A call also becomes a record of its own, not only a line in the
         * timeline.
         *
         * The timeline answers "what happened to this lead"; this answers "how
         * many calls did we make, to whom, and what came of them" — which is a
         * manager's question and a report's, and an activity row cannot carry a
         * duration or an outcome without becoming something else.
         *
         * Through the provider registry even though today's provider places no
         * call: when a real one is connected, the same button starts a real
         * call and nothing above here changes.
         */
        $call = null;
        $fallback = null;

        if ($data['channel'] === 'call') {
            try {
                $call = $this->calls->forTenant()->place($lead, $request->user());
            } catch (CallCannotBePlaced $e) {
                /*
                 * The provider could not start it — the rep has no number on
                 * their profile, the lead has none, or the provider refused.
                 * The call still happens: the rep dials, and this is recorded
                 * the way a manual call always was. The reason is returned so
                 * the screen can say why the workspace's own telephony stayed
                 * quiet, rather than the button appearing to do nothing.
                 */
                $fallback = $e->reason;
                $call = $this->calls->get('manual')->place($lead, $request->user());
            }
        }

        return ApiResponse::success([
            'lead' => new LeadResource($lead->fresh(['assignee', 'project'])),
            'call' => $call === null ? null : [
                'id' => $call->uuid,
                'status' => $call->status,
                // `bridge` means the provider is ringing the rep; `manual` means
                // the rep dials. The client opens tel: only for the second.
                'mode' => $call->provider === 'manual' ? 'manual' : 'bridge',
                'fallback_reason' => $fallback,
            ],
        ]);
    }

    /**
     * What came of a call, once the rep knows.
     *
     * Separate from placing it because nothing is watching a rep's phone: the
     * product learns how a call went when a person says so, and a status that
     * advanced on its own would be a guess printed as a fact.
     */
    public function recordCall(Request $request, string $uuid, string $callUuid)
    {
        $lead = $this->findLead($uuid);
        $this->authorize('update', $lead);

        $call = Call::where('lead_id', $lead->id)->where('uuid', $callUuid)->firstOrFail();

        $data = $request->validate([
            'status' => ['required', 'in:answered,no_answer,failed,completed'],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Accepted when a provider or a person supplies it, never invented.
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $call->forceFill($data + ['ended_at' => now()])->save();

        LeadSignal::dispatch($lead, 'lead.call_completed', ['call_status' => $data['status'], 'outcome' => $data['outcome'] ?? null], $request->user());

        return ApiResponse::success(['call' => [
            'id' => $call->uuid,
            'status' => $call->status,
            'outcome' => $call->outcome,
            'duration_seconds' => $call->duration_seconds,
            'measured' => $call->wasMeasured(),
        ]]);
    }

    /** CSV export of the current filter selection. Streamed so large tenants work. */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', Lead::class);

        $query = $this->queries->build($request, $request->user())
            ->with(['assignee:id,uuid,name', 'project:id,uuid,name']);

        $filename = 'leads-'.now()->format('Y-m-d-His').'.csv';

        $hidden = Permissions::hiddenData($request->user());

        return response()->streamDownload(function () use ($query, $hidden) {
            $out = fopen('php://output', 'w');
            $csv = CsvWriter::open($out);

            $csv->writeRow(LeadExportColumns::headings());

            $query->chunk(500, function ($leads) use ($csv, $hidden) {
                foreach ($leads as $lead) {
                    $csv->writeRow(LeadExportColumns::row($lead, $hidden));
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

    /**
     * Scoped lookup. A uuid belonging to another tenant is not found here, and
     * the caller gets the same 404 they would get for one that never existed.
     */
    private function resolvePropertyId(?string $uuid): ?int
    {
        if (blank($uuid)) {
            return null;
        }

        $id = Property::where('uuid', $uuid)->value('id');

        abort_if($id === null, 404, 'Resource not found.');

        return $id;
    }

    private function resolveProjectId(?string $uuid): ?int
    {
        if (blank($uuid)) {
            return null;
        }

        $id = Project::where('uuid', $uuid)->value('id');

        abort_if($id === null, 404, 'Resource not found.');

        return $id;
    }

    /**
     * Naming an owner is privileged — commission follows the assignment — so a
     * rep may not hand a lead to a colleague, and nobody may hand one to a
     * deactivated account or to a stranger from another tenant. An id we cannot
     * honour is refused rather than quietly dropped.
     */
    private function resolveAssignee(User $actor, ?string $uuid): ?int
    {
        if ($uuid === null) {
            return null;
        }

        if ($actor->cannot('assignOnCreate', Lead::class)) {
            throw ValidationException::withMessages([
                'assigned_to' => ['You may not choose who this lead belongs to.'],
            ]);
        }

        $id = User::where('tenant_id', app(TenantContext::class)->id())
            ->where('uuid', $uuid)
            ->where('is_active', true)
            ->value('id');

        if ($id === null) {
            throw ValidationException::withMessages([
                'assigned_to' => ['This user is not available.'],
            ]);
        }

        return $id;
    }

    private function syncCustomFields(Lead $lead, array $custom): void
    {
        $fields = CustomField::where('is_active', true)
            ->whereIn('key', array_keys($custom))->get()->keyBy('key');

        foreach ($custom as $key => $value) {
            if (! $fields->has($key)) {
                continue;
            }

            CustomFieldValue::updateOrCreate(
                ['custom_field_id' => $fields[$key]->id, 'lead_id' => $lead->id],
                ['value' => $value === null ? null : (string) $value],
            );
        }
    }
}
