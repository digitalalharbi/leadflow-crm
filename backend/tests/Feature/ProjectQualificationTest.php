<?php

namespace Tests\Feature;

use App\Domain\Leads\NextBestAction;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAnswer;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Services\Leads\LeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project asks its own questions, and the answers have consequences.
 *
 * Everything here protects one idea: the score stays explainable. An answer
 * adds or removes points (capped), may make the lead a priority, or may say it
 * is the wrong fit — and each says so in the reasons. None of it closes a lead.
 */
class ProjectQualificationTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Ask Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'فلل النرجس']));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل '.random_int(100, 999),
            'phone_normalized' => '+9665'.random_int(10000000, 99999999),
            'status' => 'contacted',
            'first_contact_at' => now(),
            'assigned_to' => $this->t['admin']->id,
            'project_id' => $this->project->id,
        ], $attributes)));
    }

    private function ask(array $payload)
    {
        return $this->actingAs($this->t['admin'])->postJson('/api/v1/projects/'.$this->project->uuid.'/questions', $payload);
    }

    /** What the lead scores right now — the stored number is only as fresh as the last save. */
    private function scoreOf(Lead $lead): int
    {
        return $this->asTenant($this->t['tenant'], fn () => app(LeadScoringService::class)->evaluate($lead->fresh())['score']);
    }

    private function answer(Lead $lead, array $answers)
    {
        return $this->actingAs($this->t['admin'])->putJson("/api/v1/leads/{$lead->uuid}/answers", ['answers' => $answers]);
    }

    private function family(?array $rules = null): string
    {
        return $this->ask([
            'label' => 'هل الشراء لعائلة؟', 'type' => 'boolean',
            'rules' => $rules ?? [['op' => 'is_true', 'points' => 10]],
        ])->assertCreated()->json('data.question.key');
    }

    // ----------------------------------------------------- managing questions

    public function test_a_manager_can_ask_and_a_rep_cannot(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $payload = ['label' => 'ميزانية', 'type' => 'number'];
        $url = '/api/v1/projects/'.$this->project->uuid.'/questions';

        $this->actingAs($manager)->postJson($url, $payload)->assertCreated();
        $this->actingAs($rep)->postJson($url, $payload)->assertForbidden();
        // A rep may read what is asked — they have to fill it in.
        $this->actingAs($rep)->getJson($url)->assertOk();
    }

    public function test_the_key_is_made_from_the_label_and_is_unique(): void
    {
        $a = $this->ask(['label' => 'Budget', 'type' => 'number'])->json('data.question.key');
        $b = $this->ask(['label' => 'Budget', 'type' => 'number'])->json('data.question.key');

        $this->assertSame('budget', $a);
        $this->assertSame('budget_2', $b);
    }

    public function test_a_choice_question_needs_two_distinct_choices(): void
    {
        $this->ask(['label' => 'نوع', 'type' => 'select', 'options' => [['value' => 'a', 'label' => 'أ']]])->assertStatus(422);
        $this->ask(['label' => 'نوع', 'type' => 'select', 'options' => [['value' => 'a', 'label' => 'أ'], ['value' => 'a', 'label' => 'ب']]])->assertStatus(422);
        $this->ask(['label' => 'نوع', 'type' => 'select'])->assertStatus(422);
    }

    /** A rule that never fires looks fine on the screen for months. */
    public function test_a_rule_that_cannot_fire_is_refused(): void
    {
        // An operator for a different type.
        $this->ask(['label' => 'x', 'type' => 'boolean', 'rules' => [['op' => 'gte', 'value' => 3, 'points' => 5]]])->assertStatus(422);
        // A choice that is not on the list.
        $this->ask(['label' => 'x', 'type' => 'select',
            'options' => [['value' => 'a', 'label' => 'أ'], ['value' => 'b', 'label' => 'ب']],
            'rules' => [['op' => 'equals', 'value' => 'zzz', 'points' => 5]]])->assertStatus(422);
        // A range the wrong way round.
        $this->ask(['label' => 'x', 'type' => 'number', 'rules' => [['op' => 'between', 'value' => [10, 1], 'points' => 5]]])->assertStatus(422);
    }

    public function test_points_beyond_the_cap_are_refused(): void
    {
        $this->ask(['label' => 'x', 'type' => 'boolean', 'rules' => [['op' => 'is_true', 'points' => 99]]])->assertStatus(422);
    }

    public function test_a_questions_type_cannot_be_changed(): void
    {
        $id = $this->ask(['label' => 'x', 'type' => 'boolean'])->json('data.question.id');

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/project-questions/$id", ['type' => 'number'])->assertStatus(422);
    }

    public function test_one_workspace_cannot_ask_on_anothers_project(): void
    {
        $other = $this->provisionTenant('Elsewhere');

        $this->actingAs($other['admin'])->postJson('/api/v1/projects/'.$this->project->uuid.'/questions', ['label' => 'x', 'type' => 'boolean'])
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- scoring

    public function test_an_answer_moves_the_score_and_says_why(): void
    {
        $key = $this->family();
        $lead = $this->lead();
        $before = $this->scoreOf($lead);

        $this->answer($lead, [$key => true])->assertOk();

        $this->assertSame($before + 10, (int) $lead->fresh()->score);
        $reasons = $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}/qualification")->json('data.reasons');
        $this->assertContains('هل الشراء لعائلة؟: نعم', array_column($reasons, 'label'));
    }

    public function test_a_no_that_no_rule_matches_changes_nothing(): void
    {
        $key = $this->family();
        $lead = $this->lead();

        $before = $this->scoreOf($lead);

        $this->answer($lead, [$key => false])->assertOk();

        $this->assertSame($before, (int) $lead->fresh()->score);
    }

    public function test_the_answers_can_never_move_a_score_by_more_than_the_cap(): void
    {
        $keys = [];
        foreach (range(1, 4) as $i) {
            $keys[] = $this->ask(['label' => "q$i", 'type' => 'boolean', 'rules' => [['op' => 'is_true', 'points' => 20]]])->json('data.question.key');
        }

        $lead = $this->lead();
        $baseline = $this->scoreOf($lead);

        $this->answer($lead, array_fill_keys($keys, true))->assertOk();

        $this->assertLessThanOrEqual($baseline + 30, (int) $lead->fresh()->score);
    }

    public function test_the_first_matching_rule_wins(): void
    {
        $id = $this->ask(['label' => 'سعر', 'type' => 'number', 'rules' => [
            ['op' => 'gte', 'value' => 100, 'points' => 12],
            ['op' => 'gte', 'value' => 50, 'points' => 5],
        ]])->json('data.question.key');

        $lead = $this->lead();
        $baseline = $this->scoreOf($lead);

        $this->answer($lead, [$id => 150])->assertOk();

        $this->assertSame($baseline + 12, (int) $lead->fresh()->score);
    }

    // --------------------------------------------------------------- priority

    public function test_an_answer_can_make_a_hot_lead_a_priority(): void
    {
        $key = $this->family([['op' => 'is_true', 'points' => 30, 'effect' => 'priority']]);
        $lead = $this->lead(['budget_max' => 99_000_000, 'purchase_timeline' => 'immediate', 'appointment_interest' => true]);

        $this->answer($lead, [$key => true])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('hot', $fresh->temperature->value);
        $this->assertTrue($fresh->is_priority);
    }

    /** Priority is not a fourth temperature: it needs to be hot first. */
    public function test_a_cold_lead_is_never_a_priority(): void
    {
        $key = $this->family([['op' => 'is_true', 'points' => 0, 'effect' => 'priority']]);
        $lead = $this->lead(['first_contact_at' => null, 'status' => 'new']);

        $this->answer($lead, [$key => true])->assertOk();

        $this->assertFalse($lead->fresh()->is_priority);
    }

    public function test_a_priority_is_still_counted_as_hot(): void
    {
        $key = $this->family([['op' => 'is_true', 'points' => 30, 'effect' => 'priority']]);
        $lead = $this->lead(['budget_max' => 99_000_000, 'purchase_timeline' => 'immediate', 'appointment_interest' => true]);
        $this->answer($lead, [$key => true])->assertOk();

        $this->assertSame(1, $this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard')->json('data.summary.hot_leads'));
    }

    public function test_a_priority_is_worked_a_step_earlier(): void
    {
        $lead = $this->lead(['next_follow_up_at' => now()->addHour()]);
        $plain = $this->asTenant($this->t['tenant'], fn () => app(NextBestAction::class)->for($lead->fresh()));

        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['is_priority' => true])->saveQuietly());
        $boosted = $this->asTenant($this->t['tenant'], fn () => app(NextBestAction::class)->for($lead->fresh()));

        $this->assertSame('high', $plain['urgency']);
        $this->assertSame('critical', $boosted['urgency']);
        $this->assertStringStartsWith('أولوية', $boosted['reason']);
    }

    public function test_priority_does_not_invent_work(): void
    {
        $lead = $this->lead(['next_follow_up_at' => now()->addDays(3)]);
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['is_priority' => true])->saveQuietly());

        $result = $this->asTenant($this->t['tenant'], fn () => app(NextBestAction::class)->for($lead->fresh()));

        $this->assertSame('wait', $result['action']);
    }

    public function test_the_work_queue_offers_a_priority_first(): void
    {
        $this->lead(['status' => 'new', 'first_contact_at' => null, 'score' => 80]);
        $priority = $this->lead(['status' => 'contacted', 'next_follow_up_at' => now()->subMinutes(5)]);
        $this->asTenant($this->t['tenant'], fn () => $priority->forceFill(['is_priority' => true])->saveQuietly());

        $top = $this->actingAs($this->t['admin'])->getJson('/api/v1/work/next')->json('data.lead.id');

        $this->assertSame($priority->uuid, $top);
    }

    public function test_priority_has_its_own_filter_and_command_center_row(): void
    {
        $lead = $this->lead();
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['is_priority' => true])->saveQuietly());
        $this->lead();

        $this->assertCount(1, $this->actingAs($this->t['admin'])->getJson('/api/v1/leads?priority=1')->json('data'));

        $row = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard/command')->json('data.attention'))->firstWhere('key', 'priority');
        $this->assertSame(1, $row['count']);
    }

    // --------------------------------------------------------------- wrong fit

    public function test_a_wrong_fit_answer_zeroes_the_score_and_asks_a_person_to_look(): void
    {
        $key = $this->family([['op' => 'is_false', 'points' => 0, 'effect' => 'wrong_fit']]);
        $lead = $this->lead(['budget_max' => 99_000_000, 'purchase_timeline' => 'immediate', 'appointment_interest' => true]);

        $this->answer($lead, [$key => false])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame(0, (int) $fresh->score);
        $this->assertTrue($fresh->wrong_fit);
        $this->assertFalse($fresh->is_priority);
        // Not closed: an answer ticked on a form is a claim.
        $this->assertSame('contacted', $fresh->status->value);

        $action = $this->asTenant($this->t['tenant'], fn () => app(NextBestAction::class)->for($fresh));
        $this->assertSame('review_fit', $action['action']);
    }

    public function test_correcting_the_answer_lifts_the_flag(): void
    {
        $key = $this->family([['op' => 'is_false', 'points' => 0, 'effect' => 'wrong_fit']]);
        $lead = $this->lead();
        $this->answer($lead, [$key => false])->assertOk();
        $this->assertTrue($lead->fresh()->wrong_fit);

        $this->answer($lead, [$key => true])->assertOk();

        $this->assertFalse($lead->fresh()->wrong_fit);
    }

    // ----------------------------------------------------------- the answers

    public function test_an_answer_that_does_not_fit_its_question_is_refused_by_name(): void
    {
        $key = $this->family();
        $lead = $this->lead();

        $this->answer($lead, [$key => 'maybe'])->assertStatus(422)->assertJsonPath('errors', fn ($errors) => ($errors["answers.$key"][0] ?? null) === 'Yes or no is expected.');
    }

    public function test_a_choice_must_be_one_of_the_choices(): void
    {
        $key = $this->ask(['label' => 'نوع', 'type' => 'select', 'options' => [['value' => 'villa', 'label' => 'فيلا'], ['value' => 'flat', 'label' => 'شقة']]])
            ->json('data.question.key');
        $lead = $this->lead();

        $this->answer($lead, [$key => 'castle'])->assertStatus(422);
        $this->answer($lead, [$key => 'villa'])->assertOk();
    }

    public function test_a_question_the_project_does_not_ask_is_refused(): void
    {
        $this->answer($this->lead(), ['nonsense' => 'x'])->assertStatus(422);
    }

    public function test_one_bad_answer_saves_nothing(): void
    {
        $good = $this->family();
        $lead = $this->lead();

        $this->answer($lead, [$good => true, 'nonsense' => 'x'])->assertStatus(422);

        $this->assertNull($this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}/answers")->json('data.questions.0.answer'));
    }

    public function test_a_blank_answer_clears_it(): void
    {
        $key = $this->family();
        $lead = $this->lead();
        $this->answer($lead, [$key => true])->assertOk();

        $this->answer($lead, [$key => null])->assertOk();

        $this->assertNull($this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}/answers")->json('data.questions.0.answer'));
    }

    public function test_a_lead_only_gets_its_own_projects_questions(): void
    {
        $this->family();
        $elsewhere = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'مشروع آخر']));
        $lead = $this->lead(['project_id' => $elsewhere->id]);

        $this->assertSame([], $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}/answers")->json('data.questions'));
    }

    public function test_a_lead_with_no_project_scores_exactly_as_before(): void
    {
        $this->family();
        $lead = $this->lead(['project_id' => null]);

        $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}/answers")->assertOk()->assertJsonPath('data.questions', []);
    }

    public function test_an_unanswered_required_question_keeps_a_lead_from_being_qualified(): void
    {
        $this->ask(['label' => 'مطلوب', 'type' => 'boolean', 'is_required' => true])->assertCreated();
        $lead = $this->lead([
            'purchase_purpose' => 'residence', 'budget_max' => 1_000_000, 'payment_method' => 'cash',
            'preferred_property_type' => 'villa', 'purchase_timeline' => 'immediate', 'appointment_interest' => true,
        ]);

        $status = $this->asTenant($this->t['tenant'], fn () => app(LeadScoringService::class)->qualificationStatus($lead->fresh()));

        $this->assertSame('partially_qualified', $status->value);
    }

    public function test_answering_the_required_question_qualifies_it(): void
    {
        $key = $this->ask(['label' => 'مطلوب', 'type' => 'boolean', 'is_required' => true])->json('data.question.key');
        $lead = $this->lead([
            'purchase_purpose' => 'residence', 'budget_max' => 1_000_000, 'payment_method' => 'cash',
            'preferred_property_type' => 'villa', 'purchase_timeline' => 'immediate', 'appointment_interest' => true,
        ]);

        $this->answer($lead, [$key => true])->assertOk()->assertJsonPath('data.qualification_status', 'qualified');
    }

    public function test_it_is_on_the_timeline_and_the_score_change_is_explained(): void
    {
        $key = $this->family();
        $lead = $this->lead();

        $this->answer($lead, [$key => true])->assertOk();

        $types = LeadActivity::withoutGlobalScopes()->where('lead_id', $lead->id)->pluck('type')->map(fn ($t) => $t->value ?? $t)->all();
        $this->assertContains('qualification_updated', $types);
        $this->assertContains('score_changed', $types);
    }

    public function test_one_workspace_cannot_answer_for_anothers_lead(): void
    {
        $key = $this->family();
        $lead = $this->lead();
        $other = $this->provisionTenant('Elsewhere');

        $this->actingAs($other['admin'])->putJson("/api/v1/leads/{$lead->uuid}/answers", ['answers' => [$key => true]])->assertNotFound();
    }

    public function test_questions_can_be_deleted_and_their_answers_go_with_them(): void
    {
        $key = $this->family();
        $lead = $this->lead();
        $this->answer($lead, [$key => true])->assertOk();

        $id = ProjectQuestion::withoutGlobalScopes()->where('key', $key)->value('uuid');
        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/project-questions/$id")->assertOk();

        $this->assertSame(0, LeadAnswer::withoutGlobalScopes()->count());
    }
}
