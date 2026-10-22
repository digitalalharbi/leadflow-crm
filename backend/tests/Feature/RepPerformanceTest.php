<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Per-rep intelligence — and a ranking that judges each rep by what they did with the leads they
 * were given, not by who got the most.
 */
class RepPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Reps Co');
    }

    private function rep(string $name): User
    {
        return $this->makeUser($this->t['tenant'], Role::Sales, ['name' => $name]);
    }

    /** @param  array<string,mixed>  $extra  */
    private function leadsFor(User $rep, int $count, int $won, array $extra = []): void
    {
        $this->asTenant($this->t['tenant'], function () use ($rep, $count, $won, $extra) {
            foreach (range(1, $count) as $i) {
                $lead = Lead::create(['full_name' => "{$rep->name} {$i}", 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => $i <= $won ? 'won' : 'contacted', 'assigned_to' => $rep->id, 'deal_value_minor' => $i <= $won ? 100_000_000 : null]);
                DB::table('leads')->where('id', $lead->id)->update($extra + [
                    'created_at' => now()->subDays(2), 'assigned_at' => now()->subDays(2),
                    'first_contact_at' => now()->subDays(2)->addMinutes(10), 'last_contact_at' => now()->subDays(1),
                ]);
            }
        });
    }

    private function report(array $query = [], $as = null): array
    {
        return $this->actingAs($as ?? $this->t['admin'])->getJson('/api/v1/reports/sales-performance?'.http_build_query($query))->assertOk()->json('data');
    }

    public function test_each_rep_has_the_rates_a_manager_reads_and_the_team_has_the_same(): void
    {
        $fast = $this->rep('سريع');
        $this->leadsFor($fast, 10, 2, ['first_contact_at' => now()->subDays(2)->addMinutes(5)]);

        $data = $this->report();
        $row = collect($data['reps'])->firstWhere('name', 'سريع');

        $this->assertSame(10, $row['leads']);
        $this->assertSame(5, $row['response_minutes']);
        $this->assertEquals(100, $row['contact_rate']);
        $this->assertEquals(100, $row['sla_compliance'], 'five minutes is inside the thirty-minute window');
        $this->assertEquals(20, $row['close_rate']);
        $this->assertEquals(2, $row['won']);
        $this->assertEquals(2000000, $row['revenue']);
        foreach (['interested_rate', 'visit_rate', 'reservation_rate', 'follow_up_completion', 'volume_share', 'won_vs_expected', 'rank', 'small_sample'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertEquals(20, $data['team']['close_rate']);
        $this->assertSame(30, $data['team']['sla_target_minutes']);
    }

    public function test_the_ranking_is_by_what_a_rep_did_with_their_leads_not_by_how_many_they_had(): void
    {
        $steady = $this->rep('ثابت');       // 20 leads, 6 won
        $buried = $this->rep('غارق');       // 20 leads, 2 won
        $lucky = $this->rep('قليل الحظ');   // 4 leads, 3 won: a great rate, on too little to judge
        $this->leadsFor($steady, 20, 6);
        $this->leadsFor($buried, 20, 2);
        $this->leadsFor($lucky, 4, 3);

        $reps = collect($this->report()['reps']);

        $this->assertSame(['ثابت', 'غارق', 'قليل الحظ'], $reps->pluck('name')->all(), 'those with enough leads to judge come first');
        $this->assertSame([1, 2, 3], $reps->pluck('rank')->all());

        // Team: 11 won of 44 = 25%. 20 leads would make 5: steady is 1 above, buried 3 below.
        $byName = $reps->keyBy('name');
        $this->assertEquals(1, $byName['ثابت']['won_vs_expected']);
        $this->assertEquals(-3, $byName['غارق']['won_vs_expected']);
        $this->assertTrue($byName['قليل الحظ']['small_sample']);
        $this->assertFalse($byName['ثابت']['small_sample']);

        // And the volume each carried is part of the picture.
        $this->assertEquals(45.5, $byName['ثابت']['volume_share']);
        $this->assertEquals(9.1, $byName['قليل الحظ']['volume_share']);
    }

    public function test_slow_answers_and_unfinished_follow_ups_show(): void
    {
        $slow = $this->rep('بطيء');
        $this->leadsFor($slow, 6, 0, ['first_contact_at' => now()->subDays(2)->addHours(5)]);
        $this->asTenant($this->t['tenant'], function () use ($slow) {
            $lead = Lead::where('assigned_to', $slow->id)->first();

            foreach (['completed', 'pending', 'pending', 'pending'] as $i => $status) {
                FollowUp::create(['lead_id' => $lead->id, 'assigned_to' => $slow->id, 'due_at' => now()->subHours(3 + $i), 'status' => $status]);
            }
        });

        $row = collect($this->report()['reps'])->firstWhere('name', 'بطيء');

        $this->assertSame(300, $row['response_minutes']);
        $this->assertEquals(0, $row['sla_compliance'], 'five hours is outside the window');
        $this->assertEquals(25, $row['follow_up_completion'], 'one of four came due and got done');
    }

    public function test_the_period_and_the_project_narrow_it(): void
    {
        $rep = $this->rep('مدة');
        $this->leadsFor($rep, 5, 1);
        $this->leadsFor($rep, 5, 0, ['created_at' => now()->subDays(60), 'assigned_at' => now()->subDays(60), 'first_contact_at' => now()->subDays(60)->addMinutes(3)]);

        $this->assertSame(5, collect($this->report(['days' => 30])['reps'])->firstWhere('name', 'مدة')['leads']);
        $this->assertSame(10, collect($this->report(['days' => 90])['reps'])->firstWhere('name', 'مدة')['leads']);
    }

    public function test_money_is_only_for_those_who_may_see_it_and_only_the_team_managers_may_look(): void
    {
        $rep = $this->rep('مالي');
        $this->leadsFor($rep, 4, 2);

        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $this->assertArrayHasKey('revenue', collect($this->report([], $manager)['reps'])->first());

        // A role the workspace built that reads reports but never revenue sees the team without the money.
        $role = $this->actingAs($this->t['admin'])->postJson('/api/v1/access/roles', ['name' => 'Team No Money', 'base_role' => 'sales_manager', 'grants' => ['dashboard' => ['view'], 'analytics' => ['view'], 'leads' => ['view']]])->assertCreated()->json('data.role.id');
        $person = $this->makeUser($this->t['tenant'], Role::SalesManager);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$person->uuid}", ['access_role_id' => $role])->assertOk();
        $seen = $this->actingAs($person->fresh())->getJson('/api/v1/reports/sales-performance')->assertOk()->json('data.reps.0');
        $this->assertArrayNotHasKey('revenue', $seen);
        $this->assertArrayHasKey('close_rate', $seen);

        // A rep may not look at the team at all, nor may somebody from outside the company.
        $this->actingAs($rep)->getJson('/api/v1/reports/sales-performance')->assertForbidden();
        $this->actingAs($this->makeUser($this->t['tenant'], Role::BrokerViewer))->getJson('/api/v1/reports/sales-performance')->assertForbidden();
        $this->actingAs($this->makeUser($this->t['tenant'], Role::ClientViewer))->getJson('/api/v1/reports/sales-performance')->assertForbidden();

        $other = $this->provisionTenant('Else');
        $this->assertSame([], $this->report([], $other['admin'])['reps']);
    }
}
