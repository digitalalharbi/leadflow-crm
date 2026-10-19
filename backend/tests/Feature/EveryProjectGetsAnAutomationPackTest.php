<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A new project asks seven questions, not for dozens of rules — and whatever
 * it is not told, it fills from sensible defaults so its leads are never met
 * with silence.
 */
class EveryProjectGetsAnAutomationPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_project_says_what_its_journey_still_needs_and_fills_it(): void
    {
        $t = $this->provisionTenant('Pack Co');
        $project = $this->asTenant($t['tenant'], fn () => Project::create(['name' => 'المرجان']));

        $before = $this->actingAs($t['admin'])->getJson("/api/v1/projects/{$project->uuid}/automation-pack")->assertOk()->json('data.pack');
        $steps = collect($before['steps'])->keyBy('key');

        $this->assertSame(7, $before['total']);
        $this->assertTrue($before['journey_on']);
        $this->assertFalse($steps['service']['done']);
        $this->assertFalse($steps['qualification']['done']);
        // The workspace's general welcome already covers it.
        $this->assertSame('general', $steps['whatsapp_template']['detail']);

        $after = $this->actingAs($t['admin'])->postJson("/api/v1/projects/{$project->uuid}/automation-pack", ['service' => 'فلل', 'language' => 'ar'])
            ->assertOk()->json('data.pack');
        $steps = collect($after['steps'])->keyBy('key');

        $this->assertTrue($steps['service']['done']);
        $this->assertSame('project', $steps['whatsapp_template']['detail']);
        $this->assertSame('project', $steps['email_template']['detail']);
        $this->assertSame('3', $steps['qualification']['detail']);

        $welcome = $this->asTenant($t['tenant'], fn () => MessageTemplate::where('project_id', $project->id)->where('channel', 'whatsapp')->firstOrFail());
        $this->assertStringContainsString('فلل', $welcome->body);

        // Completing it twice changes nothing.
        $this->actingAs($t['admin'])->postJson("/api/v1/projects/{$project->uuid}/automation-pack", [])->assertOk();
        $this->assertSame(3, $this->asTenant($t['tenant'], fn () => ProjectQuestion::where('project_id', $project->id)->count()));
        $this->assertSame(1, $this->asTenant($t['tenant'], fn () => MessageTemplate::where('project_id', $project->id)->where('channel', 'whatsapp')->count()));
    }

    public function test_a_rep_cannot_change_a_projects_pack_and_another_workspace_cannot_see_it(): void
    {
        $t = $this->provisionTenant('Pack Co');
        $project = $this->asTenant($t['tenant'], fn () => Project::create(['name' => 'المرجان']));
        $rep = $this->makeUser($t['tenant']);

        $this->actingAs($rep)->postJson("/api/v1/projects/{$project->uuid}/automation-pack", ['service' => 'x'])->assertForbidden();

        $other = $this->provisionTenant('Other Co');
        $this->actingAs($other['admin'])->getJson("/api/v1/projects/{$project->uuid}/automation-pack")->assertNotFound();
    }
}
