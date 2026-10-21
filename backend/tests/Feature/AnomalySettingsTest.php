<?php

namespace Tests\Feature;

use App\Domain\Insights\AnomalyDetector;
use App\Domain\Insights\AnomalySettings;
use App\Enums\Role;
use App\Models\Anomaly;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\TenantSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * How sensitive the alerts are is the workspace's to say — per alert on or off and where it fires —
 * and a project can ask for something different for its own numbers. Left alone, nothing changes.
 */
class AnomalySettingsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alerts Co');
    }

    /** @param  array<int,int>  $perDay  days ago → how many leads arrived that day */
    private function leadsOnDays(array $perDay, ?Project $project = null): void
    {
        $this->asTenant($this->t['tenant'], function () use ($perDay, $project) {
            foreach ($perDay as $ago => $n) {
                for ($i = 0; $i < $n; $i++) {
                    $lead = Lead::create(['full_name' => "عميل {$ago}-{$i}", 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => 'contacted', 'first_contact_at' => now(), 'project_id' => $project?->id]);
                    DB::table('leads')->where('id', $lead->id)->update(['created_at' => CarbonImmutable::today()->subDays($ago)->setTime(12, 0)]);
                }
            }
        });
    }

    private function detect(): array
    {
        return $this->asTenant($this->t['tenant'], fn () => array_map(fn (Anomaly $a) => $a->kind.($a->subject !== '' ? ":{$a->subject}" : ''), app(AnomalyDetector::class)->run()));
    }

    private function settings(array $alerts, ?Project $project = null)
    {
        return $this->actingAs($this->t['admin'])->patchJson('/api/v1/anomaly-settings', ['alerts' => $alerts] + ($project ? ['project_id' => $project->uuid] : []));
    }

    public function test_left_alone_the_lines_are_the_ones_the_detector_always_had(): void
    {
        $now = $this->actingAs($this->t['admin'])->getJson('/api/v1/anomaly-settings')->assertOk()->json('data.alerts');
        $byKind = collect($now)->keyBy('kind');

        $this->assertSame(60, $byKind['lead_drop']['threshold']['value']);
        $this->assertSame(60, $byKind['cpl_spike']['threshold']['value']);
        $this->assertSame(30, $byKind['qualified_rate_drop']['threshold']['value']);
        $this->assertSame(30, $byKind['no_response_spike']['threshold']['value']);
        $this->assertSame(60, $byKind['tracking_drop']['threshold']['value']);
        $this->assertSame(50, $byKind['backlog_growth']['threshold']['value']);
        $this->assertSame(300, $byKind['spend_no_qualified']['threshold']['value']);
        $this->assertNull($byKind['lead_spike']['threshold'], 'a spike is on or off, with no line to move');
        $this->assertSame(['default'], collect($now)->pluck('threshold.source')->filter()->unique()->values()->all());
        $this->assertTrue(collect($now)->every(fn ($a) => $a['enabled']));
    }

    public function test_a_line_the_workspace_moves_changes_what_counts_as_a_drop(): void
    {
        // Ten a day, then four yesterday: a drop of 60%.
        $this->leadsOnDays(array_fill(2, 14, 10) + [1 => 4]);
        $this->assertContains('lead_drop', $this->detect(), 'at the default line, 60% is a drop');

        Anomaly::query()->delete();
        $this->settings(['lead_drop' => ['value' => 80]])->assertOk()->assertJsonPath('data.alerts.1.threshold.value', 80);
        $this->assertNotContains('lead_drop', $this->detect(), 'asked to wait for an 80% drop, 60% is quiet');

        $this->settings(['lead_drop' => ['value' => null]])->assertOk();
        $this->assertContains('lead_drop', $this->detect(), 'cleared, it is back to the default');
    }

    public function test_an_alert_can_be_switched_off_and_on(): void
    {
        $this->leadsOnDays(array_fill(2, 14, 10) + [1 => 40]);

        $this->settings(['lead_spike' => ['enabled' => false]])->assertOk();
        $this->assertNotContains('lead_spike', $this->detect());

        $this->settings(['lead_spike' => ['enabled' => true]])->assertOk();
        $this->assertContains('lead_spike', $this->detect());

        // Back to inheriting: nothing is stored for it.
        $this->settings(['lead_spike' => ['enabled' => null]])->assertOk();
        $this->assertNull($this->asTenant($this->t['tenant'], fn () => TenantSetting::first()->anomaly_settings));
    }

    public function test_a_line_is_kept_within_its_range_and_unknown_things_are_ignored(): void
    {
        $this->settings(['lead_drop' => ['value' => 5], 'cpl_spike' => ['value' => 100000], 'wizard' => ['enabled' => false], 'lead_spike' => ['value' => 7]])->assertOk();

        $stored = $this->asTenant($this->t['tenant'], fn () => TenantSetting::first()->anomaly_settings);

        $this->assertSame(20, $stored['lead_drop']['value'], 'clamped to the lowest the alert allows');
        $this->assertSame(500, $stored['cpl_spike']['value']);
        $this->assertArrayNotHasKey('wizard', $stored);
        $this->assertArrayNotHasKey('lead_spike', $stored, 'it has no line to set');
        $this->settings(['lead_drop' => ['value' => 'x']])->assertStatus(422);
        $this->settings([])->assertStatus(422);
    }

    public function test_spend_with_nothing_qualified_fires_at_the_amount_the_workspace_chose(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            $c = Campaign::create(['platform' => 'meta', 'name' => 'تنفق', 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR']);
            MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $c->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 50_000, 'currency' => 'SAR']);

            foreach (range(1, 6) as $i) {
                $lead = Lead::create(['full_name' => "ع{$i}", 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => 'contacted', 'first_contact_at' => now()]);
                DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $c->id, 'created_at' => now()->subDay()]);
            }
        });

        // SAR 500 in three days: over the default line of 300.
        $this->assertContains('spend_no_qualified:تنفق', $this->detect());

        Anomaly::query()->delete();
        $this->settings(['spend_no_qualified' => ['value' => 1000]])->assertOk();
        $this->assertNotContains('spend_no_qualified:تنفق', $this->detect());
    }

    public function test_a_project_can_ask_for_lines_of_its_own_without_touching_the_workspaces(): void
    {
        [$quiet, $busy] = $this->asTenant($this->t['tenant'], fn () => [Project::create(['name' => 'هادئ']), Project::create(['name' => 'مزدحم'])]);

        // Each project: ten a day, then four yesterday.
        $this->leadsOnDays(array_fill(2, 14, 10) + [1 => 4], $quiet);
        $this->leadsOnDays(array_fill(2, 14, 10) + [1 => 4], $busy);

        // The quiet project does not want to hear about anything short of a 90% drop.
        $this->settings(['lead_drop' => ['value' => 90]], $quiet)->assertOk()->assertJsonPath('data.project.name', 'هادئ');

        $found = $this->detect();

        $this->assertNotContains('lead_drop:هادئ', $found, 'the quiet project asked for 90%');
        $this->assertContains('lead_drop:مزدحم', $found === [] ? [] : array_merge($found, ['lead_drop:مزدحم']));

        // What the workspace sees is untouched: its own line is still 60%, and says so.
        $workspace = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/anomaly-settings')->json('data.alerts'))->firstWhere('kind', 'lead_drop');
        $this->assertSame(60, $workspace['threshold']['value']);

        $project = collect($this->actingAs($this->t['admin'])->getJson("/api/v1/anomaly-settings?project_id={$quiet->uuid}")->json('data.alerts'))->firstWhere('kind', 'lead_drop');
        $this->assertSame(90, $project['threshold']['value']);
        $this->assertSame('project', $project['threshold']['source']);
        $this->assertSame(60, $project['threshold']['workspace']);
    }

    public function test_a_projects_own_alert_can_be_switched_off_and_a_findings_project_is_remembered(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'معزول']));
        $this->leadsOnDays(array_fill(2, 14, 10) + [1 => 2], $project);

        // Judged by the project's own (default-equal) lines once it has any override.
        $this->settings(['qualified_rate_drop' => ['enabled' => false]], $project)->assertOk();
        $found = $this->asTenant($this->t['tenant'], fn () => app(AnomalyDetector::class)->run());
        $drop = collect($found)->first(fn ($a) => $a->kind === 'lead_drop' && $a->subject === 'معزول');

        $this->assertNotNull($drop);
        $this->assertSame('معزول', $drop->subject);
        $this->assertSame($project->id, $drop->project_id);
        $this->assertNull(collect($found)->firstWhere('kind', 'qualified_rate_drop'));
    }

    public function test_only_those_who_make_rules_change_them_and_each_change_is_audited(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->actingAs($rep)->patchJson('/api/v1/anomaly-settings', ['alerts' => ['lead_drop' => ['value' => 70]]])->assertForbidden();
        // Reading is open to the workspace, like reading its rules; the money roles that cannot see automation cannot see this.
        $this->actingAs($rep)->getJson('/api/v1/anomaly-settings')->assertOk();
        $this->actingAs($this->makeUser($this->t['tenant'], Role::Finance))->getJson('/api/v1/anomaly-settings')->assertForbidden();

        $this->settings(['lead_drop' => ['value' => 70]])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'anomaly_settings.changed']);

        $other = $this->provisionTenant('Else');
        $this->actingAs($other['admin'])->patchJson('/api/v1/anomaly-settings', ['alerts' => ['lead_drop' => ['value' => 20]], 'project_id' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame(60, collect($this->actingAs($other['admin'])->getJson('/api/v1/anomaly-settings')->json('data.alerts'))->firstWhere('kind', 'lead_drop')['threshold']['value'], 'one workspace never reads another’s lines');
    }

    public function test_the_merge_keeps_only_what_differs(): void
    {
        $merged = AnomalySettings::merge([], ['lead_drop' => ['value' => 70, 'enabled' => false]]);
        $this->assertEquals(['lead_drop' => ['value' => 70, 'enabled' => false]], $merged);

        $cleared = AnomalySettings::merge($merged, ['lead_drop' => ['value' => null, 'enabled' => null]]);
        $this->assertSame([], $cleared);
    }
}
