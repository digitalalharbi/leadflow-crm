<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Project;
use App\Models\User;
use App\Services\Leads\LeadAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Who receives it, and why." Every rule that narrowed the choice is written onto the lead's
 * story when it is assigned, and the rep sees it on the card.
 */
class SmartAssignmentWhyTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Why Co');
        $this->t['tenant']->settings->forceFill(['assignment_mode' => 'round_robin', 'assignment_strategy' => 'workload'])->save();
        // Not the admin: only reps and managers are in the pool, and the admin would join it.
        $this->t['admin']->forceFill(['role' => 'admin'])->save();
    }

    private function rep(array $attributes = []): User
    {
        return tap($this->makeUser($this->t['tenant']), fn (User $u) => $u->forceFill($attributes)->save())->fresh();
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create($attributes + [
            'full_name' => 'عميل', 'phone_normalized' => '+96650020'.random_int(1000, 9999), 'status' => 'new',
        ]));
    }

    private function assignNew(Lead $lead): ?User
    {
        return $this->asTenant($this->t['tenant'], function () use ($lead) {
            $service = app(LeadAssignmentService::class);
            $rep = $service->pickAssignee($this->t['tenant'], $lead);
            $rep && $service->assign($lead, $rep);

            return $rep;
        });
    }

    private function why(Lead $lead): ?array
    {
        return $this->asTenant($this->t['tenant'], fn () => LeadActivity::where('lead_id', $lead->id)->where('type', 'assigned')->latest('id')->first()?->meta['why'] ?? null);
    }

    public function test_the_rules_that_applied_are_written_down(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $mine = $this->rep(['covers_cities' => ['الرياض']]);
        $jeddah = $this->rep(['covers_cities' => ['جدة']]);
        $this->asTenant($this->t['tenant'], function () use ($mine, $jeddah, $project) {
            $mine->projects()->attach($project->id);
            $jeddah->projects()->attach($project->id);
        });
        $this->rep(['covers_cities' => ['الرياض']]);

        $lead = $this->lead(['project_id' => $project->id, 'city' => 'الرياض']);
        $this->assertSame($mine->id, $this->assignNew($lead)->id);

        $why = $this->why($lead);
        $this->assertSame(['project', 'region'], $why['rules']);
        $this->assertSame('workload', $why['strategy']);
        $this->assertSame(1, $why['candidates']);
    }

    public function test_the_kind_of_deal_is_a_rule(): void
    {
        $rent = $this->rep(['handles' => ['rent']]);
        $this->rep(['handles' => ['buy']]);

        $lead = $this->lead(['transaction_type' => 'rent']);

        $this->assertSame($rent->id, $this->assignNew($lead)->id);
        $this->assertSame(['type'], $this->why($lead)['rules']);
    }

    public function test_no_opinion_means_no_rules_and_the_least_loaded_rep(): void
    {
        $busy = $this->rep();
        $free = $this->rep();
        $this->asTenant($this->t['tenant'], fn () => collect(range(1, 3))->each(fn () => $this->lead(['assigned_to' => $busy->id])));

        $lead = $this->lead();

        $this->assertSame($free->id, $this->assignNew($lead)->id);
        $this->assertSame([], $this->why($lead)['rules']);
    }

    public function test_the_performance_strategy_prefers_the_closer_who_is_not_buried(): void
    {
        $this->t['tenant']->settings->forceFill(['assignment_strategy' => 'performance'])->save();
        $closer = $this->rep();
        $browser = $this->rep();

        // Last quarter: the closer won 3 of 4, the other lost 4 of 4.
        $this->asTenant($this->t['tenant'], function () use ($closer, $browser) {
            foreach (['won', 'won', 'won', 'lost'] as $status) {
                $this->lead(['assigned_to' => $closer->id, 'status' => $status]);
            }
            foreach (range(1, 4) as $_) {
                $this->lead(['assigned_to' => $browser->id, 'status' => 'lost']);
            }
        });

        $lead = $this->lead();

        $this->assertSame($closer->id, $this->assignNew($lead)->id);
        $this->assertSame('performance', $this->why($lead)['strategy']);

        // Buried: nine open leads more than the other rep and the lead goes to the one with room.
        $this->asTenant($this->t['tenant'], fn () => collect(range(1, 9))->each(fn () => $this->lead(['assigned_to' => $closer->id])));
        $second = $this->lead();
        $this->assertSame($browser->id, $this->assignNew($second)->id);
    }

    public function test_the_rep_sees_why_it_reached_them_and_the_manager_sees_who_is_late(): void
    {
        $rep = $this->rep(['handles' => ['buy']]);
        $this->rep(['handles' => ['rent']]);
        $lead = $this->lead(['transaction_type' => 'buy']);
        $this->assignNew($lead);

        DB::table('leads')->where('id', $lead->id)->update(['interest_level' => 'hot', 'interest_level_changed_at' => now()->subHour(), 'created_at' => now()->subDay()]);

        $card = $this->actingAs($rep)->getJson('/api/v1/work/next')->assertOk()->json('data');
        $this->assertSame(['type'], $card['assignment']['rules']);
        $this->assertSame('breached', $card['sla']['state']);

        $manager = $this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard/manager?days=7')->assertOk()->json('data');
        $this->assertSame(1, $manager['interest']['levels']['hot']);
        $this->assertSame(1, $manager['interest']['late']);
        $row = collect($manager['team'])->firstWhere('id', $rep->uuid);
        $this->assertSame(1, $row['interested']);
        $this->assertSame(1, $row['sla_late']);
        $this->assertArrayNotHasKey('by_rep', $manager['interest']);
    }

    public function test_only_a_manager_can_say_what_a_rep_handles(): void
    {
        $rep = $this->rep();

        $this->actingAs($rep)->patchJson("/api/v1/users/{$rep->uuid}", ['handles' => ['buy']])->assertStatus(403);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$rep->uuid}", ['handles' => ['buy']])->assertOk()->assertJsonPath('data.user.handles', ['buy']);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$rep->uuid}", ['handles' => ['villa']])->assertStatus(422);
    }
}
