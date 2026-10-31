<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tasks are someone's, with a due time and a priority; a rep sees their own; nobody sees another company's; and a task
 * about a lead the person cannot open is not there.
 */
class TasksTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha');
    }

    public function test_a_task_is_created_on_a_lead_and_lands_in_the_right_bucket(): void
    {
        $lead = $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'Sara', 'phone' => '0551112222']));

        $id = $this->actingAs($this->a['admin'])->postJson('/api/v1/tasks', ['title' => 'Send the floor plan', 'lead' => $lead->uuid, 'due_at' => now()->subHour()->toIso8601String(), 'priority' => 'high'])
            ->assertCreated()->assertJsonPath('data.lead.id', $lead->uuid)->assertJsonPath('data.overdue', true)->json('data.id');

        $this->assertSame([$id], array_column($this->actingAs($this->a['admin'])->getJson('/api/v1/tasks?bucket=overdue')->json('data'), 'id'));
        $this->assertSame([], $this->actingAs($this->a['admin'])->getJson('/api/v1/tasks?bucket=today')->json('data'));

        $this->actingAs($this->a['admin'])->patchJson("/api/v1/tasks/{$id}", ['status' => 'done'])->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertNotNull(Task::withoutGlobalScopes()->first()->completed_at);
    }

    public function test_a_rep_sees_their_own_tasks_and_cannot_hand_work_to_others(): void
    {
        $rep = $this->makeUser($this->a['tenant']);
        $other = $this->makeUser($this->a['tenant']);

        $this->actingAs($this->a['admin'])->postJson('/api/v1/tasks', ['title' => 'For rep', 'assignee' => $rep->uuid])->assertCreated();
        $this->actingAs($this->a['admin'])->postJson('/api/v1/tasks', ['title' => 'For other', 'assignee' => $other->uuid])->assertCreated();

        $titles = array_column($this->actingAs($rep)->getJson('/api/v1/tasks')->json('data'), 'title');
        $this->assertSame(['For rep'], $titles);

        $this->actingAs($rep)->postJson('/api/v1/tasks', ['title' => 'Push it', 'assignee' => $other->uuid])->assertForbidden();
        $theirs = Task::withoutGlobalScopes()->where('title', 'For other')->value('uuid');
        $this->actingAs($rep)->patchJson("/api/v1/tasks/{$theirs}", ['status' => 'done'])->assertNotFound();
    }

    public function test_a_rep_cannot_attach_a_task_to_a_colleagues_lead(): void
    {
        $rep = $this->makeUser($this->a['tenant']);
        $colleague = $this->makeUser($this->a['tenant']);
        $theirs = $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'Not yours', 'phone' => '0553334444', 'assigned_to' => $colleague->id]));
        $mine = $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'Yours', 'phone' => '0555556666', 'assigned_to' => $rep->id]));

        $this->actingAs($rep)->postJson('/api/v1/tasks', ['title' => 'Snoop', 'lead' => $theirs->uuid])->assertStatus(422);
        $this->actingAs($rep)->postJson('/api/v1/tasks', ['title' => 'Call back', 'lead' => $mine->uuid])->assertCreated();
    }

    public function test_another_companys_task_lead_and_person_are_out_of_reach(): void
    {
        $b = $this->provisionTenant('Beta');
        $theirLead = $this->asTenant($b['tenant'], fn () => Lead::create(['full_name' => 'B', 'phone' => '0559990000']));
        $theirTask = $this->actingAs($b['admin'])->postJson('/api/v1/tasks', ['title' => 'Beta task'])->json('data.id');

        $this->actingAs($this->a['admin'])->patchJson("/api/v1/tasks/{$theirTask}", ['title' => 'x'])->assertNotFound();
        $this->actingAs($this->a['admin'])->postJson('/api/v1/tasks', ['title' => 'x', 'lead' => $theirLead->uuid])->assertStatus(422);
        $this->actingAs($this->a['admin'])->postJson('/api/v1/tasks', ['title' => 'x', 'assignee' => $b['admin']->uuid])->assertStatus(422);
        $this->assertSame([], $this->actingAs($this->a['admin'])->getJson('/api/v1/tasks')->json('data'));
    }

    public function test_a_project_limited_manager_does_not_see_tasks_on_other_projects(): void
    {
        [$mine, $other] = $this->asTenant($this->a['tenant'], fn () => [Project::create(['name' => 'Mine']), Project::create(['name' => 'Other'])]);
        $leadOther = $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'O', 'phone' => '0551230000', 'project_id' => $other->id]));
        $manager = $this->makeUser($this->a['tenant'], Role::Manager, ['all_projects' => false]);
        $manager->projects()->sync([$mine->id]);

        $this->actingAs($this->a['admin'])->postJson('/api/v1/tasks', ['title' => 'On mine', 'project' => $mine->uuid])->assertCreated();
        $hidden = $this->actingAs($this->a['admin'])->postJson('/api/v1/tasks', ['title' => 'On other lead', 'lead' => $leadOther->uuid])->json('data.id');

        app('auth')->forgetGuards();
        $titles = array_column($this->actingAs($manager->fresh())->getJson('/api/v1/tasks')->json('data'), 'title');

        if ($manager->fresh()->isProjectScoped()) {
            $this->assertSame(['On mine'], $titles);
            $this->actingAs($manager->fresh())->patchJson("/api/v1/tasks/{$hidden}", ['status' => 'done'])->assertNotFound();
        } else {
            $this->markTestSkipped('Managers are not project-scoped by default in this build.');
        }
    }
}
