<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Domain\Automation\RuleTemplates;
use App\Enums\MessageStatus;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectQuestion;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The starting points a workspace installs instead of facing an empty form.
 *
 * The test that matters most here is the first one: a template whose actions
 * the API would reject is worse than no template at all. It would install, sit
 * in the list looking configured, and do nothing — and the workspace would
 * conclude the automation feature does not work.
 */
class AutomationTemplateTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Templates Co');
        // The gallery is tested against a workspace that has none of it yet.
        $this->withoutJourney($this->t['tenant']);
    }

    /** @return array<string, array{string}> */
    public static function templateKeys(): array
    {
        return array_combine(
            RuleTemplates::keys(),
            array_map(fn (string $k) => [$k], RuleTemplates::keys()),
        );
    }

    /**
     * Every template has to survive the same door a hand-written rule does.
     * The schema endpoint and the templates are two lists that must agree, and
     * nothing except this test makes them.
     *
     * It asserts what came back, not that the request was accepted. `validate()`
     * returns only the keys it has rules for, so a parameter nobody wrote a rule
     * for was dropped on the way in and the response was still 201 — every
     * template here carries a message, a note or a strategy, and the earlier
     * version of this test watched all of them be deleted and called it a pass.
     */
    #[DataProvider('templateKeys')]
    public function test_a_template_survives_the_door_a_typed_rule_goes_through(string $key): void
    {
        $template = RuleTemplates::get($key);

        $uuid = $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', [
                'name' => $template['name'],
                'trigger' => $template['trigger'],
                'conditions' => $template['conditions'],
                'actions' => $template['actions'],
            ])
            ->assertCreated()
            ->json('data.rule.id');

        $saved = AutomationRule::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

        $this->assertEquals($template['actions'], $saved->actions);
        $this->assertEquals($template['conditions'], $saved->conditions);
    }

    /**
     * The same loss, reached the way a customer reaches it: install a template,
     * open it to change one thing, save. Everything the form did not ask about
     * on that screen went with it.
     */
    #[DataProvider('templateKeys')]
    public function test_editing_an_installed_template_does_not_delete_the_rest_of_it(string $key): void
    {
        $template = RuleTemplates::get($key);

        $uuid = $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/automation/templates/{$key}")
            ->assertCreated()
            ->json('data.rule.id');

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/automation/rules/{$uuid}", [
                'name' => 'باسم آخر',
                'actions' => $template['actions'],
            ])
            ->assertOk();

        $saved = AutomationRule::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

        $this->assertEquals($template['actions'], $saved->actions);
    }

    #[DataProvider('templateKeys')]
    public function test_a_template_installs(string $key): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/automation/templates/{$key}")
            ->assertCreated()
            ->assertJsonPath('data.rule.trigger', RuleTemplates::get($key)['trigger']);
    }

    /**
     * Installed disabled, deliberately. A rule that starts running the moment
     * somebody clicks a card messages real customers before anyone has read
     * what it does.
     */
    /**
     * Validation is not enough. A template can pass every check above and still
     * do nothing when it runs: the executor read `in_minutes` while the
     * templates and the builder both wrote `in_hours`, so every rule that
     * scheduled a follow-up reported a failure into a trace nobody reads.
     *
     * This runs each template's actions for real and insists every one of them
     * reports success. It is the only test here that would have caught it.
     */
    #[DataProvider('templateKeys')]
    public function test_every_action_in_a_template_actually_works(string $key): void
    {
        // Somebody to assign to: "no rep available" is a correct report, not a
        // broken action, and a workspace with no sales team is not the case
        // this test is about.
        $this->makeUser($this->t['tenant']);

        // Likewise a brochure to send: a project with no files correctly reports
        // that nothing was sent, and that is not the case this test is about.
        $project = $this->asTenant($this->t['tenant'], function () {
            $project = Project::create(['name' => 'مشروع القالب']);
            ProjectFile::create([
                'project_id' => $project->id, 'name' => 'بروشور', 'kind' => 'brochure',
                'path' => 'project-files/'.uniqid().'.pdf', 'mime' => 'application/pdf',
                'size_bytes' => 1024, 'is_active' => true,
            ]);

            return $project;
        });

        // And a question to ask, for the journey's qualification step.
        $this->asTenant($this->t['tenant'], fn () => ProjectQuestion::create([
            'project_id' => $project->id, 'key' => 'budget', 'label' => 'ما ميزانيتك؟', 'type' => 'text', 'is_active' => true,
        ]));

        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'عميل القالب',
            'email' => 'template@example.com',
            'phone_normalized' => '+9665009'.self::uniqueDigits(5),
            'status' => 'new',
            'assigned_to' => $this->t['admin']->id,
            'project_id' => $project->id,
        ]));

        // WhatsApp is connected for this case: the real sender needs a live
        // provider, and "not connected" is a correct, separately tested report.
        $whatsapp = $this->mock(WhatsappSender::class);
        $whatsapp->shouldReceive('sendFile')->andReturn((new LeadMessage)->forceFill(['status' => MessageStatus::Sent]));
        $whatsapp->shouldReceive('sendSaved')->andReturn((new LeadMessage)->forceFill(['status' => MessageStatus::Sent, 'provider' => 'whatsapp_cloud']));

        $executor = app(ActionExecutor::class);

        foreach (RuleTemplates::get($key)['actions'] as $action) {
            $result = $this->asTenant(
                $this->t['tenant'],
                fn () => $executor->execute($lead->fresh(), $action),
            );

            $this->assertTrue(
                $result['ok'],
                "{$key}: action '{$action['type']}' reported — {$result['description']}",
            );
        }
    }

    public function test_an_installed_template_does_not_start_running(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/templates/new_lead_assign_notify')
            ->assertCreated()
            ->assertJsonPath('data.rule.is_enabled', false);
    }

    public function test_the_gallery_says_what_is_already_installed(): void
    {
        $before = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/automation/templates')->assertOk()->json('data.templates');

        $this->assertCount(count(RuleTemplates::keys()), $before);
        $this->assertFalse($before[0]['installed']);

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/automation/templates/{$before[0]['key']}")->assertCreated();

        $after = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/automation/templates')->assertOk()->json('data.templates');

        $this->assertTrue($after[0]['installed']);
    }

    /** Double-clicking a card must not produce two identical rules. */
    public function test_a_template_cannot_be_installed_twice(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/templates/hot_lead_priority')->assertCreated();

        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/templates/hot_lead_priority')->assertStatus(422);

        $this->assertSame(1, AutomationRule::where('template_key', 'hot_lead_priority')->count());
    }

    /** But two workspaces each get their own copy. */
    public function test_two_workspaces_can_both_install_the_same_template(): void
    {
        $other = $this->provisionTenant('Another Co');

        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/templates/hot_lead_priority')->assertCreated();

        $this->actingAs($other['admin'])
            ->postJson('/api/v1/automation/templates/hot_lead_priority')->assertCreated();
    }

    public function test_a_sales_rep_cannot_install_templates(): void
    {
        $this->actingAs($this->makeUser($this->t['tenant']))
            ->postJson('/api/v1/automation/templates/hot_lead_priority')
            ->assertForbidden();
    }

    public function test_an_unknown_template_is_not_found(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/templates/make_me_rich')
            ->assertNotFound();
    }
}
