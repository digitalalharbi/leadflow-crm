<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\LandingForm;
use App\Models\Project;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Landing forms and the links that open them: who may make them, that every one belongs to one workspace,
 * and that a link can only ever be pointed at what that workspace owns.
 */
class LandingFormApiTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $rival;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Forms Co');
        $this->rival = $this->provisionTenant('Rival Forms Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'نموذج النرجس',
            'project_id' => $this->project->uuid,
            'headline' => 'ابدأ امتلاك منزلك في النرجس',
            'description' => 'فلل عصرية في شمال الرياض',
            'benefits' => ['موقع مميز', 'تسليم خلال 12 شهرًا'],
            'cta_label' => 'اطلب التفاصيل',
            'consent_required' => true,
            'consent_text' => 'أوافق على أن يتواصل معي فريق المبيعات.',
            'fields' => [
                ['key' => 'name', 'type' => 'text', 'label' => 'الاسم', 'required' => true, 'mapping' => 'lead.full_name'],
                ['key' => 'mobile', 'type' => 'phone', 'label' => 'الجوال', 'required' => true, 'mapping' => 'lead.phone'],
                ['key' => 'budget', 'type' => 'number', 'label' => 'الميزانية', 'mapping' => 'lead.budget_max', 'step' => 2],
            ],
        ];
    }

    private function make(array $overrides = []): LandingForm
    {
        $id = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $this->payload($overrides))->assertCreated()->json('data.form.id');

        return $this->asTenant($this->t['tenant'], fn () => LandingForm::where('uuid', $id)->firstOrFail());
    }

    public function test_a_manager_makes_a_form_and_the_server_tidies_and_audits_it(): void
    {
        $response = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $this->payload())->assertCreated();

        $form = $response->json('data.form');
        $this->assertSame('draft', $form['status'], 'a new form is a draft until somebody publishes it');
        $this->assertSame(['name', 'mobile', 'budget'], array_column($form['fields'], 'key'), 'ordered by step, then as written');
        $this->assertSame(2, $form['steps'], 'the budget is on a second step');
        $this->assertSame('النرجس', $form['project']['name']);

        $this->assertNotNull(AuditLog::where('action', 'landing_form.created')->first(), 'making a form is on the trail');
    }

    public function test_a_form_that_cannot_work_is_refused_with_the_field_it_is_about(): void
    {
        $bad = $this->payload(['fields' => [['key' => 'name', 'type' => 'text', 'label' => 'الاسم', 'mapping' => 'lead.full_name']]]);

        $response = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $bad)->assertStatus(422);

        $this->assertArrayHasKey('fields', $response->json('errors'));
    }

    public function test_only_those_who_manage_links_make_or_change_forms(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $form = $this->make();

        $this->actingAs($rep)->postJson('/api/v1/tracking/forms', $this->payload())->assertForbidden();
        $this->actingAs($rep)->patchJson("/api/v1/tracking/forms/{$form->uuid}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($rep)->deleteJson("/api/v1/tracking/forms/{$form->uuid}")->assertForbidden();
    }

    public function test_publishing_needs_a_form_that_works_and_changing_it_is_audited(): void
    {
        $form = $this->make();

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/forms/{$form->uuid}", ['status' => 'published', 'headline' => 'عنوان جديد'])
            ->assertOk()->assertJsonPath('data.form.status', 'published');

        $log = AuditLog::where('action', 'landing_form.updated')->first();
        $this->assertNotNull($log);
        $this->assertSame('draft', $log->changes['before']['status'] ?? null);
        $this->assertSame('published', $log->changes['after']['status'] ?? null);
    }

    public function test_a_workspace_sees_only_its_own_forms_and_cannot_reach_another(): void
    {
        $mine = $this->make();
        $theirs = $this->asTenant($this->rival['tenant'], fn () => LandingForm::create(['name' => 'نموذج المنافس', 'status' => 'published', 'headline' => 'س', 'fields' => []]));

        $listed = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/tracking/forms')->assertOk()->json('data.forms'))->pluck('id');
        $this->assertTrue($listed->contains($mine->uuid));
        $this->assertFalse($listed->contains($theirs->uuid), 'another workspace\'s form is in the list');

        $this->actingAs($this->t['admin'])->getJson("/api/v1/tracking/forms/{$theirs->uuid}")->assertNotFound();
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/forms/{$theirs->uuid}", ['name' => 'سطو'])->assertNotFound();
        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/tracking/forms/{$theirs->uuid}")->assertNotFound();
        $this->assertSame('نموذج المنافس', $this->asTenant($this->rival['tenant'], fn () => $theirs->fresh()->name));
    }

    public function test_a_form_cannot_be_pointed_at_another_workspaces_project(): void
    {
        $theirProject = $this->asTenant($this->rival['tenant'], fn () => Project::create(['name' => 'مشروع المنافس']));

        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', $this->payload(['project_id' => $theirProject->uuid]))->assertNotFound();
    }

    public function test_a_link_can_open_a_landing_page_but_only_of_its_own_workspace(): void
    {
        $form = $this->make();
        $theirs = $this->asTenant($this->rival['tenant'], fn () => LandingForm::create(['name' => 'نموذج المنافس', 'status' => 'published', 'headline' => 'س', 'fields' => []]));

        $ok = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/links', [
            'name' => 'إعلان', 'channel' => 'meta', 'mode' => 'landing', 'landing_form_id' => $form->uuid, 'project_id' => $this->project->uuid,
            'custom_params' => ['ad_set' => 'north-1'],
        ])->assertCreated();
        $this->assertSame('landing', $ok->json('data.link.mode'));
        $this->assertSame($form->uuid, $ok->json('data.link.landing_form.id'));

        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/links', ['name' => 'سطو', 'channel' => 'meta', 'mode' => 'landing', 'landing_form_id' => $theirs->uuid])->assertNotFound();
    }

    public function test_a_link_cannot_name_another_workspaces_salesperson(): void
    {
        $theirRep = $this->makeUser($this->rival['tenant'], Role::Sales);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/links', ['name' => 'رابط', 'channel' => 'meta', 'user_id' => $theirRep->uuid])->assertNotFound();
        $this->assertSame(0, TrackingLink::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->where('user_id', $theirRep->id)->count());
    }

    public function test_the_field_presets_are_offered_with_their_mappings(): void
    {
        $presets = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/tracking/forms/presets')->assertOk()->json('data.presets'));

        foreach (['name', 'mobile', 'email', 'city', 'district', 'budget', 'property_type', 'purpose', 'financing', 'timeline', 'project', 'unit', 'source'] as $key) {
            $this->assertTrue($presets->contains('key', $key), "no preset for {$key}");
        }
        $this->assertSame('lead.full_name', $presets->firstWhere('key', 'name')['mapping']);
        $this->assertTrue($presets->firstWhere('key', 'source')['hidden'], 'the source is the server\'s to fill');
    }
}
