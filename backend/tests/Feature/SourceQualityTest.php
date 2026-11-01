<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Where leads come from, ranked by how far they get — with or without a campaign behind them. */
class SourceQualityTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Source Co');
    }

    private function lead(array $attributes): Lead
    {
        $guarded = array_intersect_key($attributes, array_flip(['first_response_at', 'qualification_status']));

        return $this->asTenant($this->t['tenant'], function () use ($attributes, $guarded) {
            $lead = Lead::create(array_merge([
                'full_name' => 'عميل '.self::uniqueDigits(3), 'phone' => '0551234567',
                'phone_normalized' => '+9665512'.self::uniqueDigits(5), 'status' => 'new',
            ], array_diff_key($attributes, $guarded)));
            if ($guarded) {
                $lead->forceFill($guarded)->saveQuietly();
            }

            return $lead;
        });
    }

    private function rows(): array
    {
        return collect($this->actingAs($this->t['admin'])->getJson('/api/v1/reports/quality?level=source&days=30')->assertOk()->json('data.rows'))->keyBy('key')->all();
    }

    public function test_sources_are_ranked_without_needing_a_campaign(): void
    {
        // A sheet that brings serious buyers, and a webhook that brings noise.
        foreach (range(1, 4) as $i) {
            $this->lead(['platform' => 'google_sheets', 'status' => 'qualified', 'qualification_status' => 'qualified']);
        }
        $this->lead(['platform' => 'google_sheets', 'status' => 'won', 'deal_value_minor' => 100_000_000]);
        foreach (range(1, 4) as $i) {
            $this->lead(['platform' => 'webhook', 'status' => 'invalid']);
        }

        $rows = $this->rows();

        $this->assertSame(5, $rows['google_sheets']['leads']);
        $this->assertSame(4, $rows['webhook']['leads']);
        $this->assertGreaterThan($rows['webhook']['index'], $rows['google_sheets']['index']);
    }

    public function test_it_reports_every_stage_as_a_share_and_what_a_lead_earns(): void
    {
        $this->lead(['platform' => 'google_sheets', 'status' => 'won', 'deal_value_minor' => 100_000_000]);
        $this->lead(['platform' => 'google_sheets', 'status' => 'contacted']);
        $this->lead(['platform' => 'google_sheets', 'status' => 'invalid']);
        $this->lead(['platform' => 'google_sheets', 'status' => 'new', 'phone' => null, 'phone_normalized' => null]);

        $row = $this->rows()['google_sheets'];

        $this->assertEquals(75.0, $row['valid_rate'], 'one of four is invalid');
        $this->assertEquals(50.0, $row['contactable_rate'], 'two of four can be reached');
        $this->assertEquals(25.0, $row['won_rate']);
        $this->assertEquals(250000.0, $row['revenue_per_lead'], 'a million over four leads, not over the one that won');
    }

    public function test_a_lead_with_no_platform_is_counted_as_other(): void
    {
        $this->lead(['platform' => null]);

        $this->assertArrayHasKey('other', $this->rows());
    }

    public function test_the_existing_levels_are_unchanged(): void
    {
        $this->actingAs($this->t['admin'])->getJson('/api/v1/reports/quality?level=platform')->assertOk()->assertJsonPath('data.level', 'platform');
        $this->actingAs($this->t['admin'])->getJson('/api/v1/reports/quality?level=campaign')->assertOk()->assertJsonPath('data.level', 'campaign');
    }
}
