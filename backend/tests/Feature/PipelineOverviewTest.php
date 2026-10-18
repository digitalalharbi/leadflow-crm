<?php

namespace Tests\Feature;

use App\Domain\Leads\LeadClassification;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PipelineOverviewTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function lead(array $attributes = []): Lead
    {
        $this->n++;

        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $lead = Lead::create(array_merge([
            'full_name' => 'عميل '.$this->n,
            'phone_normalized' => '+9665'.str_pad((string) (10000000 + $this->n), 8, '0', STR_PAD_LEFT),
            'status' => 'new',
        ], $attributes));

        if ($createdAt) {
            $lead->forceFill(['created_at' => $createdAt])->save();
        }

        return $lead;
    }

    public function test_the_overview_counts_stages_funnel_health_and_workload(): void
    {
        $t = $this->provisionTenant('Overview Co');

        $this->asTenant($t['tenant'], function () use ($t) {
            $this->lead();
            $this->lead(['status' => 'contacted', 'first_contact_at' => now()->subHour(), 'created_at' => now()->subHours(3)]);
            $this->lead(['status' => 'qualified', 'temperature' => 'hot', 'first_contact_at' => now(), 'budget_max' => 900000, 'assigned_to' => $t['admin']->id]);
            $this->lead(['status' => 'appointment', 'first_contact_at' => now(), 'budget_max' => 100000]);
            $this->lead(['status' => 'won', 'first_contact_at' => now(), 'deal_value_minor' => 250000]);
            $this->lead(['status' => 'lost']);
            // Stalled: open, last touched a week ago, nothing scheduled.
            $this->lead(['status' => 'contacted', 'first_contact_at' => now()->subDays(8), 'last_contact_at' => now()->subDays(8)]);
        });

        $data = $this->actingAs($t['admin'])->getJson('/api/v1/pipeline/overview')->assertOk()->json('data');

        $this->assertSame(7, $data['health']['total']);
        $this->assertSame(5, $data['health']['open']);
        $this->assertSame(1, $data['health']['hot']);
        $this->assertSame(1, $data['health']['stalled']);
        $this->assertSame(1000000.0, (float) $data['health']['open_value']);
        $this->assertSame(2500.0, (float) $data['health']['won_value']);
        $this->assertNotNull($data['health']['avg_response_seconds']);

        $funnel = collect($data['funnel'])->keyBy('key');
        $this->assertSame(7, $funnel['new']['count']);
        $this->assertSame(6, $funnel['contacted']['count']);
        $this->assertSame(3, $funnel['qualified']['count']);
        $this->assertSame(2, $funnel['appointment']['count']);
        $this->assertSame(1, $funnel['won']['count']);

        // A funnel only narrows.
        $counts = array_column($data['funnel'], 'count');
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts);

        $stages = collect($data['stages'])->keyBy('status');
        $this->assertSame(2, $stages['contacted']['total']);
        $this->assertSame(1, $stages['lost']['total']);
        $this->assertCount(8, $data['stages']);

        $this->assertCount(1, $data['stalled']);
        $this->assertSame(1, $data['hot'] ? count($data['hot']) : 0);
        $this->assertSame(array_sum(array_column($data['classification'], 'count')), $data['health']['open']);
        $this->assertNotEmpty($data['workload']);
    }

    public function test_a_rep_only_sees_the_pipeline_of_the_leads_they_may_see(): void
    {
        $t = $this->provisionTenant('Scoped Co');
        $rep = $this->makeUser($t['tenant']);

        $this->asTenant($t['tenant'], function () use ($rep) {
            $this->lead(['assigned_to' => $rep->id]);
            $this->lead();
            $this->lead();
        });

        $this->assertSame(1, $this->actingAs($rep)->getJson('/api/v1/pipeline/overview')->assertOk()->json('data.health.total'));
        $this->assertSame(3, $this->actingAs($t['admin'])->getJson('/api/v1/pipeline/overview')->assertOk()->json('data.health.total'));
    }

    public function test_php_and_sql_classification_agree_for_every_shape_of_lead(): void
    {
        $t = $this->provisionTenant('Classify Co');

        $shapes = [
            ['status' => 'invalid'],
            ['status' => 'contacted', 'wrong_fit' => true],
            ['status' => 'qualified'],
            ['status' => 'contacted', 'qualification_status' => 'qualified'],
            ['status' => 'contacted', 'temperature' => 'hot', 'appointment_interest' => true],
            ['status' => 'replied', 'temperature' => 'hot', 'first_response_at' => now()],
            ['status' => 'contacted', 'temperature' => 'hot'],
            ['status' => 'contacted', 'temperature' => 'warm'],
            ['status' => 'new', 'temperature' => 'cold'],
            ['status' => 'contacted', 'temperature' => 'cold', 'first_contact_at' => now()],
            ['status' => 'new', 'temperature' => 'cold', 'first_contact_at' => now()],
            ['status' => 'won'],
            ['status' => 'contacted', 'temperature' => 'cold', 'is_priority' => true],
            ['status' => 'lost', 'temperature' => 'warm'],
        ];

        $this->asTenant($t['tenant'], function () use ($shapes) {
            foreach ($shapes as $shape) {
                $flags = array_intersect_key($shape, ['is_priority' => 1, 'wrong_fit' => 1]);
                $lead = $this->lead(array_diff_key($shape, $flags));
                if ($flags) {
                    $lead->forceFill($flags)->save();
                }
                $php = LeadClassification::for($lead->fresh())['key'];
                $sql = DB::table('leads')->where('id', $lead->id)
                    ->selectRaw(LeadClassification::sql().' as class')->value('class');

                $this->assertSame($php, $sql, 'PHP and SQL classify '.json_encode($shape).' differently.');
            }
        });
    }

    public function test_leads_can_be_filtered_by_classification_and_carry_the_reason(): void
    {
        $t = $this->provisionTenant('Filter Co');

        $this->asTenant($t['tenant'], function () {
            $hot = $this->lead(['appointment_interest' => true]);
            $warm = $this->lead();
            $this->lead(['status' => 'invalid']);
            // Written directly: creating a lead scores it, and this test is about
            // the filter, not about what the score would have been.
            DB::table('leads')->where('id', $hot->id)->update(['temperature' => 'hot']);
            DB::table('leads')->where('id', $warm->id)->update(['temperature' => 'warm']);
        });

        $res = $this->actingAs($t['admin'])->getJson('/api/v1/leads?classification[]=priority')->assertOk();
        $rows = $res->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame(['key' => 'priority', 'reason' => 'hot_wants_appointment'], $rows[0]['classification']);

        $this->actingAs($t['admin'])->getJson('/api/v1/leads?classification[]=nonsense')->assertOk()->assertJsonCount(0, 'data');
    }
}
