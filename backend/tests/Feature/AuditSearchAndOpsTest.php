<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\Project;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who moved that? — one trail for what people and automation changed; one box that finds leads,
 * units, campaigns and partners (only those the role may open); and the operations home's answers.
 */
class AuditSearchAndOpsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Audit Co');
    }

    private function api()
    {
        return $this->actingAs($this->t['admin']);
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create($attributes + ['full_name' => 'سلمان القحطاني', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'contacted', 'assigned_to' => $this->t['admin']->id]));
    }

    // ------------------------------------------------------------------- audit

    public function test_the_changes_the_workspace_cares_about_each_leave_a_record(): void
    {
        $rule = $this->api()->postJson('/api/v1/automation/rules', ['name' => 'قاعدة', 'trigger' => 'lead.created', 'conditions' => [], 'actions' => [['type' => 'notify', 'audience' => 'owner', 'message' => 'x']], 'is_enabled' => false])->assertCreated()->json('data.rule.id');
        $this->api()->patchJson("/api/v1/automation/rules/{$rule}", ['is_enabled' => true])->assertOk();
        $this->api()->deleteJson("/api/v1/automation/rules/{$rule}")->assertOk();

        // A sale, and then its value corrected: money on the record.
        $lead = $this->lead(['status' => 'won', 'deal_value_minor' => 10_000_000]);
        $this->actingAs($this->t['admin']);
        $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->forceFill(['deal_value_minor' => 50_000_000])->save());

        $actions = $this->asTenant($this->t['tenant'], fn () => AuditLog::pluck('action')->all());
        foreach (['automation_rule.created', 'automation_rule.updated', 'automation_rule.deleted', 'lead.deal_value_changed'] as $expected) {
            $this->assertContains($expected, $actions);
        }

        $updated = $this->asTenant($this->t['tenant'], fn () => AuditLog::where('action', 'automation_rule.updated')->first()->changes);
        $this->assertFalse($updated['before']['is_enabled']);
        $this->assertTrue($updated['after']['is_enabled']);
    }

    public function test_one_trail_reads_both_records_with_who_what_before_after_and_source(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $lead = $this->lead(['assigned_to' => null]);

        $this->api()->postJson("/api/v1/leads/{$lead->uuid}/assign", ['assigned_to' => $rep->uuid])->assertOk();
        $this->api()->postJson("/api/v1/leads/{$lead->uuid}/status", ['status' => 'qualified'])->assertOk();
        $this->api()->patchJson("/api/v1/users/{$rep->uuid}", ['role' => 'manager'])->assertOk();

        $entries = collect($this->api()->getJson('/api/v1/audit')->assertOk()->json('data.entries'));

        $assigned = $entries->firstWhere('action', 'lead.assigned');
        $this->assertSame('سلمان القحطاني', $assigned['subject']);
        $this->assertSame($this->t['admin']->name, $assigned['actor']);
        $this->assertSame('user', $assigned['source']);
        $this->assertSame($lead->uuid, $assigned['lead_id']);

        $status = $entries->firstWhere('action', 'lead.status_changed');
        $this->assertSame('contacted', $status['before']);
        $this->assertSame('qualified', $status['after']);

        $this->assertNotNull($entries->firstWhere('category', 'permissions'), 'a role change is on the trail');

        // Newest first.
        $this->assertSame($entries->pluck('at')->sortDesc()->values()->all(), $entries->pluck('at')->all());
    }

    public function test_the_trail_filters_by_category_and_only_managers_read_it(): void
    {
        $this->api()->postJson('/api/v1/automation/rules', ['name' => 'قاعدة', 'trigger' => 'lead.created', 'conditions' => [], 'actions' => [['type' => 'notify', 'audience' => 'owner', 'message' => 'x']], 'is_enabled' => false])->assertCreated();
        $this->api()->postJson('/api/v1/partners', ['name' => 'وسيط', 'type' => 'broker'])->assertCreated();

        $automation = collect($this->api()->getJson('/api/v1/audit?category=automation')->assertOk()->json('data.entries'));
        $this->assertNotEmpty($automation);
        $this->assertSame(['automation'], $automation->pluck('category')->unique()->values()->all());

        $this->actingAs($this->makeUser($this->t['tenant'], Role::Sales))->getJson('/api/v1/audit')->assertForbidden();
        $this->actingAs($this->makeUser($this->t['tenant'], Role::Finance))->getJson('/api/v1/audit')->assertForbidden();
        $this->actingAs($this->makeUser($this->t['tenant'], Role::Manager))->getJson('/api/v1/audit')->assertOk();
        $this->api()->getJson('/api/v1/audit?category=nonsense')->assertStatus(422);
    }

    public function test_another_workspace_never_appears_on_the_trail(): void
    {
        $this->api()->postJson('/api/v1/partners', ['name' => 'سري', 'type' => 'broker'])->assertCreated();
        $other = $this->provisionTenant('Rival');

        $this->assertSame([], $this->actingAs($other['admin'])->getJson('/api/v1/audit?days=365')->json('data.entries'));
    }

    // ------------------------------------------------------------------ search

    public function test_the_box_finds_units_campaigns_and_partners_too(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $this->asTenant($this->t['tenant'], function () use ($project) {
            Property::create(['project_id' => $project->id, 'code' => 'NRG-204', 'property_type' => 'apartment', 'title' => 'شقة النرجس']);
            Campaign::create(['platform' => 'meta', 'name' => 'حملة النرجس', 'origin' => 'manual']);
            Partner::create(['name' => 'وسيط النرجس', 'type' => 'broker']);
        });

        $r = $this->api()->getJson('/api/v1/search?q='.urlencode('النرجس'))->assertOk()->json('data');
        $this->assertSame('النرجس', $r['projects'][0]['name']);
        $this->assertSame('NRG-204', $r['properties'][0]['code']);
        $this->assertSame('حملة النرجس', $r['campaigns'][0]['name']);
        $this->assertSame('وسيط النرجس', $r['partners'][0]['name']);

        $this->assertSame('NRG-204', $this->api()->getJson('/api/v1/search?q=NRG-2')->json('data.properties.0.code'));
    }

    public function test_search_only_offers_what_the_role_may_open(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            Campaign::create(['platform' => 'meta', 'name' => 'حملة سرية', 'origin' => 'manual']);
            Partner::create(['name' => 'وسيط سري', 'type' => 'broker']);
        });

        $rep = $this->actingAs($this->makeUser($this->t['tenant'], Role::Sales))->getJson('/api/v1/search?q='.urlencode('سري'))->assertOk()->json('data');
        $this->assertSame([], $rep['campaigns']);
        $this->assertSame([], $rep['partners']);

        $client = $this->actingAs($this->makeUser($this->t['tenant'], Role::ClientViewer))->getJson('/api/v1/search?q='.urlencode('سري'))->assertOk()->json('data');
        $this->assertSame([], $client['campaigns']);
        $this->assertSame([], $client['partners']);
    }

    // --------------------------------------------------------------------- ops

    public function test_the_operations_home_answers_the_nine_questions_and_shows_four_lights(): void
    {
        $hot = $this->lead(['temperature' => 'hot']);
        $this->asTenant($this->t['tenant'], fn () => DB::table('leads')->where('id', $hot->id)->update(['interest_level' => 'hot']));
        $won = $this->lead(['status' => 'won']);
        $this->asTenant($this->t['tenant'], fn () => DB::table('leads')->where('id', $won->id)->update(['won_at' => now(), 'deal_value_minor' => 20_000_000]));

        $d = $this->api()->getJson('/api/v1/dashboard/ops')->assertOk()->json('data');

        foreach (['leads_today', 'interested', 'hot', 'needs_follow_up', 'sla_late', 'visits_today', 'reservations_pending', 'won_today', 'revenue_today'] as $key) {
            $this->assertArrayHasKey($key, $d['answers']);
        }
        $this->assertSame(2, $d['answers']['leads_today']);
        $this->assertSame(1, $d['answers']['hot']);
        $this->assertSame(1, $d['answers']['won_today']);
        $this->assertEquals(200000, $d['answers']['revenue_today']);
        $this->assertSame(['automation', 'tracking', 'campaigns', 'backlog'], array_keys($d['health']));
        $this->assertContains($d['health']['automation']['state'], ['ok', 'watch', 'bad']);
    }

    public function test_a_rep_sees_their_own_answers_and_no_health_lights(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->lead(['assigned_to' => $rep->id]);
        $this->lead(['assigned_to' => $this->t['admin']->id]);

        $d = $this->actingAs($rep)->getJson('/api/v1/dashboard/ops')->assertOk()->json('data');

        $this->assertSame(1, $d['answers']['leads_today']);
        $this->assertNull($d['health']);
    }

    public function test_revenue_is_not_sent_to_a_role_without_it(): void
    {
        $client = $this->makeUser($this->t['tenant'], Role::ClientViewer);

        $answers = $this->actingAs($client)->getJson('/api/v1/dashboard/ops')->assertOk()->json('data.answers');

        $this->assertArrayNotHasKey('revenue_today', $answers);
        $this->assertArrayHasKey('leads_today', $answers);
    }
}
