<?php

namespace Tests\Feature;

use App\Domain\Leads\InterestEngine;
use App\Domain\Tracking\Forms\FormTemplates;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\LandingForm;
use App\Models\Lead;
use App\Models\LeadFact;
use App\Models\LeadSourceEvent;
use App\Models\Project;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the advanced form does when a stranger presses the button: the matrix and the repeater arrive whole, a hidden section is not
 * demanded, the phone leaves as one international number with the typed one kept beside it, the version the visitor saw is remembered,
 * the workspace's own rules score the intent, and the thank-you tells the page what to do — with nothing of the customer in it.
 */
class FormSubmissionV2Test extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $rival;

    private Project $project;

    private TrackingLink $link;

    private string $formId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Submit V2 Co');
        $this->rival = $this->provisionTenant('Rival Submit V2 Co');
        $this->t['tenant']->settings->forceFill(['assignment_mode' => 'round_robin'])->save();
        $this->makeUser($this->t['tenant']);
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));

        $d = FormTemplates::get('investment_qualification');
        $d['settings']['mode'] = 'single';
        $d['layout'] = ['sections' => [['rows' => array_map(fn ($f) => ['columns' => [['fields' => [$f['key']]]]], array_values(array_filter($d['fields'], fn ($f) => empty($f['hidden']))))]]];
        $d['fields'][] = ['key' => 'guests', 'type' => 'repeater', 'label' => 'المرافقون', 'mapping' => 'custom.companions', 'required' => false, 'hidden' => false, 'repeater' => [
            'subfields' => [['key' => 'name', 'type' => 'text', 'label' => 'الاسم', 'required' => true], ['key' => 'mobile', 'type' => 'phone', 'label' => 'الجوال']],
            'min_rows' => 0, 'max_rows' => 3, 'default_rows' => 1,
        ]];
        $d['layout']['sections'][0]['rows'][] = ['columns' => [['fields' => ['guests']]]];
        $d['settings']['thanks'] = ['actions' => [['type' => 'whatsapp', 'label' => 'واتساب', 'value' => '+966551112222']], 'redirect_url' => null, 'redirect_delay' => 5];

        $this->formId = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', ['name' => 'تأهيل', 'project_id' => $this->project->uuid] + $d)->assertCreated()->json('data.form.id');
        $this->actingAs($this->t['admin'])->postJson("/api/v1/tracking/forms/{$this->formId}/publish")->assertOk();

        $form = $this->asTenant($this->t['tenant'], fn () => LandingForm::where('uuid', $this->formId)->first());
        $this->link = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create([
            'token' => 'sub'.random_int(100000, 999999), 'name' => 'إعلان', 'channel' => 'meta', 'mode' => 'landing', 'landing_form_id' => $form->id,
            'project_id' => $this->project->id, 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'invest_oct',
        ]));
    }

    private function answers(array $o = []): array
    {
        return array_merge(['name' => 'سلمان', 'mobile' => '0551234567', 'budget' => 2500000, 'financing' => 'cash', 'timeline' => 'now', 'horizon' => 'mid',
            'priorities' => ['return' => 'high', 'location' => 'mid']], $o);
    }

    private function sendForm(array $answers, array $extra = [])
    {
        return $this->postJson('/api/v1/public/landing/'.$this->link->token.'/submit', $extra + [
            'submission_id' => (string) str()->uuid(), 'consent' => true, 'answers' => $answers,
            'visitor_id' => 'v_'.str_repeat('a', 20), 'session_id' => 's_'.str_repeat('b', 20),
        ]);
    }

    private function newestLead(): ?Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::query()->latest('id')->first());
    }

    public function test_a_matrix_and_a_repeater_arrive_whole_and_the_lead_reads_them_as_text(): void
    {
        $this->sendForm($this->answers(['guests' => [['name' => 'نورة', 'mobile' => '0555000111'], ['name' => '', 'mobile' => '']]]))->assertOk();

        $lead = $this->newestLead();
        $values = $this->asTenant($this->t['tenant'], function () use ($lead) {
            return CustomFieldValue::where('lead_id', $lead->id)->get()->mapWithKeys(fn ($v) => [CustomField::find($v->custom_field_id)->key => $v->value])->all();
        });

        $this->assertStringContainsString('العائد الإيجاري: مهم جدًا', $values['investor_priorities'] ?? '');
        $this->assertStringContainsString('الاسم: نورة', $values['companions'] ?? '');

        $source = LeadSourceEvent::withoutGlobalScopes()->where('lead_id', $lead->id)->first();
        $this->assertEquals(['return' => 'high', 'location' => 'mid'], $source->payload['answers']['priorities'], 'the structure is kept as it came');
        $this->assertSame([['name' => 'نورة', 'mobile' => '+966555000111']], $source->payload['answers']['guests']);
        $this->assertSame('0551234567', $source->payload['typed']['mobile'], 'the phone as typed, beside the one the lead keeps');
        $this->assertSame('+966551234567', $lead->phone_normalized);
    }

    public function test_a_wrong_matrix_or_repeater_row_is_refused_beside_its_cell_and_nothing_is_made(): void
    {
        $r = $this->sendForm($this->answers(['priorities' => ['return' => 'extreme'], 'guests' => [['name' => 'نورة', 'mobile' => '12']]]))->assertStatus(422);

        $this->assertSame('invalid_matrix', $r->json('errors.priorities'));
        $this->assertSame('invalid_phone', $r->json('errors')['guests.0.mobile'] ?? null);
        $this->assertCount(0, $this->asTenant($this->t['tenant'], fn () => Lead::query()->get()));
    }

    public function test_the_submission_remembers_the_version_the_visitor_saw_even_after_a_new_one_is_published(): void
    {
        $this->sendForm($this->answers())->assertOk();

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/forms/{$this->formId}", ['headline' => 'عنوان جديد'])->assertOk();
        $this->actingAs($this->t['admin'])->postJson("/api/v1/tracking/forms/{$this->formId}/publish")->assertOk()->assertJsonPath('data.form.published_version.version', 2);

        $this->sendForm($this->answers(['mobile' => '0551230000']))->assertOk();

        $versions = TrackingEvent::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->where('event_type', 'form_submit')->orderBy('id')->get()
            ->map(fn ($e) => $e->meta['version'])->all();
        $this->assertSame([1, 2], $versions);

        $this->assertSame(2, LeadSourceEvent::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->whereNotNull('form_version_id')->distinct()->count('form_version_id'));
    }

    public function test_the_workspaces_rules_score_the_intent_and_the_interest_engine_says_why(): void
    {
        $this->sendForm($this->answers())->assertOk();

        $lead = $this->newestLead();
        $fact = LeadFact::withoutGlobalScopes()->where('lead_id', $lead->id)->where('kind', 'form_intent')->first();

        $this->assertNotNull($fact);
        $this->assertSame(30, $fact->detail['points'], 'budget 12 + soon 10 + cash 8 = 30, the cap');
        $this->assertEqualsCanonicalizing(['budget_high', 'soon', 'financing_ready'], array_column($fact->detail['matched'], 'id'));

        $signals = $this->asTenant($this->t['tenant'], fn () => collect((new InterestEngine)->evaluate($lead)['signals']));
        $this->assertSame(30, $signals->firstWhere('key', 'form_intent')['points']);
    }

    public function test_an_answer_that_matches_no_rule_adds_nothing(): void
    {
        $this->sendForm($this->answers(['budget' => 300000, 'financing' => null, 'timeline' => 'year']))->assertOk();

        $this->assertSame(0, LeadFact::withoutGlobalScopes()->where('kind', 'form_intent')->count());
    }

    public function test_a_condition_on_the_server_requires_what_the_page_asked_and_demands_nothing_it_did_not_show(): void
    {
        // The horizon is required only when the budget reaches a million: below it, it is not asked.
        $this->sendForm($this->answers(['budget' => 500000, 'horizon' => null]))->assertOk();
        $this->sendForm($this->answers(['mobile' => '0551119999', 'budget' => 5000000, 'horizon' => null]))->assertStatus(422)->assertJsonPath('errors.horizon', 'required');
    }

    public function test_the_thank_you_tells_the_page_what_to_offer_and_the_datalayer_has_no_customer_in_it(): void
    {
        $r = $this->sendForm($this->answers())->assertOk();

        $this->assertSame('whatsapp', $r->json('data.thank_you.actions.0.type'));
        $layer = $r->json('data.datalayer');
        $this->assertSame($this->link->uuid, $layer['tracking_link_id']);
        $this->assertSame($this->project->uuid, $layer['project_id']);
        $this->assertSame($this->formId, $layer['form_id']);
        $this->assertSame(1, $layer['form_version']);
        $this->assertSame('meta', $layer['source']);

        $json = $r->getContent();
        foreach (['سلمان', '0551234567', '+966551234567', 'lead_id'] as $pii) {
            $this->assertStringNotContainsString($pii, $json, "the datalayer carried {$pii}");
        }
    }

    public function test_a_retry_is_one_submission_and_still_gets_the_thank_you(): void
    {
        $id = (string) str()->uuid();
        $this->sendForm($this->answers(), ['submission_id' => $id])->assertOk();
        $again = $this->sendForm($this->answers(), ['submission_id' => $id])->assertOk();

        $this->assertTrue($again->json('data.duplicate'));
        $this->assertSame('whatsapp', $again->json('data.thank_you.actions.0.type'));
        $this->assertCount(1, $this->asTenant($this->t['tenant'], fn () => Lead::query()->get()));
    }

    public function test_a_page_published_in_one_workspace_cannot_be_filled_into_anothers(): void
    {
        $this->sendForm($this->answers())->assertOk();

        $this->assertCount(0, $this->asTenant($this->rival['tenant'], fn () => Lead::query()->get()));
        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->where('tenant_id', $this->rival['tenant']->id)->count());
    }

    public function test_the_draft_being_edited_cannot_change_what_a_running_page_demands(): void
    {
        // The manager makes the budget impossible in the draft; the live page still takes the old answers.
        $form = $this->actingAs($this->t['admin'])->getJson("/api/v1/tracking/forms/{$this->formId}")->assertOk()->json('data.form');
        $fields = collect($form['fields'])->map(fn ($f) => $f['key'] === 'budget' ? array_replace($f, ['validation' => ['min' => 99000000000]]) : $f)->all();
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/forms/{$this->formId}", ['fields' => $fields])->assertOk()->assertJsonPath('data.form.has_unpublished_changes', true);

        $this->sendForm($this->answers())->assertOk();
    }

    public function test_looking_at_the_page_and_reporting_steps_does_not_use_up_the_allowance_to_send_the_form(): void
    {
        // One visitor on one phone: they open the page, move through the steps, and send. Each kind of request has its own allowance —
        // a shared counter would let the views and the beacons of a busy mobile network lock its customers out of the one button that matters.
        foreach (range(1, 45) as $i) {
            $this->getJson('/api/v1/public/landing/'.$this->link->token)->assertOk();
        }
        foreach (range(1, 45) as $i) {
            $this->postJson('/api/v1/track/'.$this->link->token, ['event' => 'form_step', 'meta' => ['step' => '1']])->assertNoContent();
        }

        $this->sendForm($this->answers())->assertOk();
    }
}
