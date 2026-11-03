<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Workspace and project limits, tried from the outside: every test here is somebody reaching for what is not theirs. */
class IsolationRound4Test extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private Project $mine;

    private Project $other;

    private User $limited;

    private User $rep;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha');
        [$this->mine, $this->other] = $this->asTenant($this->a['tenant'], fn () => [Project::create(['name' => 'Mine']), Project::create(['name' => 'Other'])]);
        $this->limited = $this->makeUser($this->a['tenant'], Role::Manager);
        $this->limited->forceFill(['all_projects' => false])->save();
        $this->limited->projects()->sync([$this->mine->id]);
        $this->rep = $this->makeUser($this->a['tenant'], Role::Sales);
        $this->colleague = $this->makeUser($this->a['tenant'], Role::Sales);
    }

    private function lead(array $attributes): Lead
    {
        return $this->asTenant($this->a['tenant'], fn () => Lead::create($attributes + ['full_name' => 'سارة السرية']));
    }

    public function test_typing_a_colleagues_number_returns_nothing_of_their_lead_and_changes_nothing(): void
    {
        $lead = $this->lead(['phone' => '0551234567', 'phone_normalized' => '+966551234567', 'assigned_to' => $this->colleague->id]);

        $response = $this->actingAs($this->rep)->postJson('/api/v1/leads', ['full_name' => 'x', 'phone' => '0551234567']);

        $response->assertUnprocessable();
        $this->assertStringNotContainsString('سارة', (string) $response->getContent());
        $this->assertSame(0, (int) $lead->fresh()->re_engaged_count);
    }

    public function test_a_number_in_another_project_gives_a_limited_manager_nothing_and_no_server_error(): void
    {
        $this->lead(['phone' => '0551234567', 'phone_normalized' => '+966551234567', 'project_id' => $this->other->id]);

        $response = $this->actingAs($this->limited)->postJson('/api/v1/leads', ['full_name' => 'x', 'phone' => '0551234567', 'project_id' => $this->mine->uuid]);

        $response->assertUnprocessable();
        $this->assertStringNotContainsString('سارة', (string) $response->getContent());
    }

    public function test_someone_allowed_to_see_the_lead_still_reengages_it(): void
    {
        $this->lead(['phone' => '0551234567', 'phone_normalized' => '+966551234567', 'project_id' => $this->mine->id]);

        $this->actingAs($this->limited)->postJson('/api/v1/leads', ['full_name' => 'x', 'phone' => '0551234567'])->assertOk()->assertJsonPath('data.duplicate', true);
    }

    public function test_the_raw_rows_of_someone_elses_import_are_not_readable(): void
    {
        $import = $this->asTenant($this->a['tenant'], function () {
            $import = Import::create(['user_id' => $this->a['admin']->id, 'original_filename' => 'x.csv', 'path' => 'x', 'status' => Import::COMPLETED]);
            ImportRow::create(['import_id' => $import->id, 'row_number' => 1, 'status' => 'failed', 'data' => ['mobile' => '0559999999']]);

            return $import;
        });

        $this->actingAs($this->limited)->getJson('/api/v1/imports')->assertOk()->assertJsonMissing(['id' => $import->uuid]);
        $this->actingAs($this->limited)->getJson("/api/v1/imports/{$import->uuid}")->assertNotFound();
        $this->actingAs($this->limited)->postJson("/api/v1/imports/{$import->uuid}/process", ['mapping' => ['phone' => 'a']])->assertNotFound();
        $this->actingAs($this->a['admin'])->getJson("/api/v1/imports/{$import->uuid}")->assertOk();
    }

    public function test_duplicates_in_another_project_are_not_listed(): void
    {
        $mine = $this->lead(['phone' => '0551111111', 'email' => 'same@x.test', 'project_id' => $this->mine->id]);
        $this->lead(['phone' => '0552222222', 'email' => 'same@x.test', 'project_id' => $this->other->id]);

        $this->actingAs($this->limited)->getJson("/api/v1/leads/{$mine->uuid}/duplicates")->assertOk()->assertJsonPath('data.duplicates', []);
        $this->assertCount(1, $this->actingAs($this->a['admin'])->getJson("/api/v1/leads/{$mine->uuid}/duplicates")->assertOk()->json('data.duplicates'));
    }

    public function test_company_wide_spend_is_closed_to_someone_limited_to_a_project(): void
    {
        $this->actingAs($this->limited)->getJson('/api/v1/campaign-spend')->assertForbidden();
        $this->actingAs($this->limited)->postJson('/api/v1/campaign-spend', [])->assertForbidden();
    }

    public function test_a_viewer_or_limited_manager_does_not_see_integration_health(): void
    {
        $this->asTenant($this->a['tenant'], fn () => Integration::query()->update(['last_error' => 'SECRET-ERROR']));

        $this->assertStringNotContainsString('SECRET-ERROR', (string) $this->actingAs($this->limited)->getJson('/api/v1/data-quality')->getContent());
    }

    public function test_a_rep_limited_to_projects_sees_only_those_units(): void
    {
        $this->rep->forceFill(['all_projects' => false])->save();
        $this->rep->projects()->sync([$this->mine->id]);
        $this->asTenant($this->a['tenant'], function () {
            Property::create(['project_id' => $this->mine->id, 'code' => 'MINE-1', 'property_type' => 'apartment']);
            Property::create(['project_id' => $this->other->id, 'code' => 'OTHER-1', 'property_type' => 'apartment']);
        });

        $body = (string) $this->actingAs($this->rep)->getJson('/api/v1/properties')->assertOk()->getContent();

        $this->assertStringContainsString('MINE-1', $body);
        $this->assertStringNotContainsString('OTHER-1', $body);
    }

    public function test_another_workspaces_things_are_simply_not_there(): void
    {
        $b = $this->provisionTenant('Beta');
        $lead = $this->asTenant($b['tenant'], fn () => Lead::create(['full_name' => 'B', 'phone' => '0553334444']));
        $project = $this->asTenant($b['tenant'], fn () => Project::create(['name' => 'B']));

        $this->actingAs($this->a['admin'])->getJson("/api/v1/leads/{$lead->uuid}")->assertNotFound();
        $this->actingAs($this->a['admin'])->getJson("/api/v1/projects/{$project->uuid}")->assertNotFound();
        $this->actingAs($this->a['admin'])->patchJson("/api/v1/leads/{$lead->uuid}", ['full_name' => 'x'])->assertNotFound();
        $this->actingAs($this->a['admin'])->postJson('/api/v1/leads', ['full_name' => 'x', 'phone' => '0553334445', 'project_id' => $project->uuid])->assertNotFound();
    }
}
