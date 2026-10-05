<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Automation\ConditionEvaluator;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Lead;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    ];

    private const FIELDS = [
        'status', 'temperature', 'qualification_status', 'score', 'platform', 'source',
        'campaign_name', 'project_id', 'city', 'budget_min', 'budget_max',
        'purchase_purpose', 'payment_method', 'financing_status', 'purchase_timeline',
        'preferred_property_type', 'preferred_location', 'appointment_interest',
        'assigned_to', 'is_assigned', 'has_phone', 'has_email', 'has_whatsapp',
        're_engaged_count', 'minutes_since_created', 'minutes_since_first_contact',
        'minutes_since_last_contact', 'was_contacted', 'has_replied',
    ];

    private const OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in',
        'greater_than', 'less_than', 'between', 'exists', 'not_exists',
    ];

    private const ACTIONS = [
        'assign', 'change_stage', 'adjust_score', 'add_tag',
        'schedule_follow_up', 'add_note',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', AutomationRule::class);

        $rules = AutomationRule::query()
            ->withCount('runs')
            ->orderBy('trigger')
            ->orderBy('priority')
            ->get();

        return ApiResponse::success(['rules' => $rules->map(fn ($r) => $this->present($r))]);
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
            'actions' => self::ACTIONS,
            'stages' => array_map(fn (LeadStatus $s) => $s->value, LeadStatus::cases()),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', AutomationRule::class);

        $data = $this->validated($request);

        $rule = AutomationRule::create($data + ['created_by' => $request->user()->id]);

        return ApiResponse::created(['rule' => $this->present($rule)]);
    }

    public function update(Request $request, string $uuid)
    {
        // Resolved first: the tenant scope turns another workspace's rule into
        // a 404, which is the right answer — a 403 would confirm it exists.
        $rule = AutomationRule::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $rule);

        $rule->update($this->validated($request, partial: true));

        return ApiResponse::success(['rule' => $this->present($rule->fresh())]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $rule = AutomationRule::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $rule);

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

        $matched = $sample
            ->map(fn (Lead $lead) => [$lead, $evaluator->evaluate($lead, $rule->conditions ?? [])])
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
        ]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'trigger' => [$required, Rule::in(self::TRIGGERS)],
            'is_enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'stops_processing' => ['sometimes', 'boolean'],

            'conditions' => [$required, 'array', 'max:20'],
            'conditions.*.field' => ['required', Rule::in(self::FIELDS)],
            'conditions.*.operator' => ['required', Rule::in(self::OPERATORS)],
            'conditions.*.value' => ['nullable'],

            // At least one action: a rule that matches and does nothing is a
            // rule somebody will believe is working.
            'actions' => [$required, 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', Rule::in(self::ACTIONS)],
        ]);
    }

    private function present(AutomationRule $rule): array
    {
        return [
            'id' => $rule->uuid,
            'name' => $rule->name,
            'trigger' => $rule->trigger,
            'is_enabled' => $rule->is_enabled,
            'priority' => $rule->priority,
            'stops_processing' => $rule->stops_processing,
            'conditions' => $rule->conditions,
            'actions' => $rule->actions,
            'times_matched' => $rule->times_matched,
            'last_matched_at' => $rule->last_matched_at?->toIso8601String(),
            'runs_count' => $rule->runs_count ?? null,
            'created_at' => $rule->created_at?->toIso8601String(),
        ];
    }
}
