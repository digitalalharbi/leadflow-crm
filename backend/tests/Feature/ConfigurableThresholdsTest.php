<?php

namespace Tests\Feature;

use App\Domain\Automation\Thresholds;
use App\Models\Lead;
use App\Models\Project;
use App\Models\TenantSetting;
use App\Services\Leads\LeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every line the product draws — hot, priority, high value, SLA, stalled, the
 * daily cap, the cooldown — is the workspace's to set and the project's to
 * override, and the lists, counts, alerts and scoring all read the same one.
 */
class ConfigurableThresholdsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Lines Co');
    }

    private function settings(array $attributes): void
    {
        TenantSetting::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->update($attributes);
    }

    private function project(array $attributes = []): Project
    {
        $scoring = $attributes['scoring'] ?? null;
        unset($attributes['scoring']);

        return $this->asTenant($this->t['tenant'], function () use ($attributes, $scoring) {
            $project = Project::create(array_merge(['name' => 'المرجان'], $attributes));

            if ($scoring) {
                $project->forceFill(['scoring' => $scoring])->save();
            }

            return $project;
        });
    }

    private function lead(array $attributes = []): Lead
    {
        $guarded = array_intersect_key($attributes, array_flip(['created_at', 'last_contact_at']));

        return $this->asTenant($this->t['tenant'], function () use ($attributes, $guarded) {
            $lead = Lead::create(array_merge([
                'full_name' => 'عميل '.random_int(100, 999), 'phone' => '0551234567',
                'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'contacted',
            ], array_diff_key($attributes, $guarded)));
            if ($guarded) {
                $lead->forceFill($guarded)->saveQuietly();
            }

            return $lead;
        });
    }

    private function inView(string $key): array
    {
        return collect($this->actingAs($this->t['admin'])->getJson("/api/v1/leads?view={$key}&per_page=100")->assertOk()->json('data'))->pluck('id')->all();
    }

    private function describe(?Project $project = null): array
    {
        return $this->asTenant($this->t['tenant'], fn () => Thresholds::describe(TenantSetting::query()->first(), $project));
    }

    // ------------------------------------------------------------- resolution

    public function test_a_workspace_that_says_nothing_gets_the_products_defaults(): void
    {
        $lines = $this->describe();

        $this->assertSame(500_000, $lines['high_value_from']['value']);
        $this->assertSame(3, $lines['stalled_after_days']['value']);
        $this->assertSame(88, $lines['priority_from']['value']);
        $this->assertSame('default', $lines['stalled_after_days']['source']);
    }

    public function test_the_workspace_overrides_the_default_and_a_project_overrides_the_workspace(): void
    {
        $this->settings(['stalled_after_days' => 10, 'high_value_from' => 900_000]);
        $project = $this->project(['automation_settings' => ['stalled_after_days' => 2, 'max_contacts_per_day' => 3], 'scoring' => ['hot_from' => 80]]);

        $workspace = $this->describe();
        $this->assertSame(['value' => 10, 'source' => 'workspace'], array_intersect_key($workspace['stalled_after_days'], array_flip(['value', 'source'])));

        $mine = $this->describe($project);
        $this->assertSame(2, $mine['stalled_after_days']['value']);
        $this->assertSame('project', $mine['stalled_after_days']['source']);
        $this->assertSame(10, $mine['stalled_after_days']['workspace'], 'what it would be without the override is still shown');
        $this->assertSame(900_000, $mine['high_value_from']['value'], 'untouched lines come from the workspace');
        $this->assertSame(3, $mine['max_contacts_per_day']['value']);
        $this->assertSame(80, $mine['hot_from']['value'], 'scoring lines are read from the project scoring');
    }

    // ----------------------------------------------------------------- effects

    public function test_the_workspaces_stalled_line_decides_who_is_stalled(): void
    {
        $quiet = $this->lead(['created_at' => now()->subDays(8), 'last_contact_at' => now()->subDays(6)]);

        $this->assertContains($quiet->uuid, $this->inView('stalled'), 'six days of silence is stalled at the default of three');

        $this->settings(['stalled_after_days' => 10]);
        $this->assertNotContains($quiet->uuid, $this->inView('stalled'), 'and not at ten');

        $counts = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads/segments')->json('data');
        $this->assertSame(0, $counts['stalled'], 'the chip agrees with the list');
    }

    public function test_a_project_can_hold_its_own_leads_to_a_different_stalled_line(): void
    {
        $strict = $this->project(['name' => 'صارم', 'automation_settings' => ['stalled_after_days' => 1]]);
        $normal = $this->project(['name' => 'عادي']);
        $a = $this->lead(['project_id' => $strict->id, 'created_at' => now()->subDays(5), 'last_contact_at' => now()->subDays(2)]);
        $b = $this->lead(['project_id' => $normal->id, 'created_at' => now()->subDays(5), 'last_contact_at' => now()->subDays(2)]);

        $stalled = $this->inView('stalled');

        $this->assertContains($a->uuid, $stalled, 'two days is past the strict project\'s one');
        $this->assertNotContains($b->uuid, $stalled, 'and inside the default three');
    }

    public function test_the_high_value_line_moves_the_list_and_the_alert(): void
    {
        $lead = $this->lead(['budget_max' => 700_000]);
        $this->assertContains($lead->uuid, $this->inView('high_value'));

        $this->settings(['high_value_from' => 2_000_000]);
        $this->assertNotContains($lead->uuid, $this->inView('high_value'));

        // And a project may set its own: a 700,000 lead in a premium project is ordinary.
        $this->settings(['high_value_from' => 500_000]);
        $premium = $this->project(['name' => 'فاخر', 'automation_settings' => ['high_value_from' => 3_000_000]]);
        $ordinary = $this->lead(['project_id' => $premium->id, 'budget_max' => 700_000]);
        $this->assertNotContains($ordinary->uuid, $this->inView('high_value'));
    }

    public function test_the_priority_line_can_be_set_for_the_workspace(): void
    {
        $this->settings(['score_priority_threshold' => 60, 'score_hot_threshold' => 50]);
        $lead = $this->lead(['status' => 'qualified', 'qualification_status' => 'qualified', 'appointment_interest' => true, 'budget_max' => 3_000_000]);

        $scored = $this->asTenant($this->t['tenant'], fn () => app(LeadScoringService::class)->evaluate($lead->fresh()));

        $this->assertSame(60, $this->describe()['priority_from']['value']);
        $this->assertSame($scored['score'] >= 60 && $scored['temperature']->value === 'hot', $scored['priority']);
    }

    public function test_the_daily_cap_and_cooldown_can_be_set_per_project(): void
    {
        $project = $this->project(['automation_settings' => ['max_contacts_per_day' => 1, 'cooldown_minutes' => 45]]);
        $lines = $this->describe($project);

        $this->assertSame(1, $lines['max_contacts_per_day']['value']);
        $this->assertSame(45, $lines['cooldown_minutes']['value']);
        $this->assertSame('project', $lines['cooldown_minutes']['source']);
    }

    // --------------------------------------------------------------------- API

    public function test_the_workspace_sets_and_clears_its_lines_over_the_api(): void
    {
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/settings', ['stalled_after_days' => 7, 'high_value_from' => 1_200_000, 'score_priority_threshold' => 75])->assertOk();

        $data = $this->actingAs($this->t['admin'])->getJson('/api/v1/settings')->assertOk()->json('data');
        $this->assertSame(7, $data['thresholds']['stalled_after_days']['value']);
        $this->assertSame('workspace', $data['thresholds']['stalled_after_days']['source']);
        $this->assertSame(75, $data['settings']['score_priority_threshold']);

        $this->actingAs($this->t['admin'])->patchJson('/api/v1/settings', ['stalled_after_days' => null])->assertOk();
        $this->assertSame(3, $this->actingAs($this->t['admin'])->getJson('/api/v1/settings')->json('data.thresholds.stalled_after_days.value'));
    }

    public function test_a_project_sets_clears_and_reads_its_own_lines(): void
    {
        $project = $this->project();
        $url = "/api/v1/projects/{$project->uuid}/thresholds";

        $set = $this->actingAs($this->t['admin'])->patchJson($url, ['hot_from' => 80, 'sla_minutes' => 10, 'stalled_after_days' => 2, 'cooldown_minutes' => 30])->assertOk()->json('data.thresholds');
        $this->assertSame(['value' => 80, 'source' => 'project'], array_intersect_key($set['hot_from'], array_flip(['value', 'source'])));
        $this->assertSame(10, $set['sla_minutes']['value']);

        $this->assertSame(80, $this->asTenant($this->t['tenant'], fn () => $project->fresh()->scoring['hot_from']), 'hot lives with the scoring');
        $this->assertSame(2, $this->asTenant($this->t['tenant'], fn () => $project->fresh()->automation_settings['stalled_after_days']));

        $cleared = $this->actingAs($this->t['admin'])->patchJson($url, ['hot_from' => null, 'stalled_after_days' => null])->assertOk()->json('data.thresholds');
        $this->assertSame('default', $cleared['hot_from']['source']);
        $this->assertSame('default', $cleared['stalled_after_days']['source']);
        $this->assertSame(10, $cleared['sla_minutes']['value'], 'what was not cleared stays');
    }

    public function test_bad_lines_are_refused_and_a_rep_cannot_set_them(): void
    {
        $project = $this->project();
        $url = "/api/v1/projects/{$project->uuid}/thresholds";

        $this->actingAs($this->t['admin'])->patchJson($url, ['stalled_after_days' => 0])->assertStatus(422);
        $this->actingAs($this->t['admin'])->patchJson($url, ['hot_from' => 50, 'warm_from' => 60])->assertStatus(422);
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/settings', ['stalled_after_days' => 500])->assertStatus(422);
        $this->actingAs($this->makeUser($this->t['tenant']))->patchJson($url, ['hot_from' => 80])->assertForbidden();
    }
}
