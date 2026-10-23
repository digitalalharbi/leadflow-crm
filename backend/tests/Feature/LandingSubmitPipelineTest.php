<?php

namespace Tests\Feature;

use App\Domain\Tracking\EventType;
use App\Domain\Tracking\Forms\FormSchema;
use App\Models\LandingForm;
use App\Models\Lead;
use App\Models\Project;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Models\VisitorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * From the visitor's click to the lead: the public landing page, the form on it, and the one pipeline a submit
 * runs — validate, record, normalise, de-duplicate, attribute, create — without a person, without losing the
 * lead to anything, and without ever counting one submit twice.
 */
class LandingSubmitPipelineTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $rival;

    private Project $project;

    private LandingForm $form;

    private TrackingLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Pipeline Co');
        $this->rival = $this->provisionTenant('Rival Pipeline Co');
        $this->t['tenant']->settings->forceFill(['assignment_mode' => 'round_robin'])->save();
        $this->makeUser($this->t['tenant']);

        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $this->form = $this->asTenant($this->t['tenant'], fn () => LandingForm::create([
            'project_id' => $this->project->id, 'name' => 'نموذج النرجس', 'status' => 'published', 'headline' => 'ابدأ امتلاك منزلك', 'description' => 'فلل عصرية',
            'benefits' => ['موقع مميز'], 'cta_label' => 'اطلب التفاصيل', 'consent_required' => true, 'consent_text' => 'أوافق على التواصل',
            'thank_you_title' => 'شكرًا لك', 'thank_you_message' => 'سنتواصل معك قريبًا',
            'fields' => app(FormSchema::class)->normalize([
                ['key' => 'name', 'type' => 'text', 'label' => 'الاسم', 'required' => true, 'mapping' => 'lead.full_name'],
                ['key' => 'mobile', 'type' => 'phone', 'label' => 'الجوال', 'required' => true, 'mapping' => 'lead.phone'],
                ['key' => 'budget', 'type' => 'number', 'label' => 'الميزانية', 'mapping' => 'lead.budget_max', 'step' => 2],
                ['key' => 'areas', 'type' => 'multi_select', 'label' => 'الأحياء', 'mapping' => 'custom.areas', 'step' => 2, 'options' => [['value' => 'north', 'label' => 'شمال'], ['value' => 'east', 'label' => 'شرق']]],
                ['key' => 'source', 'type' => 'hidden', 'label' => 'المصدر', 'hidden' => true, 'default' => '@source', 'mapping' => 'tracking.source'],
            ]),
        ]));
        $this->link = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create([
            'token' => 'land'.random_int(100000, 999999), 'name' => 'إعلان ميتا', 'channel' => 'meta', 'mode' => 'landing', 'landing_form_id' => $this->form->id,
            'project_id' => $this->project->id, 'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'narjis_oct',
        ]));
    }

    private function answers(array $overrides = []): array
    {
        return array_merge(['name' => 'سلمان القحطاني', 'mobile' => '0551234567', 'budget' => 1800000, 'areas' => ['north']], $overrides);
    }

    private function submit(array $body = [], ?TrackingLink $link = null)
    {
        return $this->postJson('/api/v1/public/landing/'.($link ?? $this->link)->token.'/submit', $body + [
            'submission_id' => (string) str()->uuid(), 'consent' => true, 'answers' => $this->answers(),
            'visitor_id' => 'v_'.str_repeat('a', 20), 'session_id' => 's_'.str_repeat('b', 20),
        ]);
    }

    private function leads()
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::query()->get());
    }

    private function events(string $type)
    {
        return TrackingEvent::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->where('event_type', $type)->get();
    }

    // ---------------------------------------------------------------- the page

    public function test_the_public_page_shows_what_to_fill_never_where_it_goes_and_counts_the_view(): void
    {
        $response = $this->getJson('/api/v1/public/landing/'.$this->link->token)->assertOk();
        $page = $response->json('data.landing');

        $this->assertSame('ابدأ امتلاك منزلك', $page['headline']);
        $this->assertSame('النرجس', $page['project']['name']);
        $this->assertSame(['name', 'mobile', 'budget', 'areas'], array_column($page['fields'], 'key'), 'the hidden source is not drawn');
        $this->assertSame(2, $page['steps']);
        $this->assertTrue($page['consent']['required']);
        $this->assertStringNotContainsString('lead.', json_encode($page), 'where an answer lands is not for the visitor');
        $this->assertStringNotContainsString('@source', json_encode($page));
        $this->assertMatchesRegularExpression('/^v_[a-z0-9]{20}$/', $response->json('data.tracking.visitor_id'));
        $this->assertMatchesRegularExpression('/^s_[a-z0-9]{20}$/', $response->json('data.tracking.session_id'));

        $views = $this->events(EventType::Visit->value);
        $this->assertCount(1, $views, 'the view was not recorded before the page was shown');
        $this->assertSame($this->link->id, $views->first()->tracking_link_id);
    }

    public function test_a_page_that_is_not_ready_is_not_there(): void
    {
        $this->asTenant($this->t['tenant'], fn () => $this->form->update(['status' => 'draft']));
        $this->getJson('/api/v1/public/landing/'.$this->link->token)->assertNotFound();

        $this->asTenant($this->t['tenant'], fn () => $this->form->update(['status' => 'published']));
        $this->asTenant($this->t['tenant'], fn () => $this->link->update(['is_active' => false]));
        $this->getJson('/api/v1/public/landing/'.$this->link->token)->assertNotFound();

        $this->getJson('/api/v1/public/landing/nope123456')->assertNotFound();
    }

    // ---------------------------------------------------------------- the submit

    public function test_a_submit_becomes_a_lead_that_remembers_where_it_came_from(): void
    {
        // The visitor clicked the ad first: the click id and the link are on the trail before the form is.
        $click = $this->get('/t/'.$this->link->token.'?fbclid=FB123');
        $vid = $click->getCookie('mt_vid', false)->getValue();
        $sid = VisitorSession::withoutGlobalScopes()->first()->session_id;

        $this->submit(['visitor_id' => $vid, 'session_id' => $sid])->assertOk()->assertJsonPath('data.ok', true);

        $lead = $this->leads()->first();
        $this->assertNotNull($lead, 'no lead was made');
        $this->assertSame('سلمان القحطاني', $lead->full_name);
        $this->assertSame('+966551234567', $lead->phone_normalized, 'the number was normalised');
        $this->assertEquals(1800000.0, (float) $lead->budget_max);
        $this->assertSame($this->project->id, $lead->project_id);
        $this->assertSame('meta', $lead->platform, 'the platform is the ad\'s, not "landing page"');
        $this->assertSame('narjis_oct', $lead->utm_campaign);
        $this->assertSame($this->link->id, $lead->first_touch_link_id);
        $this->assertSame('FB123', $lead->click_ids['first']['fbclid'] ?? null);
        $this->assertSame('granted', $lead->tracking_consent);
        $this->assertNotNull($lead->assigned_to, 'nobody was assigned');

        // Every answer is kept as it came, and the visit's own metadata is kept apart.
        $payload = $lead->provider_payload ?? [];
        $this->assertSame('1800000', (string) ($payload['answers']['budget'] ?? $payload['budget'] ?? ''), 'the raw submission was lost');
        $this->assertSame('meta', $payload['tracking']['source'] ?? null);
    }

    public function test_one_submit_is_one_event_and_a_retry_is_not_a_second_lead(): void
    {
        $id = (string) str()->uuid();

        $first = $this->submit(['submission_id' => $id])->assertOk();
        $second = $this->submit(['submission_id' => $id])->assertOk();

        $this->assertFalse((bool) $first->json('data.duplicate'));
        $this->assertTrue((bool) $second->json('data.duplicate'), 'a retry should say it was the same submit');
        $this->assertCount(1, $this->leads());
        $this->assertCount(1, $this->events(EventType::FormSubmit->value), 'the submit was counted twice');
        $this->assertSame($id, $this->events(EventType::FormSubmit->value)->first()->event_id);
    }

    public function test_the_same_customer_filling_it_again_is_one_lead(): void
    {
        $this->submit()->assertOk();
        $this->submit(['answers' => $this->answers(['mobile' => '+966 55 123 4567', 'budget' => 2500000])])->assertOk();

        $this->assertCount(1, $this->leads(), 'a duplicate lead was made');
    }

    public function test_a_bad_answer_is_refused_with_the_field_and_makes_nothing(): void
    {
        $response = $this->submit(['answers' => $this->answers(['mobile' => 'abc', 'name' => ''])])->assertStatus(422);

        $this->assertEqualsCanonicalizing(['mobile', 'name'], array_keys($response->json('errors')));
        $this->assertCount(0, $this->leads());
        $this->assertCount(0, $this->events(EventType::FormSubmit->value), 'a refused submit is not a submission');
    }

    public function test_consent_is_asked_for_and_without_it_nothing_is_taken(): void
    {
        $this->submit(['consent' => false])->assertStatus(422)->assertJsonPath('errors.consent', 'required');
        $this->assertCount(0, $this->leads());
    }

    public function test_the_visitor_cannot_choose_what_the_server_fills_in(): void
    {
        $this->submit(['answers' => $this->answers(['source' => 'evil', 'is_admin' => true, 'project_id' => 999])])->assertOk();

        $payload = $this->leads()->first()->provider_payload ?? [];
        $this->assertSame('meta', $payload['tracking']['source'] ?? null, 'the source came from the browser, not the link');
        $this->assertStringNotContainsString('evil', json_encode($payload));
        $this->assertSame($this->project->id, $this->leads()->first()->project_id);
    }

    public function test_a_bot_that_fills_the_trap_is_thanked_and_ignored(): void
    {
        $this->submit(['website' => 'http://spam.example'])->assertOk();

        $this->assertCount(0, $this->leads());
    }

    public function test_a_form_that_is_not_published_takes_nothing(): void
    {
        $this->asTenant($this->t['tenant'], fn () => $this->form->update(['status' => 'archived']));

        $this->submit()->assertNotFound();
        $this->assertCount(0, $this->leads());
    }

    // ---------------------------------------------------------------- one workspace

    public function test_a_submit_lands_in_the_workspace_that_owns_the_link_and_nowhere_else(): void
    {
        $this->submit()->assertOk();

        $this->assertCount(1, $this->leads());
        $this->assertCount(0, $this->asTenant($this->rival['tenant'], fn () => Lead::query()->get()), 'the lead appeared in another workspace');
        $this->assertCount(0, TrackingEvent::withoutGlobalScopes()->where('tenant_id', $this->rival['tenant']->id)->get());
        $this->assertCount(0, VisitorSession::withoutGlobalScopes()->where('tenant_id', $this->rival['tenant']->id)->get());
    }

    public function test_one_workspaces_token_cannot_reach_another_workspaces_form(): void
    {
        $theirForm = $this->asTenant($this->rival['tenant'], fn () => LandingForm::create(['name' => 'نموذجهم', 'status' => 'published', 'headline' => 'سرّي', 'fields' => app(FormSchema::class)->normalize([['key' => 'mobile', 'type' => 'phone', 'label' => 'ج', 'required' => true, 'mapping' => 'lead.phone']])]));
        // A link of this workspace pointed (by a bug, or by hand in the database) at a form that is not its own.
        $this->asTenant($this->t['tenant'], fn () => $this->link->forceFill(['landing_form_id' => $theirForm->id])->saveQuietly());

        $this->getJson('/api/v1/public/landing/'.$this->link->token)->assertNotFound();
        $this->submit(['answers' => ['mobile' => '0551234567']])->assertNotFound();
        $this->assertCount(0, $this->asTenant($this->rival['tenant'], fn () => Lead::query()->get()));
    }
}
