<?php

namespace Tests\Feature;

use App\Domain\Tracking\Forms\FormSchema;
use App\Domain\Tracking\Forms\FormTemplates;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\LandingForm;
use App\Models\LandingFormVersion;
use App\Models\Project;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The advanced form as a managed thing: sections and a design that are checked, a draft that does not touch the live page until it is
 * published, every publish a version a submission remembers, a form that can be copied or started from a template — and all of it one
 * workspace's own.
 */
class FormVersioningApiTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $rival;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Versions Co');
        $this->rival = $this->provisionTenant('Rival Versions Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function fields(): array
    {
        return [
            ['key' => 'name', 'type' => 'text', 'label' => 'الاسم', 'required' => true, 'mapping' => 'lead.full_name'],
            ['key' => 'mobile', 'type' => 'phone', 'label' => 'الجوال', 'required' => true, 'mapping' => 'lead.phone'],
            ['key' => 'city', 'type' => 'text', 'label' => 'المدينة', 'mapping' => 'lead.city'],
        ];
    }

    private function payload(array $o = []): array
    {
        return $o + [
            'name' => 'نموذج النرجس', 'project_id' => $this->project->uuid, 'headline' => 'ابدأ امتلاك منزلك', 'fields' => $this->fields(),
            'layout' => ['sections' => [['title' => 'بياناتك', 'rows' => [
                ['columns' => [['fields' => ['name']], ['fields' => ['mobile']]]],
                ['columns' => [['fields' => ['city']]]],
            ]]]],
            'design' => ['header' => ['layout' => 'centered', 'logo_size' => 'md', 'logo_align' => 'center', 'background' => 'soft'], 'style' => ['surface' => 'card', 'radius' => 'lg', 'input_size' => 'large', 'button' => 'full', 'theme' => 'brand', 'primary' => 'emerald', 'accent' => 'amber']],
            'settings' => ['mode' => 'single', 'default_country' => 'SA'],
        ];
    }

    private function make(array $o = []): string
    {
        return $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $this->payload($o))->assertCreated()->json('data.form.id');
    }

    private function publish(string $id)
    {
        return $this->actingAs($this->t['admin'])->postJson("/api/v1/tracking/forms/{$id}/publish");
    }

    private function link(string $formId): TrackingLink
    {
        $form = $this->asTenant($this->t['tenant'], fn () => LandingForm::where('uuid', $formId)->firstOrFail());

        return $this->asTenant($this->t['tenant'], fn () => TrackingLink::create([
            'token' => 'v'.random_int(10000000, 99999999), 'name' => 'رابط', 'channel' => 'meta', 'mode' => 'landing', 'landing_form_id' => $form->id, 'project_id' => $this->project->id,
        ]));
    }

    private function page(TrackingLink $link)
    {
        return $this->getJson('/api/v1/public/landing/'.$link->token);
    }

    // ---------------------------------------------------------------- structure and design

    public function test_a_form_keeps_its_layout_design_and_settings_tidied(): void
    {
        $form = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $this->payload())->assertCreated()->json('data.form');

        $this->assertCount(1, $form['layout']['sections']);
        $this->assertSame([['name'], ['mobile']], array_map(fn ($c) => $c['fields'], $form['layout']['sections'][0]['rows'][0]['columns']));
        $this->assertSame('centered', $form['design']['header']['layout']);
        $this->assertSame('large', $form['design']['style']['input_size']);
        $this->assertSame('SA', $form['settings']['default_country']);
        $this->assertSame('draft', $form['status']);
        $this->assertNull($form['published_version']);
    }

    public function test_a_design_is_only_ever_made_of_the_safe_choices(): void
    {
        $bad = [
            'an unknown header layout' => ['header' => ['layout' => 'fireworks']],
            'a colour that is not a token' => ['style' => ['primary' => '#ff0000']],
            'a css string' => ['style' => ['radius' => 'lg; background:url(//evil)']],
            'a script as a colour' => ['style' => ['accent' => '<script>']],
            'a logo address that is not https' => ['header' => ['logo_size' => 'md'], 'logo_url' => 'javascript:alert(1)'],
        ];

        foreach ($bad as $name => $design) {
            $logo = $design['logo_url'] ?? null;
            unset($design['logo_url']);
            $response = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $this->payload(['design' => $design] + ($logo ? ['logo_url' => $logo] : [])));
            $response->assertStatus(422);
            $this->assertNotEmpty($response->json('errors'), $name);
        }
    }

    public function test_html_in_the_texts_is_not_kept(): void
    {
        $form = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $this->payload([
            'headline' => 'عنوان <img src=x onerror=alert(1)>',
            'design' => ['header' => ['layout' => 'minimal', 'trust_note' => '<script>alert(1)</script>ثقة', 'location' => '<b>الرياض</b>']],
        ]))->assertCreated()->json('data.form');

        $this->assertStringNotContainsString('<', json_encode($form, JSON_UNESCAPED_UNICODE));
        $this->assertSame('الرياض', $form['design']['header']['location']);
    }

    public function test_the_settings_are_checked_too(): void
    {
        $cases = [
            'a country that does not exist' => ['default_country' => 'ZZ'],
            'a mode that does not exist' => ['mode' => 'carousel'],
            'a thank-you action that does not exist' => ['thanks' => ['actions' => [['type' => 'rm_rf', 'label' => 'x', 'value' => 'x']]]],
            'a whatsapp action with letters for a number' => ['thanks' => ['actions' => [['type' => 'whatsapp', 'label' => 'x', 'value' => 'hello']]]],
            'a brochure that is not an https address' => ['thanks' => ['actions' => [['type' => 'brochure', 'label' => 'x', 'value' => 'ftp://x']]]],
            'a redirect that is not https' => ['thanks' => ['redirect_url' => 'javascript:1']],
            'a signal with no conditions' => ['signals' => [['id' => 'a', 'label' => 'x', 'points' => 10, 'when' => ['match' => 'all', 'conditions' => []]]]],
            'a signal worth too much' => ['signals' => [['id' => 'a', 'label' => 'x', 'points' => 500, 'when' => ['match' => 'all', 'conditions' => [['field' => 'city', 'op' => 'not_empty']]]]]],
        ];

        foreach ($cases as $name => $settings) {
            $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $this->payload(['settings' => $settings]))->assertStatus(422, $name);
        }

        $ok = $this->payload(['settings' => ['mode' => 'multi', 'default_country' => 'AE', 'thanks' => ['actions' => [['type' => 'whatsapp', 'label' => 'تواصل عبر واتساب', 'value' => '+966551234567'], ['type' => 'brochure', 'label' => 'حمّل الكتيّب', 'value' => 'https://example.sa/b.pdf']], 'redirect_url' => 'https://example.sa/thanks', 'redirect_delay' => 5], 'gtm' => ['events' => true]]]);
        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $ok)->assertCreated();
    }

    // ---------------------------------------------------------------- draft and publish

    public function test_a_form_that_was_never_published_is_not_a_page(): void
    {
        $id = $this->make();

        $this->page($this->link($id))->assertNotFound();
    }

    public function test_publishing_makes_a_version_and_the_page_serves_it(): void
    {
        $id = $this->make();
        $link = $this->link($id);

        $published = $this->publish($id)->assertOk()->json('data.form');
        $this->assertSame('published', $published['status']);
        $this->assertSame(1, $published['published_version']['version']);
        $this->assertFalse($published['has_unpublished_changes']);

        $page = $this->page($link)->assertOk()->json('data.landing');
        $this->assertSame('ابدأ امتلاك منزلك', $page['headline']);
        $this->assertSame('centered', $page['design']['header']['layout']);
        $this->assertSame(1, $page['version']);
        $this->assertNotNull(AuditLog::where('action', 'landing_form.published')->first());
    }

    public function test_editing_a_published_form_does_not_touch_the_live_page_until_it_is_published_again(): void
    {
        $id = $this->make();
        $link = $this->link($id);
        $this->publish($id)->assertOk();

        $edited = $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/forms/{$id}", ['headline' => 'عنوان جديد كليًا'])->assertOk()->json('data.form');
        $this->assertTrue($edited['has_unpublished_changes'], 'the draft knows it differs from what is live');
        $this->assertSame('published', $edited['status']);

        $this->assertSame('ابدأ امتلاك منزلك', $this->page($link)->json('data.landing.headline'), 'campaigns already running keep the page they were sent to');

        $second = $this->publish($id)->assertOk()->json('data.form');
        $this->assertSame(2, $second['published_version']['version']);
        $this->assertSame('عنوان جديد كليًا', $this->page($link)->json('data.landing.headline'));
    }

    public function test_publishing_again_with_nothing_changed_makes_no_new_version(): void
    {
        $id = $this->make();
        $this->publish($id)->assertOk();
        $this->publish($id)->assertOk()->assertJsonPath('data.form.published_version.version', 1);

        $this->assertSame(1, LandingFormVersion::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->count());
    }

    public function test_a_form_that_does_not_work_cannot_be_published(): void
    {
        $id = $this->make();
        $this->asTenant($this->t['tenant'], fn () => LandingForm::where('uuid', $id)->update(['fields' => [['key' => 'name', 'type' => 'text', 'label' => 'الاسم', 'mapping' => 'lead.full_name']]]));

        $this->publish($id)->assertStatus(422);
        $this->assertNull(LandingForm::withoutGlobalScopes()->where('uuid', $id)->first()->published_version_id);
    }

    public function test_the_versions_are_listed_and_each_keeps_what_was_live(): void
    {
        $id = $this->make();
        $this->publish($id);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/forms/{$id}", ['headline' => 'النسخة الثانية']);
        $this->publish($id);

        $versions = $this->actingAs($this->t['admin'])->getJson("/api/v1/tracking/forms/{$id}/versions")->assertOk()->json('data.versions');

        $this->assertSame([2, 1], array_column($versions, 'version'), 'newest first');
        $this->assertSame('ابدأ امتلاك منزلك', LandingFormVersion::withoutGlobalScopes()->where('version', 1)->first()->snapshot['headline']);
    }

    public function test_an_old_published_form_without_a_version_gets_one_the_first_time_it_is_shown(): void
    {
        $form = $this->asTenant($this->t['tenant'], fn () => LandingForm::create([
            'project_id' => $this->project->id, 'name' => 'قديم', 'status' => 'published', 'headline' => 'قديم', 'fields' => app(FormSchema::class)->normalize($this->fields()),
        ]));
        $link = $this->link($form->uuid);

        $this->page($link)->assertOk()->assertJsonPath('data.landing.headline', 'قديم')->assertJsonPath('data.landing.version', 1);
        $this->assertSame(1, LandingFormVersion::withoutGlobalScopes()->where('landing_form_id', $form->id)->count());
    }

    public function test_an_archived_form_is_not_a_page_even_though_it_was_published(): void
    {
        $id = $this->make();
        $link = $this->link($id);
        $this->publish($id);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/forms/{$id}", ['status' => 'archived'])->assertOk();

        $this->page($link)->assertNotFound();
    }

    public function test_the_old_way_of_publishing_with_a_status_still_publishes_properly(): void
    {
        $id = $this->make();
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/forms/{$id}", ['status' => 'published'])->assertOk()->assertJsonPath('data.form.published_version.version', 1);
    }

    public function test_the_public_page_never_shows_where_an_answer_goes_or_how_interest_is_scored(): void
    {
        $id = $this->make(['settings' => ['mode' => 'single', 'signals' => [['id' => 'rich', 'label' => 'ميزانية عالية', 'points' => 12, 'when' => ['match' => 'all', 'conditions' => [['field' => 'city', 'op' => 'not_empty']]]]]]]);
        $link = $this->link($id);
        $this->publish($id);

        $json = $this->page($link)->assertOk()->getContent();

        $this->assertStringNotContainsString('lead.', $json);
        $this->assertStringNotContainsString('signals', $json, 'the scoring rules are the workspace\'s business');
        $this->assertStringNotContainsString('ميزانية عالية', $json);
    }

    // ---------------------------------------------------------------- copying and starting

    public function test_a_form_can_be_copied_and_the_copy_is_a_draft_nobody_links_to(): void
    {
        $id = $this->make();
        $this->publish($id);

        $copy = $this->actingAs($this->t['admin'])->postJson("/api/v1/tracking/forms/{$id}/duplicate")->assertCreated()->json('data.form');

        $this->assertNotSame($id, $copy['id']);
        $this->assertSame('draft', $copy['status']);
        $this->assertNull($copy['published_version']);
        $this->assertSame(0, $copy['links_count']);
        $this->assertStringContainsString('نسخة', $copy['name']);
        $this->assertCount(1, $copy['layout']['sections']);
    }

    public function test_every_template_is_a_form_that_works(): void
    {
        $schema = new FormSchema;
        $templates = FormTemplates::all();

        $this->assertEqualsCanonicalizing(['real_estate_interest', 'property_visit', 'reservation_interest', 'investment_qualification', 'broker_lead', 'general_contact'], array_keys($templates));

        foreach ($templates as $key => $t) {
            $this->assertSame([], $schema->definitionErrors($t['definition']['fields'], $t['definition']['layout']), "template {$key}");
        }
    }

    public function test_a_form_starts_from_a_template_and_anything_given_overrides_it(): void
    {
        $this->assertSame(['real_estate_interest', 'property_visit', 'reservation_interest', 'investment_qualification', 'broker_lead', 'general_contact'], array_column($this->actingAs($this->t['admin'])->getJson('/api/v1/tracking/forms/templates')->assertOk()->json('data.templates'), 'key'));

        $form = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', ['template' => 'investment_qualification', 'name' => 'تأهيل المستثمرين', 'project_id' => $this->project->uuid])->assertCreated()->json('data.form');

        $this->assertSame('تأهيل المستثمرين', $form['name']);
        $this->assertGreaterThan(3, count($form['fields']));
        $this->assertNotEmpty($form['layout']['sections']);
        $this->assertNotEmpty($form['settings']['signals'] ?? [], 'investment intent is scored from the answers');

        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', ['template' => 'nope', 'name' => 'x'])->assertStatus(422);
    }

    // ---------------------------------------------------------------- the logo

    public function test_a_logo_can_be_uploaded_and_served_without_a_session(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->image('logo.png', 400, 200);

        $url = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms/assets', ['file' => $file])->assertCreated()->json('data.url');

        $this->assertMatchesRegularExpression('#^/api/v1/public/form-assets/[a-f0-9]{32}\.png$#', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

        // And a form may use it.
        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $this->payload(['logo_url' => $url]))->assertCreated()->assertJsonPath('data.form.logo_url', $url);
    }

    public function test_only_a_small_real_image_is_accepted_as_a_logo(): void
    {
        Storage::fake('local');

        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms/assets', ['file' => UploadedFile::fake()->create('logo.php', 5, 'application/x-httpd-php')])->assertStatus(422);
        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms/assets', ['file' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')])->assertStatus(422);
        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms/assets', ['file' => UploadedFile::fake()->create('big.png', 4000, 'image/png')])->assertStatus(422);
        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms/assets', ['file' => UploadedFile::fake()->create('fake.png', 5, 'text/plain')])->assertStatus(422);
    }

    public function test_a_form_cannot_point_at_an_upload_that_is_not_there(): void
    {
        Storage::fake('local');

        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $this->payload(['logo_url' => '/api/v1/public/form-assets/'.str_repeat('a', 32).'.png']))->assertStatus(422);
    }

    // ---------------------------------------------------------------- whose it is

    public function test_another_workspace_cannot_publish_copy_list_or_read_the_versions_of_this_ones_form(): void
    {
        $id = $this->make();
        $this->publish($id);

        $rival = $this->rival['admin'];
        $this->actingAs($rival)->postJson("/api/v1/tracking/forms/{$id}/publish")->assertNotFound();
        $this->actingAs($rival)->postJson("/api/v1/tracking/forms/{$id}/duplicate")->assertNotFound();
        $this->actingAs($rival)->getJson("/api/v1/tracking/forms/{$id}/versions")->assertNotFound();
        $this->actingAs($rival)->getJson("/api/v1/tracking/forms/{$id}/analytics")->assertNotFound();
        $this->assertSame(1, LandingFormVersion::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->count());
    }

    public function test_a_salesperson_may_not_publish_copy_or_upload(): void
    {
        Storage::fake('local');
        $id = $this->make();
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->postJson("/api/v1/tracking/forms/{$id}/publish")->assertForbidden();
        $this->actingAs($rep)->postJson("/api/v1/tracking/forms/{$id}/duplicate")->assertForbidden();
        $this->actingAs($rep)->postJson('/api/v1/tracking/forms/assets', ['file' => UploadedFile::fake()->image('l.png')])->assertForbidden();
    }

    public function test_a_published_page_of_one_workspace_never_serves_anothers_version(): void
    {
        $id = $this->make();
        $this->publish($id);
        $theirs = $this->actingAs($this->rival['admin'])->postJson('/api/v1/tracking/forms', ['name' => 'المنافس', 'headline' => 'سر المنافس', 'fields' => $this->fields()])->assertCreated()->json('data.form.id');
        $this->actingAs($this->rival['admin'])->postJson("/api/v1/tracking/forms/{$theirs}/publish")->assertOk();

        // A link of ours pointed (by a bug) at their form finds nothing, not their page.
        $theirForm = LandingForm::withoutGlobalScopes()->where('uuid', $theirs)->first();
        $link = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create(['token' => 'x'.random_int(10000000, 99999999), 'name' => 'سطو', 'channel' => 'meta', 'mode' => 'landing', 'project_id' => $this->project->id]));
        TrackingLink::withoutGlobalScopes()->whereKey($link->id)->update(['landing_form_id' => $theirForm->id]);

        $this->page($link->refresh())->assertNotFound();
        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->where('tenant_id', $this->rival['tenant']->id)->count());
    }
}
