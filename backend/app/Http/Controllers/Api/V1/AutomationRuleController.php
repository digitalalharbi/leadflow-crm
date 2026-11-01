<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Automation\ActionLog;
use App\Domain\Automation\AutomationInsights;
use App\Domain\Automation\ConditionEvaluator;
use App\Domain\Automation\RuleSimulator;
use App\Domain\Automation\RuleTemplates;
use App\Domain\Leads\OutcomeRecorder;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ScheduledAction;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rules, written by the workspace rather than by a developer.
 *
 * The validation here is the product's safety boundary: a rule is data a
 * customer types, and it then runs unattended against their own leads. Every
 * field, operator and action name is checked against a closed list — an
 * unrecognised one is rejected at the door rather than discovered later by the
 * engine quietly doing nothing.
 */
class AutomationRuleController extends Controller
{
    /** Everything the builder is allowed to offer, in one place. */
    private const TRIGGERS = [
        'lead.created', 'lead.assigned', 'lead.replied',
        'lead.stage_changed', 'lead.qualified', 'lead.won', 'lead.lost',
        // Fires for every outcome a rep records, including the ones that move
        // nothing — which is the only way "after two unanswered calls…" can be
        // written at all.
        'lead.outcome_recorded',
        // What the customer did, and what did not happen.
        'lead.sheet_row', 'lead.email_opened', 'lead.email_clicked', 'lead.file_opened',
        'lead.no_reply', 'lead.score_changed', 'lead.appointment_created', 'lead.call_completed',
        'lead.re_engaged', 'lead.sla_breached', 'lead.interest_changed', 'lead.reservation_created',
    ];

    private const FIELDS = [
        // Not a column: what the rep just recorded, carried on the event.
        'last_outcome',
        'status', 'temperature', 'qualification_status', 'score', 'platform', 'source',
        'campaign_name', 'project_id', 'city', 'budget_min', 'budget_max',
        'purchase_purpose', 'payment_method', 'financing_status', 'purchase_timeline',
        'preferred_property_type', 'preferred_location', 'appointment_interest',
        'assigned_to', 'is_assigned', 'has_phone', 'has_email', 'has_whatsapp',
        're_engaged_count', 'minutes_since_created', 'minutes_since_first_contact',
        'minutes_since_last_contact', 'was_contacted', 'has_replied',
        'is_priority', 'classification', 'language', 'provider', 'campaign_id', 'ad_name',
        'automation_state', 'attempts', 'no_reply_minutes', 'tags',
        // How interested they are, apart from how good a fit.
        'interest_level', 'interest_score',
    ];

    private const OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in',
        'greater_than', 'less_than', 'between', 'exists', 'not_exists',
    ];

    private const ACTIONS = [
        'assign', 'change_stage', 'adjust_score', 'add_tag',
        'schedule_follow_up', 'add_note', 'notify', 'stop_follow_ups',
        'send_whatsapp', 'send_email', 'share_file',
        'ask_qualification', 'set_priority', 'hand_off',
        'set_temperature', 'stop_automation', 'start_nurture', 'create_call_task', 'request_appointment',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', AutomationRule::class);

        $rules = AutomationRule::query()
            ->withCount('runs')
            ->orderBy('trigger')
            ->orderBy('priority')
            ->get();

        /*
         * The table's last two columns: how each rule last ran, and which
         * project it is for. One query each, not one per row.
         */
        $lastRuns = collect(DB::select(
            'select distinct on (automation_rule_id) automation_rule_id, outcome, created_at from automation_runs where automation_rule_id = any(?) order by automation_rule_id, created_at desc',
            ['{'.$rules->pluck('id')->implode(',').'}'],
        ))->keyBy('automation_rule_id');

        $projectIds = $rules->flatMap(fn ($r) => collect($r->conditions ?? [])->where('field', 'project_id')->pluck('value'))->filter()->unique();
        $projects = Project::whereIn('uuid', $projectIds->all())->pluck('name', 'uuid');

        return ApiResponse::success(['rules' => $rules->map(fn ($r) => $this->present($r) + [
            'last_run' => isset($lastRuns[$r->id]) ? ['outcome' => $lastRuns[$r->id]->outcome, 'at' => Carbon::parse($lastRuns[$r->id]->created_at)->toIso8601String()] : null,
            'project' => ($uuid = collect($r->conditions ?? [])->firstWhere('field', 'project_id')['value'] ?? null) ? ($projects[$uuid] ?? null) : null,
        ])]);
    }

    /**
     * What the builder may offer. Served from the same constants the validator
     * enforces, so a UI cannot present an option the API will refuse.
     */
    public function schema()
    {
        $this->authorize('viewAny', AutomationRule::class);

        return ApiResponse::success([
            'triggers' => self::TRIGGERS,
            'fields' => self::FIELDS,
            'operators' => self::OPERATORS,
            'condition_matches' => ConditionEvaluator::matches(),
            'actions' => self::ACTIONS,
            'stages' => array_map(fn (LeadStatus $s) => $s->value, LeadStatus::cases()),
            /*
             * Fields whose values are a closed set, so the editor can offer them
             * instead of asking somebody to type `no_answer` from memory. A
             * typo here is the worst kind of bug to own: the rule saves, runs,
             * matches nothing, and reports no error.
             *
             * Taken from the recorder rather than written out, so a new outcome
             * cannot exist without being selectable. It lists every outcome the
             * API accepts, which is more than the six buttons on the rep's card.
             */
            'field_values' => [
                'last_outcome' => OutcomeRecorder::available(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', AutomationRule::class);

        $data = $this->validated($request);

        $rule = AutomationRule::create($data + ['created_by' => $request->user()->id]);
        $this->audited('automation_rule.created', $request, $rule, ['after' => $this->trail($rule)]);

        return ApiResponse::created(['rule' => $this->present($rule)]);
    }

    public function update(Request $request, string $uuid)
    {
        // Resolved first: the tenant scope turns another workspace's rule into
        // a 404, which is the right answer — a 403 would confirm it exists.
        $rule = AutomationRule::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $rule);

        $before = $this->trail($rule);
        $rule->update($this->validated($request, partial: true));
        $this->audited('automation_rule.updated', $request, $rule, ['before' => $before, 'after' => $this->trail($rule->fresh())]);

        return ApiResponse::success(['rule' => $this->present($rule->fresh())]);
    }

    /**
     * A copy to edit, rather than a rule to retype.
     *
     * A workspace running three projects writes the same rule three times with
     * one condition different, and retyping it is where the second copy quietly
     * stops matching the first — a condition missed, a delay that is two days
     * in one and two hours in another. Nobody notices until a project stops
     * being chased.
     *
     * The copy arrives switched off, like an installed template does and for
     * the same reason: it is a rule nobody has read yet, and it is about to be
     * edited anyway.
     */
    public function duplicate(Request $request, string $uuid)
    {
        $this->authorize('create', AutomationRule::class);

        $original = AutomationRule::where('uuid', $uuid)->firstOrFail();

        $copy = AutomationRule::create([
            'name' => $this->copyName($original->name),
            'trigger' => $original->trigger,
            'conditions' => $original->conditions,
            'condition_match' => $original->condition_match,
            'actions' => $original->actions,
            'else_actions' => $original->else_actions,
            'priority' => $original->priority,
            'stops_processing' => $original->stops_processing,
            'is_enabled' => false,
            'created_by' => $request->user()->id,
        ]);

        $this->audited('automation_rule.duplicated', $request, $copy, ['after' => $this->trail($copy), 'from' => $original->name]);

        return ApiResponse::created(['rule' => $this->present($copy)]);
    }

    /**
     * "نسخة من X", and "نسخة من X 2" after that.
     *
     * Duplicating twice is the ordinary case — three projects, three copies —
     * and two rules with the same name in one list is the thing that makes the
     * trace unreadable later.
     */
    private function copyName(string $name): string
    {
        $base = 'نسخة من '.$name;
        $taken = AutomationRule::where('name', 'like', $base.'%')->pluck('name')->all();

        if (! in_array($base, $taken, true)) {
            return mb_substr($base, 0, 120);
        }

        $n = 2;

        while (in_array($base.' '.$n, $taken, true)) {
            $n++;
        }

        return mb_substr($base.' '.$n, 0, 120);
    }

    public function destroy(Request $request, string $uuid)
    {
        $rule = AutomationRule::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $rule);

        $this->audited('automation_rule.deleted', $request, $rule, ['before' => $this->trail($rule)]);
        $rule->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * What this rule would do to a lead, without doing it.
     *
     * The difference between a rule people trust and one they disable: you can
     * see whether it matches before it runs against real customers.
     */
    public function preview(Request $request, string $uuid, ConditionEvaluator $evaluator)
    {
        $this->authorize('viewAny', AutomationRule::class);

        $rule = AutomationRule::where('uuid', $uuid)->firstOrFail();

        $sample = Lead::query()
            ->visibleTo($request->user())
            ->latest('id')
            ->limit(200)
            ->get();

        // The rule's own match mode, or the preview answers a different question
        // from the one the rule will ask when it runs.
        $matched = $sample
            ->map(fn (Lead $lead) => [$lead, $evaluator->evaluate(
                $lead,
                $rule->conditions ?? [],
                [],
                $rule->condition_match ?? ConditionEvaluator::MATCH_ALL,
            )])
            ->filter(fn ($pair) => $pair[1]['matched']);

        return ApiResponse::success([
            'sampled' => $sample->count(),
            'would_match' => $matched->count(),
            'examples' => $matched->take(5)->map(fn ($pair) => [
                'id' => $pair[0]->uuid,
                'full_name' => $pair[0]->full_name,
                'status' => $pair[0]->status?->value,
            ])->values(),
        ]);
    }

    /**
     * "If this rule had run over the last seven days": leads affected, messages,
     * stage changes, hand-offs. Nothing is executed, sent, queued or notified.
     */
    public function simulate(Request $request, string $uuid)
    {
        $this->authorize('viewAny', AutomationRule::class);

        $rule = AutomationRule::where('uuid', $uuid)->firstOrFail();

        return ApiResponse::success(['simulation' => app(RuleSimulator::class)->run($rule, $request->user(), (int) $request->input('days', 7))]);
    }

    /** The trace for one lead: every rule considered, in order, with reasons. */
    public function runsForLead(Request $request, string $leadUuid)
    {
        $lead = Lead::where('uuid', $leadUuid)->firstOrFail();
        $this->authorize('view', $lead);

        $runs = AutomationRun::where('lead_id', $lead->id)
            ->with('rule:id,uuid,name')
            ->latest('id')
            ->limit(100)
            ->get();

        /*
         * What is still coming, beside what already happened.
         *
         * A rule can promise an action two days out, and a trace that showed
         * only the past would say a rule "applied" on Tuesday and never mention
         * the WhatsApp it arranged for Thursday — which is the one thing
         * somebody reading this screen on Wednesday needs to know, and the only
         * place they could stop it.
         */
        $scheduled = ScheduledAction::where('lead_id', $lead->id)
            ->orderBy('run_at')
            ->limit(50)
            ->get();

        return ApiResponse::success([
            'runs' => $runs->map(fn (AutomationRun $run) => [
                'id' => $run->id,
                'rule' => $run->rule ? ['id' => $run->rule->uuid, 'name' => $run->rule->name] : null,
                'trigger' => $run->trigger,
                'outcome' => $run->outcome,
                'reason' => $run->reason,
                'applied_actions' => $run->applied_actions,
                'correlation_id' => $run->correlation_id,
                'duration_ms' => $run->duration_ms,
                'created_at' => $run->created_at?->toIso8601String(),
            ]),
            'scheduled' => $scheduled->map(fn (ScheduledAction $action) => [
                'id' => $action->uuid,
                'rule_name' => $action->rule_name,
                'type' => $action->action['type'] ?? 'unknown',
                'run_at' => $action->run_at?->toIso8601String(),
                'status' => $action->status,
                'outcome' => $action->outcome,
                'correlation_id' => $action->correlation_id,
            ]),
        ]);
    }

    /**
     * Call off something a rule promised.
     *
     * The rule decided on Tuesday; by Wednesday a manager may know something
     * the rule does not. Without this the only way to stop it was to close the
     * lead, which is a worse thing to do to the pipeline than the message was
     * going to be.
     */
    public function cancelScheduled(Request $request, string $uuid)
    {
        $action = ScheduledAction::where('uuid', $uuid)->firstOrFail();

        // The authority to change a lead, not to write rules: the person
        // watching this lead is the one who knows the message is now wrong.
        $this->authorize('update', $action->lead);

        if ($action->status !== ScheduledAction::PENDING) {
            return ApiResponse::error('This action is no longer pending.', null, 422);
        }

        $action->resolve(ScheduledAction::CANCELLED, 'أُلغي بواسطة '.$request->user()->name.'.');
        app(ActionLog::class)->resolveScheduled($action->id, $action->action ?? [], [], 'أُلغي بواسطة '.$request->user()->name.'.');

        return ApiResponse::success(null, 'Scheduled action cancelled.');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'trigger' => [$required, Rule::in(self::TRIGGERS)],
            'is_enabled' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(AutomationRule::MODES)],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'stops_processing' => ['sometimes', 'boolean'],

            /*
             * `present`, not `required`: Laravel's `required` rejects an empty
             * array, which made "when a lead is won, stop chasing it" — a rule
             * with a trigger and no conditions — impossible to create. That is
             * the simplest and most useful kind of rule there is. The field
             * must still be sent, so a client that forgets it is corrected
             * rather than silently given a rule that fires on everything.
             */
            'conditions' => $partial ? ['sometimes', 'array', 'max:20'] : ['present', 'array', 'max:20'],

            /*
             * Whether the rule needs all of its conditions or any one of them.
             * `sometimes`, so a client written before this existed keeps the
             * column's default and every rule already saved means exactly what
             * it meant when it was written.
             */
            'condition_match' => ['sometimes', Rule::in(ConditionEvaluator::matches())],
            // A known field, or `custom.<key>` — any answer the form or sheet carried.
            // Each item a condition or one bracket of them; checked, and rebuilt, by conditions() below.
            'conditions.*' => ['array'],

            // At least one action: a rule that matches and does nothing is a
            // rule somebody will believe is working.
            'actions' => [$required, 'array', 'min:1', 'max:10'],
            // The otherwise branch: what to do when the conditions do not hold. Same vocabulary as `actions`.
            'else_actions' => ['sometimes', 'array', 'max:10'],
            'actions.*.type' => ['required', Rule::in(self::ACTIONS)],

            /*
             * An action also has to carry what it needs to do anything.
             *
             * The closed list above checks that an action *exists*; it said
             * nothing about its parameters, and a `schedule_follow_up` with no
             * delay — or a `change_stage` with no stage — saved cleanly and
             * then failed every time it ran, in a trace nobody reads until
             * somebody complains. That is the same silent-configuration bug the
             * closed vocabulary exists to prevent, one level down.
             */
            'actions.*.status' => ['required_if:actions.*.type,change_stage', Rule::in(LeadStatus::values())],

            /*
             * A lost lead carries why it was lost, whoever closed it.
             *
             * The executor refuses to close one without a reason, and
             * `validate()` returns only the keys it has rules for — so without
             * this line the reason was stripped on the way in and every rule
             * that closed a lead as lost failed on every run, in a trace nobody
             * reads. The rule has to be refused at the point it is written.
             */
            'actions.*.lost_reason_id' => ['required_if:actions.*.status,lost', 'uuid'],
            'actions.*.by' => ['required_if:actions.*.type,adjust_score', 'integer', 'not_in:0'],
            'actions.*.tag' => ['required_if:actions.*.type,add_tag', 'string', 'max:60'],
            'actions.*.body' => ['required_if:actions.*.type,add_note', 'string', 'max:2000'],
            'actions.*.audience' => ['required_if:actions.*.type,notify', Rule::in(['owner', 'managers'])],
            'actions.*.in_hours' => ['required_if:actions.*.type,schedule_follow_up', 'integer', 'min:1', 'max:8760'],

            /*
             * A rule that messages people names the message it sends.
             *
             * The wording is a template rather than a line typed into the rule,
             * so a workspace changing what it says to customers changes it
             * where the messages are listed — not by opening every rule to find
             * which one carries that sentence. Required, because a send_whatsapp
             * with no template is a rule that reports a failure on every run.
             */
            'actions.*.template_uuid' => ['nullable', 'uuid'],
            // …or what the message is for, and each lead gets the version
            // written for its project, language and stage.
            'actions.*.purpose' => ['nullable', Rule::in(MessageTemplate::PURPOSES)],
            // A waiting step that only makes sense if the customer stayed quiet.
            'actions.*.unless' => ['sometimes', 'nullable', Rule::in(['replied'])],
            'actions.*.reason' => ['sometimes', 'nullable', Rule::in(['replied', 'qualified', 'no_reply', 'unreached', 'asked_for_human', 'needs_human'])],
            'actions.*.when' => ['sometimes', 'nullable', Rule::in(['hot', 'unreached'])],
            'actions.*.temperature' => ['required_if:actions.*.type,set_temperature', Rule::in(LeadTemperature::values())],

            /*
             * Optional on purpose. Left out, the chooser reads the lead's own
             * answers — investment buyers get the numbers, somebody who said
             * they need financing gets the guide — which is the behaviour worth
             * having by default.
             */
            'actions.*.kind' => ['sometimes', 'nullable', Rule::in(ProjectFile::KINDS)],
            'actions.*.send' => ['sometimes', 'boolean'],
            // Where the file goes. WhatsApp unless the rule says email.
            'actions.*.channel' => ['sometimes', 'nullable', Rule::in(['whatsapp', 'email'])],

            /*
             * The parameters that used to fall off the rule on the way in.
             *
             * `validate()` returns only the keys it was given rules for, so
             * every parameter missing from this list was dropped between the
             * editor and the database — silently, after a success toast. The
             * alert text a manager typed was gone, and the rule sent the
             * generic fallback to the same people forever.
             *
             * Worst where it was least visible: every built-in template carries
             * a message, a note or a strategy, and they survive only because
             * templates are installed server-side. Opening one in the editor to
             * change its delay and saving deleted the rest of it.
             *
             * So the test for this is a round trip, not a rule-by-rule
             * assertion: anything the executor reads has to come back out of
             * the API unchanged.
             */
            'actions.*.strategy' => ['required_if:actions.*.type,assign', Rule::in(['round_robin', 'specific'])],
            'actions.*.user_id' => ['required_if:actions.*.strategy,specific', 'uuid'],
            'actions.*.message' => ['sometimes', 'nullable', 'string', 'max:500'],
            'actions.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'actions.*.in_minutes' => ['sometimes', 'integer', 'min:1', 'max:525600'],

            /*
             * How long a rule waits before doing this one.
             *
             * Thirty days at the outside. Longer than that and the world the
             * rule decided against no longer exists in any useful sense — and a
             * message arriving two months after the conversation it belongs to
             * reads as a mistake, which is what it would be.
             */
            'actions.*.delay_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:43200'],
            // The otherwise branch takes exactly the parameters an action does.
            'else_actions.*.type' => ['required', Rule::in(self::ACTIONS)],
            'else_actions.*.status' => ['required_if:else_actions.*.type,change_stage', Rule::in(LeadStatus::values())],
            'else_actions.*.lost_reason_id' => ['required_if:else_actions.*.status,lost', 'uuid'],
            'else_actions.*.by' => ['required_if:else_actions.*.type,adjust_score', 'integer', 'not_in:0'],
            'else_actions.*.tag' => ['required_if:else_actions.*.type,add_tag', 'string', 'max:60'],
            'else_actions.*.body' => ['required_if:else_actions.*.type,add_note', 'string', 'max:2000'],
            'else_actions.*.audience' => ['required_if:else_actions.*.type,notify', Rule::in(['owner', 'managers'])],
            'else_actions.*.in_hours' => ['required_if:else_actions.*.type,schedule_follow_up', 'integer', 'min:1', 'max:8760'],
            'else_actions.*.template_uuid' => ['nullable', 'uuid'],
            'else_actions.*.purpose' => ['nullable', Rule::in(MessageTemplate::PURPOSES)],
            'else_actions.*.unless' => ['sometimes', 'nullable', Rule::in(['replied'])],
            'else_actions.*.reason' => ['sometimes', 'nullable', Rule::in(['replied', 'qualified', 'no_reply', 'unreached', 'asked_for_human', 'needs_human'])],
            'else_actions.*.when' => ['sometimes', 'nullable', Rule::in(['hot', 'unreached'])],
            'else_actions.*.temperature' => ['required_if:else_actions.*.type,set_temperature', Rule::in(LeadTemperature::values())],
            'else_actions.*.kind' => ['sometimes', 'nullable', Rule::in(ProjectFile::KINDS)],
            'else_actions.*.send' => ['sometimes', 'boolean'],
            'else_actions.*.channel' => ['sometimes', 'nullable', Rule::in(['whatsapp', 'email'])],
            'else_actions.*.strategy' => ['required_if:else_actions.*.type,assign', Rule::in(['round_robin', 'specific'])],
            'else_actions.*.user_id' => ['required_if:else_actions.*.strategy,specific', 'uuid'],
            'else_actions.*.message' => ['sometimes', 'nullable', 'string', 'max:500'],
            'else_actions.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'else_actions.*.in_minutes' => ['sometimes', 'integer', 'min:1', 'max:525600'],
            'else_actions.*.delay_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:43200'],
        ]);

        // A send names its message or what the message is for; with neither it
        // would report a failure on every run.
        foreach (['actions', 'else_actions'] as $list) {
            foreach ($data[$list] ?? [] as $index => $action) {
                if (in_array($action['type'] ?? null, ['send_whatsapp', 'send_email'], true)
                    && blank($action['template_uuid'] ?? null) && blank($action['purpose'] ?? null)) {
                    throw ValidationException::withMessages(["{$list}.{$index}.template_uuid" => ['اختر الرسالة أو الغرض منها.']]);
                }
            }
        }

        if (array_key_exists('conditions', $data)) {
            $data['conditions'] = $this->conditions((array) $data['conditions']);
        }

        return $data;
    }

    /**
     * Conditions, checked and rebuilt with only the keys the evaluator reads: a plain
     * `{field, operator, value}`, or `{group: all|any, conditions: [plain, …]}` — one level, up to ten inside.
     *
     * @return array<int,array<string,mixed>>
     */
    private function conditions(array $items): array
    {
        $plain = function (mixed $c, string $at): array {
            $field = is_array($c) ? (string) ($c['field'] ?? '') : '';
            $operator = is_array($c) ? (string) ($c['operator'] ?? '') : '';

            if (! in_array($field, self::FIELDS, true) && ! preg_match('/^custom\.[a-z0-9_]{1,60}$/', $field)) {
                throw ValidationException::withMessages(["{$at}.field" => ['حقل غير معروف.']]);
            }

            if (! in_array($operator, self::OPERATORS, true)) {
                throw ValidationException::withMessages(["{$at}.operator" => ['عامل مقارنة غير معروف.']]);
            }

            $value = $c['value'] ?? null;
            if (is_array($value) && (count($value) > 50 || collect($value)->contains(fn ($v) => ! is_scalar($v) && $v !== null))) {
                throw ValidationException::withMessages(["{$at}.value" => ['قيمة غير صالحة.']]);
            }

            return ['field' => $field, 'operator' => $operator, 'value' => $value];
        };

        return collect($items)->values()->map(function ($item, $i) use ($plain) {
            if (is_array($item) && array_key_exists('group', $item)) {
                $inner = $item['conditions'] ?? null;

                if (! in_array($item['group'], ConditionEvaluator::matches(), true) || ! is_array($inner) || $inner === [] || count($inner) > 10) {
                    throw ValidationException::withMessages(["conditions.{$i}" => ['مجموعة شروط غير صالحة.']]);
                }

                return ['group' => $item['group'], 'conditions' => collect($inner)->values()->map(function ($c, $j) use ($plain, $i) {
                    if (is_array($c) && array_key_exists('group', $c)) {
                        throw ValidationException::withMessages(["conditions.{$i}.conditions.{$j}" => ['لا يمكن وضع مجموعة داخل مجموعة.']]);
                    }

                    return $plain($c, "conditions.{$i}.conditions.{$j}");
                })->all()];
            }

            return $plain($item, "conditions.{$i}");
        })->all();
    }

    private function present(AutomationRule $rule): array
    {
        return [
            'id' => $rule->uuid,
            'name' => $rule->name,
            'trigger' => $rule->trigger,
            'is_enabled' => $rule->is_enabled,
            'mode' => $rule->mode ?? AutomationRule::MODE_AUTO,
            'template_key' => $rule->template_key,
            'priority' => $rule->priority,
            'stops_processing' => $rule->stops_processing,
            'conditions' => $rule->conditions,
            'condition_match' => $rule->condition_match,
            'else_actions' => $rule->else_actions ?? [],
            'actions' => $rule->actions,
            'times_matched' => $rule->times_matched,
            'last_matched_at' => $rule->last_matched_at?->toIso8601String(),
            'runs_count' => $rule->runs_count ?? null,
            'created_at' => $rule->created_at?->toIso8601String(),
        ];
    }

    /** The starting points, with what each one would do spelled out. */
    public function templates()
    {
        $this->authorize('viewAny', AutomationRule::class);

        $installed = AutomationRule::query()->pluck('template_key')->filter()->all();

        return ApiResponse::success([
            'templates' => array_map(fn (array $template) => $template + [
                'installed' => in_array($template['key'], $installed, true),
            ], RuleTemplates::all()),
        ]);
    }

    /**
     * Installs a template as an ordinary rule.
     *
     * It is created disabled. A rule that starts running the moment somebody
     * clicks a card in a gallery is a rule that messages real customers before
     * anyone has read what it does — the workspace turns it on once it has
     * looked, which is also when `preview` is useful.
     */
    public function installTemplate(Request $request, string $key)
    {
        $this->authorize('create', AutomationRule::class);

        if (! RuleTemplates::has($key)) {
            return ApiResponse::error('لا يوجد قالب بهذا الاسم.', status: 404);
        }

        $template = RuleTemplates::get($key);

        $existing = AutomationRule::withTrashed()->where('template_key', $key)->first();

        if ($existing && ! $existing->trashed()) {
            return ApiResponse::error('هذا القالب مُضاف بالفعل.', status: 422);
        }

        /*
         * Deleted once and wanted back. The old row still holds the template's
         * key, so a fresh insert would collide with it; the rule comes back as
         * the template describes it today, switched off like any install.
         */
        if ($existing) {
            $existing->restore();
            $existing->forceFill([
                'name' => $template['name'],
                'trigger' => $template['trigger'],
                'priority' => $template['priority'],
                'conditions' => $template['conditions'],
                'actions' => $this->stampRuleName($template['actions'], $template['name']),
                'is_enabled' => false,
            ])->save();

            $this->audited('automation_rule.installed', $request, $existing, ['after' => $this->trail($existing), 'template' => $key]);

            return ApiResponse::success(['rule' => $this->present($existing->refresh())], status: 201);
        }

        $rule = AutomationRule::create([
            'name' => $template['name'],
            'trigger' => $template['trigger'],
            'priority' => $template['priority'],
            'conditions' => $template['conditions'],
            'actions' => $this->stampRuleName($template['actions'], $template['name']),
            'is_enabled' => false,
            'template_key' => $key,
            'created_by' => $request->user()->id,
        ]);

        $this->audited('automation_rule.installed', $request, $rule, ['after' => $this->trail($rule), 'template' => $key]);

        return ApiResponse::success(['rule' => $this->present($rule)], status: 201);
    }

    /** Who changed which rule, and from what to what — a rule is how the workspace talks to its customers. */
    private function audited(string $action, Request $request, AutomationRule $rule, array $changes): void
    {
        app(AuditLogger::class)->log($action, $request->user(), $rule, $changes + ['name' => $rule->name]);
    }

    /** @return array<string,mixed> */
    private function trail(AutomationRule $rule): array
    {
        return ['name' => $rule->name, 'trigger' => $rule->trigger, 'is_enabled' => (bool) $rule->is_enabled, 'actions' => count((array) $rule->actions), 'conditions' => count((array) $rule->conditions)];
    }

    /**
     * Notifications carry the rule's name so a recipient can find what sent it.
     * Stamped at install rather than read at send time, so renaming a rule does
     * not rewrite the history of messages already delivered under the old name.
     */
    private function stampRuleName(array $actions, string $ruleName): array
    {
        return array_map(
            fn (array $action) => ($action['type'] ?? '') === 'notify'
                ? $action + ['rule_name' => $ruleName]
                : $action,
            $actions,
        );
    }

    /**
     * Whether the rules are doing anything.
     *
     * Readable by anyone who can see the rules, because the question "did the
     * automation call this lead or did we forget" is asked by whoever is
     * holding the phone, not only by whoever wrote the rule.
     */
    public function insights(Request $request, AutomationInsights $insights)
    {
        $this->authorize('viewAny', AutomationRule::class);

        $days = min(90, max(7, (int) $request->integer('days', 30)));

        return ApiResponse::success([
            'summary' => $insights->summary($days),
            'daily' => $insights->daily($days),
            'by_rule' => $insights->byRule($days),
        ]);
    }
}
