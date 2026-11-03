<?php

namespace Tests\Feature;

use App\Domain\Analytics\LeadFunnel;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ReportShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha');
    }

    private function share(array $over = []): array
    {
        return $this->actingAs($this->a['admin'])->postJson('/api/v1/report-shares', $over + ['name' => 'R', 'template' => 'executive'])->assertCreated()->json('data.share');
    }

    private function open(string $url)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->getJson('/api/v1/shared/reports/'.basename($url));
    }

    public function test_editing_keeps_the_link_and_pause_and_resume_keep_it_too(): void
    {
        $share = $this->share();
        $edited = $this->actingAs($this->a['admin'])->patchJson("/api/v1/report-shares/{$share['id']}", ['name' => 'New name'])->assertOk()->json('data.share');
        $this->assertSame($share['url'], $edited['url']);
        $this->open($share['url'])->assertOk();

        $this->actingAs($this->a['admin'])->postJson("/api/v1/report-shares/{$share['id']}/pause")->assertOk()->assertJsonPath('data.share.status', 'paused');
        $this->open($share['url'])->assertStatus(410);

        $resumed = $this->actingAs($this->a['admin'])->postJson("/api/v1/report-shares/{$share['id']}/resume")->assertOk()->json('data.share');
        $this->assertSame($share['url'], $resumed['url']);
        $this->open($share['url'])->assertOk();
    }

    public function test_archive_closes_the_link_and_duplicate_gets_its_own(): void
    {
        $share = $this->share();
        $copy = $this->actingAs($this->a['admin'])->postJson("/api/v1/report-shares/{$share['id']}/duplicate")->assertCreated()->json('data.share');
        $this->assertNotSame($share['url'], $copy['url']);

        $this->actingAs($this->a['admin'])->postJson("/api/v1/report-shares/{$share['id']}/archive")->assertOk();
        $this->open($share['url'])->assertNotFound();
        $this->open($copy['url'])->assertOk();
    }

    public function test_a_snapshot_stays_as_it_was_while_the_live_report_moves_on(): void
    {
        $share = $this->share();
        $snapshot = $this->actingAs($this->a['admin'])->postJson("/api/v1/report-shares/{$share['id']}/snapshot")->assertCreated()->json('data.share');
        $this->assertSame('snapshot', $snapshot['kind']);
        $this->assertNotEmpty($snapshot['snapshot']['generated_at']);
        $before = $this->open($snapshot['url'])->assertOk()->json('data');
        unset($before['workspace']);

        $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'Later', 'phone' => '0551234567']));

        $after = $this->open($snapshot['url'])->assertOk()->json('data');
        unset($after['workspace']);
        $this->assertSame($before, $after);

        $this->actingAs($this->a['admin'])->patchJson("/api/v1/report-shares/{$snapshot['id']}", ['days' => 7])->assertStatus(422);
        $this->actingAs($this->a['admin'])->patchJson("/api/v1/report-shares/{$snapshot['id']}", ['name' => 'Q3'])->assertOk();

        $raw = \DB::table('report_shares')->where('uuid', $snapshot['id'])->value('frozen_payload');
        $this->assertStringNotContainsString('generated_at', (string) $raw, 'Kept encrypted.');
    }

    public function test_someone_limited_to_a_project_cannot_act_on_another_projects_report(): void
    {
        [$mine, $other] = $this->asTenant($this->a['tenant'], fn () => [Project::create(['name' => 'Mine']), Project::create(['name' => 'Other'])]);
        $share = $this->share(['project_id' => $other->uuid]);
        $limited = $this->makeUser($this->a['tenant'], Role::Manager);
        $limited->forceFill(['all_projects' => false])->save();
        $limited->projects()->sync([$mine->id]);
        $this->app['auth']->forgetGuards();

        foreach (['pause', 'resume', 'archive', 'duplicate', 'snapshot'] as $action) {
            $this->actingAs($limited)->postJson("/api/v1/report-shares/{$share['id']}/{$action}")->assertNotFound();
        }

        $this->assertNull(ReportShare::withoutGlobalScopes()->where('uuid', $share['id'])->value('paused_at'));
    }

    public function test_the_funnel_walks_the_whole_road_and_counts_from_the_same_leads(): void
    {
        $this->asTenant($this->a['tenant'], function () {
            Lead::create(['full_name' => 'New', 'phone' => '0551000001']);
            Lead::create(['full_name' => 'Talking', 'phone' => '0551000002', 'status' => 'negotiation']);
        });

        $counts = $this->asTenant($this->a['tenant'], fn () => LeadFunnel::full(Lead::query()));

        $this->assertSame(['leads', 'contacted', 'replied', 'qualified', 'interested', 'appointment', 'visit', 'reservation', 'negotiation', 'won'], LeadFunnel::FULL_STEPS);
        $this->assertSame(2, $counts['leads']);
        $this->assertSame(1, $counts['contacted']);
        $this->assertSame(1, $counts['negotiation']);
        $this->assertSame(0, $counts['won']);
    }
}
