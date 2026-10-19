<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Domain\Leads\NextBestAction;
use App\Jobs\RunScheduledAction;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ScheduledAction;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The pack grows to eleven answers, and the new ones are not decoration: the
 * cadence changes when the journey follows up, the target changes who is late,
 * a pause stops the messages, and one press switches it all on.
 */
class AutomationPackV2Test extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([RunScheduledAction::class]);
        $this->t = $this->provisionTenant('Pack2 Co');
    }

    private function project(array $attributes = []): Project
    {
        return $this->asTenant($this->t['tenant'], fn () => Project::create(array_merge(['name' => 'المرجان'], $attributes)));
    }

    private function pack(Project $project): array
    {
        return $this->actingAs($this->t['admin'])->getJson("/api/v1/projects/{$project->uuid}/automation-pack")->assertOk()->json('data.pack');
    }

    private function lead(Project $project): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'سلطان', 'phone' => '0551234567', 'phone_normalized' => '+9665512'.random_int(10000, 99999),
            'status' => 'new', 'project_id' => $project->id,
        ]));
    }

    // ---------------------------------------------------------------- the steps

    public function test_the_four_new_steps_report_their_state(): void
    {
        $project = $this->project();

        $steps = collect($this->pack($project)['steps'])->keyBy('key');

        $this->assertFalse($steps['sla']['done']);
        $this->assertTrue($steps['scoring']['done']);
        $this->assertSame('default', $steps['scoring']['detail']);
        $this->assertFalse($steps['cadence']['done']);
        $this->assertFalse($steps['quiet_hours']['done']);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/projects/{$project->uuid}/automation-pack", [
            'cadence' => 'intensive', 'sla_minutes' => 10, 'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00'],
        ])->assertOk();

        $steps = collect($this->pack($project)['steps'])->keyBy('key');
        $this->assertTrue($steps['sla']['done']);
        $this->assertSame('10', $steps['sla']['detail']);
        $this->assertSame('intensive', $steps['cadence']['detail']);
        $this->assertSame('project', $steps['quiet_hours']['detail']);
    }

    public function test_a_bad_cadence_or_target_is_refused(): void
    {
        $project = $this->project();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/projects/{$project->uuid}/automation-pack", ['cadence' => 'frantic'])->assertStatus(422);
        $this->actingAs($this->t['admin'])->postJson("/api/v1/projects/{$project->uuid}/automation-pack", ['sla_minutes' => 0])->assertStatus(422);
    }

    // ------------------------------------------------------------ the effects

    public function test_the_cadence_sets_the_pace_of_the_journeys_waits(): void
    {
        $gentle = $this->project(['name' => 'هادئ', 'automation_settings' => ['cadence' => 'gentle']]);
        $intensive = $this->project(['name' => 'مكثف', 'automation_settings' => ['cadence' => 'intensive']]);

        // Through the front door, so the journey starts the way it does for a real lead.
        $arrive = fn (Project $p, string $phone) => $this->asTenant($this->t['tenant'], fn () => Lead::where('uuid', $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/leads', ['full_name' => 'سلطان', 'phone' => $phone, 'project_id' => $p->uuid])->assertSuccessful()->json('data.lead.id'))->firstOrFail());

        $slow = $arrive($gentle, '0551110001');
        $fast = $arrive($intensive, '0551110002');

        $wait = fn (Lead $lead) => (int) ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)
            ->get()->first(fn ($s) => ($s->action['type'] ?? null) === 'ask_qualification')
            ?->run_at->diffInMinutes(now(), true);

        // The journey asks its questions 3 minutes in; gentle doubles that, intensive halves it.
        $this->assertEqualsWithDelta(6, $wait($slow), 1);
        $this->assertEqualsWithDelta(2, $wait($fast), 1);
    }

    public function test_a_paused_project_is_not_messaged_by_rules(): void
    {
        $project = $this->project(['automation_settings' => ['active' => false]]);
        $lead = $this->lead($project);
        $template = $this->asTenant($this->t['tenant'], fn () => MessageTemplate::create(['name' => 'تجربة التوقف', 'body' => 'مرحبًا', 'is_active' => true]));

        $result = $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)->execute($lead, ['type' => 'send_whatsapp', 'template_uuid' => $template->uuid]));

        $this->assertSame('skipped', $result['result']);
        $this->assertStringContainsString('أُوقفت', $result['description']);
    }

    public function test_a_project_with_its_own_target_is_judged_against_it(): void
    {
        $this->asTenant($this->t['tenant'], fn () => TenantSetting::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->update(['first_contact_target_minutes' => 60]));
        $strict = $this->project(['name' => 'صارم', 'automation_settings' => ['sla_minutes' => 10]]);
        $relaxed = $this->project(['name' => 'مريح']);

        $make = fn (Project $p) => $this->asTenant($this->t['tenant'], function () use ($p) {
            $lead = Lead::create(['full_name' => 'س', 'phone' => '0551111111', 'phone_normalized' => '+966551'.random_int(100000, 999999), 'status' => 'new', 'project_id' => $p->id, 'assigned_to' => $this->t['admin']->id]);
            $lead->forceFill(['created_at' => now()->subMinutes(20)])->saveQuietly();

            return $lead->fresh();
        });

        $action = fn (Lead $lead) => app(NextBestAction::class)->for($lead, 60);

        $this->assertSame('critical', $this->asTenant($this->t['tenant'], fn () => $action($make($strict)))['urgency'], '20 minutes is late for a 10-minute project');
        $this->assertSame('high', $this->asTenant($this->t['tenant'], fn () => $action($make($relaxed)))['urgency'], 'and still inside the workspace\'s 60');
    }

    // ------------------------------------------------------------- activation

    public function test_activate_completes_the_pack_switches_the_journey_on_and_lets_the_project_run(): void
    {
        $project = $this->project(['automation_settings' => ['active' => false]]);
        $this->asTenant($this->t['tenant'], fn () => AutomationRule::whereIn('template_key', ['lead_journey', 'reply_hand_off', 'qualified_hand_off'])->update(['is_enabled' => false]));
        $this->assertFalse($this->pack($project)['journey_on']);
        $this->assertFalse($this->pack($project)['active']);

        $pack = $this->actingAs($this->t['admin'])->postJson("/api/v1/projects/{$project->uuid}/automation-pack/activate", ['service' => 'شقق', 'language' => 'ar'])
            ->assertOk()->json('data.pack');

        $this->assertTrue($pack['active']);
        $this->assertTrue($pack['journey_on']);
        $steps = collect($pack['steps'])->keyBy('key');
        $this->assertTrue($steps['service']['done']);
        $this->assertTrue($steps['qualification']['done']);
        $this->assertSame('standard', $pack['settings']['cadence']);
    }

    public function test_pause_stops_a_project_and_resume_starts_it_again(): void
    {
        $project = $this->project();

        $this->assertFalse($this->actingAs($this->t['admin'])->postJson("/api/v1/projects/{$project->uuid}/automation-pack/pause")->assertOk()->json('data.pack.active'));
        $this->assertTrue($this->actingAs($this->t['admin'])->postJson("/api/v1/projects/{$project->uuid}/automation-pack/activate")->assertOk()->json('data.pack.active'));
    }

    public function test_a_rep_cannot_activate_or_pause(): void
    {
        $project = $this->project();
        $rep = $this->makeUser($this->t['tenant']);

        $this->actingAs($rep)->postJson("/api/v1/projects/{$project->uuid}/automation-pack/activate")->assertForbidden();
        $this->actingAs($rep)->postJson("/api/v1/projects/{$project->uuid}/automation-pack/pause")->assertForbidden();
    }
}
