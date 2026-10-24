<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmailDelivery;
use App\Models\Lead;
use App\Models\Project;
use App\Support\ProjectContext;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What happens away from a request — summaries, notifications, queued jobs — keeps each company to itself too.
 */
class BackgroundIsolationTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeMailer();
        $this->a = $this->provisionTenant('Alpha Realty');
        $this->b = $this->provisionTenant('Beta Realty');

        foreach ([[$this->a, 'ALPHA'], [$this->b, 'BETAONLY']] as [$t, $tag]) {
            $this->asTenant($t['tenant'], function () use ($t, $tag) {
                $project = Project::create(['name' => "{$tag} Tower"]);
                foreach (range(1, 3) as $i) {
                    Lead::create(['full_name' => "{$tag} Lead {$i}", 'phone' => "05{$i}000000{$i}", 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'project_id' => $project->id, 'assigned_to' => $t['admin']->id]);
                }
            });
            $this->makeUser($t['tenant'], Role::Manager, ['email' => strtolower($tag).'.manager@example.test']);
        }
    }

    public function test_every_summary_is_written_from_its_own_companys_rows_only(): void
    {
        foreach (['daily', 'weekly', 'monthly'] as $kind) {
            DB::table('alert_marks')->where('key', 'like', 'digest:%')->delete();
            Artisan::call('leadflow:send-digests', ['--now' => true, '--kind' => $kind]);
        }

        $toA = EmailDelivery::withoutGlobalScopes()->where('tenant_id', $this->a['tenant']->id)->where('kind', 'like', 'digest.%')->get();
        $this->assertNotEmpty($toA);

        foreach ($toA as $delivery) {
            $this->assertStringNotContainsString('BETAONLY', $delivery->html);
            $this->assertStringNotContainsString('Beta Realty', $delivery->html);
            $this->assertNotSame($this->b['admin']->email, $delivery->to_email);
            $this->assertStringNotContainsString('betaonly.manager', $delivery->to_email);
        }

        // And a digest to a company is addressed only to that company's own people.
        $peopleOfB = DB::table('users')->where('tenant_id', $this->b['tenant']->id)->pluck('email')->all();
        $this->assertSame([], EmailDelivery::withoutGlobalScopes()->where('tenant_id', $this->a['tenant']->id)->whereIn('to_email', $peopleOfB)->pluck('to_email')->all());
    }

    public function test_a_closed_subscription_gets_no_summaries(): void
    {
        $this->b['tenant']->forceFill(['subscription_status' => 'suspended'])->save();

        Artisan::call('leadflow:send-digests', ['--now' => true, '--kind' => 'daily']);

        $this->assertSame(0, EmailDelivery::withoutGlobalScopes()->where('tenant_id', $this->b['tenant']->id)->where('kind', 'like', 'digest.%')->count());
        $this->assertGreaterThan(0, EmailDelivery::withoutGlobalScopes()->where('tenant_id', $this->a['tenant']->id)->where('kind', 'like', 'digest.%')->count());
    }

    public function test_a_notification_is_read_only_by_the_person_it_was_written_to(): void
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'App\\Notifications\\LeadAssigned', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $this->b['admin']->id,
            'data' => json_encode(['title' => 'BETAONLY lead arrived']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $list = $this->actingAs($this->a['admin'])->getJson('/api/v1/notifications')->assertOk()->getContent();
        $this->assertStringNotContainsString('BETAONLY', $list);
        $this->actingAs($this->a['admin'])->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
        $this->actingAs($this->a['admin'])->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_a_queued_job_never_inherits_the_previous_jobs_company(): void
    {
        // What a long-lived worker holds after one job, when the next one starts.
        app(TenantContext::class)->set($this->b['tenant']);
        app(ProjectContext::class)->restrictTo([1, 2, 3]);

        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        event(new JobProcessing('redis', $job));

        $this->assertNull(app(TenantContext::class)->id());
        $this->assertNull(app(ProjectContext::class)->ids());
    }
}
