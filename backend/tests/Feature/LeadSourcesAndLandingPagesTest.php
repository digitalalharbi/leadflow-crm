<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Automation\ConditionEvaluator;
use App\Domain\Sheets\GoogleTokens;
use App\Domain\Tracking\Forms\LandingSlugs;
use App\Domain\Tracking\WhatsappReference;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Jobs\MirrorLeadToSheet;
use App\Models\Integration;
use App\Models\LandingForm;
use App\Models\Lead;
use App\Models\LeadFormMapping;
use App\Models\Project;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Services\Leads\LeadIngestionService;
use App\Services\Whatsapp\InboundWhatsappProcessor;
use App\Support\SafeSvg;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A project's lead sources end in one pipeline, and a landing page is one of them: it publishes to a public address, frozen, and a
 * submission becomes a lead of that project straight away, with its credit, its tags and its events. Nothing crosses a company.
 */
class LeadSourcesAndLandingPagesTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->a = $this->provisionTenant('Alpha');
        $this->b = $this->provisionTenant('Beta');
        $this->project = $this->asTenant($this->a['tenant'], fn () => Project::create(['name' => 'رواق']));
    }

    private function fields(): array
    {
        return [
            ['key' => 'name', 'type' => 'text', 'label' => 'الاسم', 'required' => true, 'mapping' => 'lead.full_name'],
            ['key' => 'mobile', 'type' => 'phone', 'label' => 'الجوال', 'required' => true, 'mapping' => 'lead.phone'],
        ];
    }

    private function page(array $page = [], array $extra = []): array
    {
        $body = $this->actingAs($this->a['admin'])->postJson('/api/v1/tracking/forms', $extra + [
            'name' => 'رواق الرئيسية', 'project_id' => $this->project->uuid, 'headline' => 'بيتك في رواق', 'fields' => $this->fields(), 'consent_required' => false,
            'page' => $page + ['template' => 'real_estate_lead'],
        ])->assertCreated()->json('data.form');

        return $body;
    }

    private function published(array $page = [], array $extra = []): array
    {
        $form = $this->page($page, $extra);

        return $this->actingAs($this->a['admin'])->postJson("/api/v1/tracking/forms/{$form['id']}/publish")->assertOk()->json('data.form');
    }

    private function public(string $address)
    {
        auth()->forgetGuards();
        $this->flushHeaders();

        return $this->getJson("/api/v1/public/landing/{$address}");
    }

    private function submit(string $address, array $body = [])
    {
        auth()->forgetGuards();
        $this->flushHeaders();

        return $this->postJson("/api/v1/public/landing/{$address}/submit", $body + [
            'submission_id' => (string) str()->uuid(), 'answers' => ['name' => 'سارة', 'mobile' => '0551234567'],
            'visitor_id' => 'v_'.str_repeat('a', 20), 'session_id' => 's_'.str_repeat('b', 20),
            'utm_source' => 'snapchat', 'utm_campaign' => 'october',
        ]);
    }

    // ------------------------------------------------------------- addresses

    public function test_a_slug_comes_from_any_name_and_refuses_what_it_must(): void
    {
        $this->assertSame('rwaq', LandingSlugs::fromName('رواق'));
        $this->assertSame('riwaq-invest', LandingSlugs::fromName('Riwaq Invest!'));
        $this->assertNull(LandingSlugs::fromName('!!'));

        $slugs = app(LandingSlugs::class);
        $this->assertSame('invalid', $slugs->problem('Has Space'));
        $this->assertSame('invalid', $slugs->problem('-bad-'));
        $this->assertSame('reserved', $slugs->problem('admin'));
        $this->assertNull($slugs->problem('riwaq'));
    }

    public function test_one_address_space_across_companies_and_never_a_live_token(): void
    {
        $this->published([], ['slug' => 'riwaq']);

        $other = $this->asTenant($this->b['tenant'], fn () => Project::create(['name' => 'B']));
        $this->actingAs($this->b['admin'])->postJson('/api/v1/tracking/forms', ['name' => 'B', 'project_id' => $other->uuid, 'fields' => $this->fields(), 'slug' => 'riwaq', 'page' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');

        $this->asTenant($this->a['tenant'], fn () => TrackingLink::create(['token' => 'printed-qr', 'name' => 'x', 'channel' => 'qr', 'mode' => 'landing']));
        $this->assertSame('taken', app(LandingSlugs::class)->problem('printed-qr'));
    }

    public function test_publishing_gives_the_page_an_address_and_a_link_and_it_opens_by_both(): void
    {
        $form = $this->published();

        $this->assertSame('/l/'.$form['slug'], $form['public_path']);
        $page = $this->public($form['slug'])->assertOk()->json('data');
        $this->assertSame('بيتك في رواق', $page['landing']['headline']);
        $this->assertSame('real_estate_lead', $page['landing']['page']['template']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $page['tracking']['ref']);

        // The token under it still works: an address already printed never stops.
        $this->public($page['landing']['token'])->assertOk();
        $this->assertSame(2, $this->asTenant($this->a['tenant'], fn () => TrackingEvent::where('event_type', 'visit')->count()));
    }

    public function test_the_public_page_never_shows_the_mirror_or_a_database_id(): void
    {
        $form = $this->published(['tags' => ['سري-داخلي'], 'mirror' => ['enabled' => true, 'spreadsheet_id' => str_repeat('s', 30)]]);
        $json = $this->public($form['slug'])->assertOk()->getContent();

        $this->assertStringNotContainsString(str_repeat('s', 30), $json);
        $this->assertStringNotContainsString('سري-داخلي', $json);
        $this->assertStringNotContainsString('"tenant_id"', $json);
        $this->assertStringNotContainsString('"project_id":'.$this->project->id, $json);
    }

    public function test_draft_paused_and_archived_are_not_public_and_a_draft_edit_never_reaches_the_live_page(): void
    {
        $draft = $this->page([], ['slug' => 'still-draft']);
        $this->public('still-draft')->assertNotFound();

        $live = $this->actingAs($this->a['admin'])->postJson("/api/v1/tracking/forms/{$draft['id']}/publish")->assertOk()->json('data.form');
        $this->public($live['slug'])->assertOk();

        $this->actingAs($this->a['admin'])->patchJson("/api/v1/tracking/forms/{$live['id']}", ['headline' => 'مسودة جديدة'])->assertOk()->assertJsonPath('data.form.has_unpublished_changes', true);
        $this->assertSame('بيتك في رواق', $this->public($live['slug'])->json('data.landing.headline'));

        $v2 = $this->actingAs($this->a['admin'])->postJson("/api/v1/tracking/forms/{$live['id']}/publish")->assertOk()->json('data.form');
        $this->assertSame(2, $v2['published_version']['version']);
        $this->assertSame('مسودة جديدة', $this->public($live['slug'])->json('data.landing.headline'));

        foreach (['paused', 'archived'] as $status) {
            $this->actingAs($this->a['admin'])->patchJson("/api/v1/tracking/forms/{$live['id']}", ['status' => $status])->assertOk();
            $this->public($live['slug'])->assertNotFound();
            $this->submit($live['slug'])->assertNotFound();
        }

        // Paused is reversible, and the page comes back as it was.
        $this->actingAs($this->a['admin'])->patchJson("/api/v1/tracking/forms/{$live['id']}", ['status' => 'published'])->assertOk();
        $this->public($live['slug'])->assertOk();
    }

    public function test_a_duplicate_has_no_address_of_its_own(): void
    {
        $form = $this->published();
        $copy = $this->actingAs($this->a['admin'])->postJson("/api/v1/tracking/forms/{$form['id']}/duplicate")->assertCreated()->json('data.form');

        $this->assertNull($copy['slug']);
        $this->assertSame('draft', $copy['status']);
    }

    public function test_a_page_cannot_be_published_without_a_project_or_a_way_to_act(): void
    {
        $noProject = $this->actingAs($this->a['admin'])->postJson('/api/v1/tracking/forms', ['name' => 'x', 'fields' => $this->fields(), 'page' => []])->assertCreated()->json('data.form');
        $this->actingAs($this->a['admin'])->postJson("/api/v1/tracking/forms/{$noProject['id']}/publish")->assertUnprocessable();

        $noNumber = $this->page(['cta' => ['whatsapp' => ['enabled' => true], 'primary' => 'whatsapp']]);
        $this->actingAs($this->a['admin'])->postJson("/api/v1/tracking/forms/{$noNumber['id']}/publish")->assertUnprocessable();
    }

    public function test_a_long_form_becomes_steps_by_itself_and_a_short_one_does_not(): void
    {
        $short = $this->published();
        $this->assertSame(1, $this->public($short['slug'])->json('data.landing.steps'));

        $fields = $this->fields();
        foreach (['a', 'b', 'c', 'd', 'e'] as $k) {
            $fields[] = ['key' => "q{$k}", 'type' => 'text', 'label' => $k, 'mapping' => "custom.q{$k}"];
        }

        $long = $this->published([], ['name' => 'طويل', 'slug' => 'long-form', 'fields' => $fields]);
        $landing = $this->public('long-form')->json('data.landing');
        $this->assertGreaterThan(1, $landing['steps']);
        $this->assertSame('multi', $landing['settings']['mode']);
        $this->assertSame('long-form', $long['slug']);

        // A manager can keep it on one screen.
        $flat = $this->published(['auto_steps' => false], ['name' => 'مسطح', 'slug' => 'flat-form', 'fields' => $fields]);
        $this->assertSame(1, $this->public($flat['slug'])->json('data.landing.steps'));
    }

    // ------------------------------------------------------------ submission

    public function test_a_submission_is_a_lead_of_the_project_at_once_with_its_credit_tags_and_events(): void
    {
        $form = $this->published(['tags' => ['riwaq', 'snapchat']]);
        $this->public($form['slug']);

        $this->submit($form['slug'])->assertOk();

        $lead = $this->asTenant($this->a['tenant'], fn () => Lead::firstOrFail());
        $this->assertSame($this->project->id, $lead->project_id);
        $this->assertSame(LandingForm::withoutGlobalScopes()->where('uuid', $form['id'])->value('id'), $lead->landing_form_id);
        $this->assertSame(['riwaq', 'snapchat'], $lead->tags);
        $this->assertSame('snapchat', $lead->utm_source);
        $this->assertSame('october', $lead->utm_campaign);
        $this->assertSame('v_'.str_repeat('a', 20), $lead->visitor_id);

        $types = $this->asTenant($this->a['tenant'], fn () => TrackingEvent::pluck('event_type')->all());
        $this->assertContains('visit', $types);
        $this->assertContains('form_submit', $types);
    }

    public function test_the_same_submission_twice_a_returning_customer_and_a_bot_each_make_what_they_should(): void
    {
        $form = $this->published();
        $id = (string) str()->uuid();

        $this->submit($form['slug'], ['submission_id' => $id])->assertOk();
        $this->submit($form['slug'], ['submission_id' => $id])->assertOk()->assertJsonPath('data.duplicate', true);
        $this->submit($form['slug'])->assertOk();
        $this->assertSame(1, $this->asTenant($this->a['tenant'], fn () => Lead::count()), 'Same number: the same person.');

        $this->submit($form['slug'], ['website' => 'spam', 'answers' => ['name' => 'bot', 'mobile' => '0559999999']])->assertOk();
        $this->assertSame(1, $this->asTenant($this->a['tenant'], fn () => Lead::count()), 'The honeypot makes nothing.');

        $this->submit($form['slug'], ['answers' => ['name' => '', 'mobile' => '']])->assertUnprocessable();
    }

    public function test_a_lead_keeps_the_first_page_that_brought_it(): void
    {
        $first = $this->published([], ['slug' => 'page-one']);
        $second = $this->published([], ['name' => 'صفحة ثانية', 'slug' => 'page-two']);

        $this->submit('page-one')->assertOk();
        $this->submit('page-two')->assertOk();

        $this->assertSame(LandingForm::withoutGlobalScopes()->where('uuid', $first['id'])->value('id'), $this->asTenant($this->a['tenant'], fn () => Lead::firstOrFail())->landing_form_id);
        $this->assertNotSame($first['id'], $second['id']);
    }

    public function test_a_mirror_that_fails_never_costs_the_lead(): void
    {
        $form = $this->published(['mirror' => ['enabled' => true, 'spreadsheet_id' => str_repeat('s', 30), 'tab' => 'Leads']]);
        Queue::fake([MirrorLeadToSheet::class]);

        $this->submit($form['slug'])->assertOk();
        $this->assertSame(1, $this->asTenant($this->a['tenant'], fn () => Lead::count()));
        Queue::assertPushed(MirrorLeadToSheet::class);

        // Run for real against a Google that refuses: nothing throws, and no customer data reaches the log.
        Queue::fake([]);
        Http::fake(['*' => Http::response(['error' => 'nope'], 403)]);
        $lead = $this->asTenant($this->a['tenant'], fn () => Lead::firstOrFail());
        (new MirrorLeadToSheet($lead->id, $this->a['tenant']->id, str_repeat('s', 30), 'Leads'))->handle(app(TenantContext::class), app(GoogleTokens::class));
        $this->assertNotNull($lead->fresh());
    }

    public function test_the_mirror_writes_raw_and_neutralised_text(): void
    {
        $lead = $this->asTenant($this->a['tenant'], function () {
            Integration::create(['provider' => IntegrationProvider::GoogleSheets, 'name' => 'g', 'status' => 'connected', 'is_active' => true, 'credentials' => ['access_token' => 'tok', 'expires_at' => now()->addHour()->toIso8601String()]]);

            return Lead::create(['full_name' => '=IMPORTRANGE("evil")', 'phone' => '0551112222', 'phone_normalized' => '+966551112222']);
        });
        Http::fake(['sheets.googleapis.com/*' => Http::response(['updates' => []], 200), '*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200)]);

        (new MirrorLeadToSheet($lead->id, $this->a['tenant']->id, str_repeat('s', 30), 'Leads'))->handle(app(TenantContext::class), app(GoogleTokens::class));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'valueInputOption=RAW') && $request['values'][0][1] === "'=IMPORTRANGE(\"evil\")";
        });
    }

    // ---------------------------------------------------- whatsapp and calls

    public function test_a_tap_is_recorded_with_its_reference_and_the_first_message_makes_the_lead_with_credit(): void
    {
        $form = $this->published(['cta' => ['whatsapp' => ['enabled' => true, 'number' => '+966500000000', 'mode' => 'inbound'], 'primary' => 'form', 'secondary' => 'whatsapp']]);
        $data = $this->public($form['slug'])->json('data');
        $token = $data['landing']['token'];
        $ref = $data['tracking']['ref'];

        $this->postJson("/api/v1/track/{$token}", ['event' => 'whatsapp_click', 'visitor_id' => $data['tracking']['visitor_id'], 'session_id' => $data['tracking']['session_id'], 'utm_source' => 'tiktok', 'utm_campaign' => 'autumn', 'meta' => ['ref' => $ref]])->assertNoContent();
        $this->postJson("/api/v1/track/{$token}", ['event' => 'call_click', 'visitor_id' => $data['tracking']['visitor_id'], 'session_id' => $data['tracking']['session_id']])->assertNoContent();

        $types = $this->asTenant($this->a['tenant'], fn () => TrackingEvent::pluck('event_type')->all());
        $this->assertContains('whatsapp_click', $types);
        $this->assertContains('call_click', $types);
        $this->assertSame(0, $this->asTenant($this->a['tenant'], fn () => Lead::count()), 'A tap alone knows no number.');

        $integration = $this->asTenant($this->a['tenant'], fn () => Integration::create(['provider' => IntegrationProvider::Whatsapp, 'name' => 'w', 'status' => 'connected', 'is_active' => true]));
        $this->asTenant($this->a['tenant'], fn () => app(InboundWhatsappProcessor::class)->process($integration, ['entry' => [['changes' => [['value' => ['messages' => [['id' => 'wamid.1', 'from' => '966551234567', 'type' => 'text', 'text' => ['body' => "مرحبًا، أنا مهتم بمشروع رواق — Ref: {$ref}"]]]]]]]]]));

        $lead = $this->asTenant($this->a['tenant'], fn () => Lead::firstOrFail());
        $this->assertSame($this->project->id, $lead->project_id);
        $this->assertNotNull($lead->landing_form_id);
        $this->assertSame('tiktok', $lead->utm_source);
    }

    public function test_a_stranger_without_a_real_reference_stays_a_stranger_and_track_mode_makes_no_lead(): void
    {
        $this->assertNull(WhatsappReference::in('hello'));
        $form = $this->published(['cta' => ['whatsapp' => ['enabled' => true, 'number' => '+966500000000', 'mode' => 'track'], 'primary' => 'form']]);
        $data = $this->public($form['slug'])->json('data');
        $this->postJson("/api/v1/track/{$data['landing']['token']}", ['event' => 'whatsapp_click', 'visitor_id' => $data['tracking']['visitor_id'], 'meta' => ['ref' => $data['tracking']['ref']]])->assertNoContent();

        $integration = $this->asTenant($this->a['tenant'], fn () => Integration::create(['provider' => IntegrationProvider::Whatsapp, 'name' => 'w', 'status' => 'connected', 'is_active' => true]));
        foreach (['wamid.a' => 'Ref: ZZZZZZ', 'wamid.b' => "Ref: {$data['tracking']['ref']}"] as $id => $body) {
            $this->asTenant($this->a['tenant'], fn () => app(InboundWhatsappProcessor::class)->process($integration, ['entry' => [['changes' => [['value' => ['messages' => [['id' => $id, 'from' => '966551234567', 'type' => 'text', 'text' => ['body' => $body]]]]]]]]]));
        }

        $this->assertSame(0, $this->asTenant($this->a['tenant'], fn () => Lead::count()));
    }

    // --------------------------------------------------------- lead sources

    public function test_the_sources_list_brings_every_kind_together_with_its_numbers_and_stays_in_its_project(): void
    {
        $form = $this->published();
        $this->submit($form['slug'])->assertOk();

        $this->actingAs($this->a['admin'])->postJson("/api/v1/projects/{$this->project->uuid}/lead-sources/mappings", ['provider' => 'meta', 'form_ref' => '111', 'form_name' => 'Meta form'])->assertCreated();

        $data = $this->actingAs($this->a['admin'])->getJson("/api/v1/projects/{$this->project->uuid}/lead-sources")->assertOk()->json('data');
        $types = array_column($data['sources'], 'type');

        foreach (['landing_page', 'platform_form', 'whatsapp', 'call', 'api', 'import'] as $type) {
            $this->assertContains($type, $types);
        }

        $page = collect($data['sources'])->firstWhere('type', 'landing_page');
        $this->assertSame(1, $page['leads']);
        $this->assertSame('connected', $page['status']);
        $this->assertNotNull($page['last_lead_at']);
        $this->assertSame('awaiting_setup', collect($data['sources'])->firstWhere('type', 'platform_form')['status']);

        $this->actingAs($this->b['admin'])->getJson("/api/v1/projects/{$this->project->uuid}/lead-sources")->assertNotFound();
        $this->actingAs($this->b['admin'])->getJson("/api/v1/projects/{$this->project->uuid}/lead-sources/compare")->assertNotFound();
    }

    public function test_a_mapped_platform_form_lands_in_its_project_with_its_answers_in_the_one_pipeline(): void
    {
        $this->actingAs($this->a['admin'])->postJson("/api/v1/projects/{$this->project->uuid}/lead-sources/mappings", [
            'provider' => 'meta', 'form_ref' => '777', 'form_name' => 'Riwaq', 'field_mapping' => ['Budget Range' => 'custom:budget', 'city_name' => 'city'],
        ])->assertCreated();

        $result = app(LeadIngestionService::class)->ingest($this->a['tenant'], IncomingLead::fromArray(IntegrationProvider::Meta, ['full_name' => 'نورة', 'phone' => '0557778888', 'form_id' => '777'], [
            'field_data' => [['name' => 'budget range', 'values' => ['1M']], ['name' => 'city_name', 'values' => ['جدة']]],
        ]));

        $lead = $result->lead;
        $this->assertSame($this->project->id, $lead->project_id);
        $this->assertSame('جدة', $lead->city);

        // Another company's form with the same id is untouched.
        $other = app(LeadIngestionService::class)->ingest($this->b['tenant'], IncomingLead::fromArray(IntegrationProvider::Meta, ['full_name' => 'س', 'phone' => '0557778899', 'form_id' => '777']));
        $this->assertNull($other->lead->project_id);
    }

    public function test_a_form_feeds_one_project_and_a_mapping_cannot_write_anywhere_it_likes(): void
    {
        $url = "/api/v1/projects/{$this->project->uuid}/lead-sources/mappings";
        $this->actingAs($this->a['admin'])->postJson($url, ['provider' => 'meta', 'form_ref' => '1'])->assertCreated();
        $this->actingAs($this->a['admin'])->postJson($url, ['provider' => 'meta', 'form_ref' => '1'])->assertUnprocessable();
        $this->actingAs($this->a['admin'])->postJson($url, ['provider' => 'meta', 'form_ref' => '2', 'field_mapping' => ['x' => 'tenant_id']])->assertUnprocessable();
        $this->actingAs($this->a['admin'])->postJson($url, ['provider' => 'nope', 'form_ref' => '3'])->assertUnprocessable();
    }

    public function test_pages_compare_side_by_side(): void
    {
        $one = $this->published([], ['slug' => 'compare-a']);
        $this->published([], ['name' => 'B', 'slug' => 'compare-b']);
        $this->public('compare-a');
        $this->public('compare-a');
        $this->submit('compare-a')->assertOk();

        $pages = $this->actingAs($this->a['admin'])->getJson("/api/v1/projects/{$this->project->uuid}/lead-sources/compare")->assertOk()->json('data.pages');
        $this->assertSame($one['id'], $pages[0]['id']);
        $this->assertSame(1, $pages[0]['leads']);
        $this->assertSame(2, $pages[0]['views']);
        $this->assertEquals(50.0, $pages[0]['conversion_rate']);
    }

    public function test_page_analytics_read_the_funnel_and_leave_cost_empty_without_spend(): void
    {
        $form = $this->published();
        $data = $this->public($form['slug'])->json('data');
        $this->submit($form['slug'])->assertOk();
        $this->postJson("/api/v1/track/{$data['landing']['token']}", ['event' => 'call_click', 'visitor_id' => $data['tracking']['visitor_id']])->assertNoContent();

        $page = $this->actingAs($this->a['admin'])->getJson("/api/v1/tracking/forms/{$form['id']}/analytics")->assertOk()->json('data.page');
        $this->assertSame(1, $page['leads']);
        $this->assertSame(1, $page['call_clicks']);
        $this->assertSame(['view', 'form_start', 'lead', 'qualified', 'appointment', 'visit', 'reservation', 'sale'], array_column($page['funnel'], 'key'));
        $this->assertNull($page['cost']['cpl']);
    }

    // ------------------------------------------------------------ permissions

    public function test_who_may_touch_a_page(): void
    {
        $form = $this->published();
        $sales = $this->makeUser($this->a['tenant'], Role::Sales);
        $marketing = $this->makeUser($this->a['tenant'], Role::MarketingManager);
        $pm = $this->makeUser($this->a['tenant'], Role::ProjectManager);
        $otherProject = $this->asTenant($this->a['tenant'], fn () => Project::create(['name' => 'مشروع آخر']));
        $pm->projects()->attach($otherProject->id);

        $this->actingAs($sales)->patchJson("/api/v1/tracking/forms/{$form['id']}", ['headline' => 'x'])->assertForbidden();
        $this->actingAs($sales)->postJson('/api/v1/tracking/forms', ['name' => 'x', 'fields' => $this->fields()])->assertForbidden();
        $this->actingAs($sales)->getJson("/api/v1/projects/{$this->project->uuid}/lead-sources")->assertForbidden();

        $this->actingAs($marketing)->patchJson("/api/v1/tracking/forms/{$form['id']}", ['headline' => 'من التسويق'])->assertOk();

        // A project manager: their own project's pages, and no other's.
        $this->actingAs($pm->fresh())->patchJson("/api/v1/tracking/forms/{$form['id']}", ['headline' => 'x'])->assertNotFound();
        $this->actingAs($pm->fresh())->getJson("/api/v1/projects/{$this->project->uuid}/lead-sources")->assertNotFound();
        $this->actingAs($pm->fresh())->postJson('/api/v1/tracking/forms', ['name' => 'x', 'fields' => $this->fields()])->assertUnprocessable();
        $this->actingAs($pm->fresh())->postJson('/api/v1/tracking/forms', ['name' => 'صفحتي', 'project_id' => $otherProject->uuid, 'fields' => $this->fields(), 'page' => []])->assertCreated();

        // Another company.
        $this->actingAs($this->b['admin'])->getJson("/api/v1/tracking/forms/{$form['id']}")->assertNotFound();
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/tracking/forms/{$form['id']}", ['headline' => 'x'])->assertNotFound();
    }

    // ------------------------------------------------------------ conversions

    public function test_a_destination_can_say_what_an_event_becomes_only_from_a_closed_list(): void
    {
        $url = '/api/v1/tracking/destinations';
        $id = $this->actingAs($this->a['admin'])->postJson($url, ['name' => 'Meta', 'kind' => 'meta', 'config' => ['pixel_id' => '123456789012345'], 'event_map' => ['form_submitted' => 'Purchase']])->assertCreated()->json('data.destination.id');
        $this->assertNotNull($id);
        $this->assertSame(['form_submitted' => 'Purchase'], DB::table('tracking_destinations')->orderByDesc('id')->value('event_map') ? json_decode(DB::table('tracking_destinations')->orderByDesc('id')->value('event_map'), true) : null);

        $this->actingAs($this->a['admin'])->postJson($url, ['name' => 'Meta2', 'kind' => 'meta', 'config' => ['pixel_id' => '123456789012345'], 'event_map' => ['form_submitted' => '<script>']])->assertUnprocessable();
    }

    // ---------------------------------------------------------------- rules

    public function test_a_rule_can_read_the_page_and_the_campaign_and_hear_the_page_events(): void
    {
        $form = $this->published([], ['slug' => 'rule-page']);
        $this->submit('rule-page')->assertOk();
        $lead = $this->asTenant($this->a['tenant'], fn () => Lead::firstOrFail());
        $evaluator = app(ConditionEvaluator::class);

        $this->asTenant($this->a['tenant'], function () use ($evaluator, $lead) {
            $this->assertSame('rule-page', $evaluator->read($lead, 'landing_page'));
            $this->assertSame('landing_form', $evaluator->read($lead, 'lead_source'));
            $this->assertSame('october', $evaluator->read($lead, 'utm_campaign'));
        });

        $this->actingAs($this->a['admin'])->postJson('/api/v1/automation/rules', ['name' => 'r', 'trigger' => 'lead.whatsapp_click', 'conditions' => [['field' => 'landing_page', 'operator' => 'equals', 'value' => 'rule-page']], 'actions' => [['type' => 'add_tag', 'tag' => 'wa']]])->assertCreated();
        $this->assertSame($form['id'], LandingForm::withoutGlobalScopes()->whereKey($lead->landing_form_id)->value('uuid'));
    }

    // ------------------------------------------------------------------ files

    public function test_a_logo_may_be_vector_only_when_it_is_only_a_drawing(): void
    {
        $this->assertTrue(SafeSvg::isSafe('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#0a7"/></svg>'));

        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
            '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="https://evil/x.png"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="https://evil/x.svg#a"/></svg>',
            '<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://evil);</style></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="url(https://evil#a)"/></svg>',
        ] as $bad) {
            $this->assertFalse(SafeSvg::isSafe($bad), $bad);
        }

        $good = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle r="4"/></svg>');
        $url = $this->actingAs($this->a['admin'])->post('/api/v1/tracking/forms/assets', ['file' => $good], ['Accept' => 'application/json'])->assertCreated()->json('data.url');
        $this->assertStringEndsWith('.svg', $url);

        $served = $this->get($url)->assertOk();
        $this->assertStringContainsString('sandbox', $served->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $served->headers->get('X-Content-Type-Options'));

        $evil = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"/>');
        $this->actingAs($this->a['admin'])->post('/api/v1/tracking/forms/assets', ['file' => $evil], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->actingAs($this->a['admin'])->patchJson("/api/v1/projects/{$this->project->uuid}/lead-sources/logo", ['url' => $url])->assertOk()->assertJsonPath('data.logo_url', $url);
        $this->actingAs($this->a['admin'])->patchJson("/api/v1/projects/{$this->project->uuid}/lead-sources/logo", ['url' => 'https://evil/x.png'])->assertUnprocessable();
        $this->actingAs($this->b['admin'])->patchJson("/api/v1/projects/{$this->project->uuid}/lead-sources/logo", ['url' => $url])->assertNotFound();
    }

    public function test_a_campaign_link_or_qr_keeps_its_own_source_on_the_same_page(): void
    {
        $form = $this->published();
        $link = $this->actingAs($this->a['admin'])->postJson("/api/v1/tracking/forms/{$form['id']}/links", ['name' => 'لوحة المعرض', 'channel' => 'qr', 'kind' => 'qr', 'placement' => 'booth', 'utm_source' => 'booth'])->assertCreated()->json('data.link');

        $this->assertSame('qr', $link['kind']);
        $page = $this->public($link['token'])->assertOk()->json('data.landing');
        $this->assertSame('بيتك في رواق', $page['headline']);

        $this->submit($link['token'], ['utm_source' => null, 'utm_campaign' => null])->assertOk();
        $this->assertSame('booth', $this->asTenant($this->a['tenant'], fn () => Lead::firstOrFail())->utm_source);

        // Only for a published page, and never another company's.
        $draft = $this->page([], ['name' => 'مسودة', 'slug' => 'a-draft']);
        $this->actingAs($this->a['admin'])->postJson("/api/v1/tracking/forms/{$draft['id']}/links", ['name' => 'x', 'channel' => 'qr'])->assertStatus(422);
        $this->actingAs($this->b['admin'])->postJson("/api/v1/tracking/forms/{$form['id']}/links", ['name' => 'x', 'channel' => 'qr'])->assertNotFound();
        $this->assertSame(LeadFormMapping::count(), 0);
    }
}
