<?php

namespace Tests\Feature;

use App\Domain\Agents\LeadsAgent;
use App\Domain\Agents\Orchestrator;
use App\Models\Campaign;
use App\Models\Lead;
use App\Services\Leads\LeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Which campaigns bring real people — measured, fed back into scores, and announced when it slips. */
class LeadQualityFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Quality Co');
    }

    private function campaignWith(array $statuses, array $attributes = []): Campaign
    {
        return $this->asTenant($this->t['tenant'], function () use ($statuses, $attributes) {
            $campaign = Campaign::create(array_merge(['platform' => 'meta', 'name' => 'حملة '.(++$this->n)], $attributes));

            foreach ($statuses as $i => $status) {
                $lead = Lead::create(['full_name' => 'ع', 'phone_normalized' => '+9665'.str_pad((string) ($this->n * 1000 + $i), 8, '0', STR_PAD_LEFT), 'status' => $status]);
                DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $campaign->id]);
            }

            return $campaign;
        });
    }

    public function test_the_score_reads_forms_versus_customers(): void
    {
        $base = ['leads' => 10, 'valid' => 10, 'engaged' => 0, 'qualified' => 0, 'appointments' => 0, 'won' => 0];

        $forms = LeadsAgent::score($base);
        $customers = LeadsAgent::score(['leads' => 10, 'valid' => 10, 'engaged' => 10, 'qualified' => 5, 'appointments' => 2, 'won' => 1]);
        $junk = LeadsAgent::score(['valid' => 2] + $base);

        $this->assertSame(15, $forms);
        $this->assertSame(100, $customers);
        $this->assertLessThan($forms, $junk);
    }

    public function test_the_review_scores_campaigns_with_enough_leads_and_alerts_on_a_fall(): void
    {
        $good = $this->campaignWith(array_merge(array_fill(0, 6, 'qualified'), array_fill(0, 6, 'new')), ['quality_score' => 95]);
        $thin = $this->campaignWith(['qualified', 'new']);

        $this->asTenant($this->t['tenant'], fn () => app(Orchestrator::class)->run('lead_quality_review'));

        $this->asTenant($this->t['tenant'], function () use ($good, $thin) {
            $good->refresh();
            $this->assertSame(95, $good->quality_previous);
            $this->assertLessThan(95 - LeadsAgent::DROP_ALERT + 1, $good->quality_score);
            $this->assertNull($thin->fresh()->quality_score, 'A campaign with two leads was judged.');
        });

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->t['admin']->id]);
    }

    public function test_a_lead_from_a_campaign_with_real_leads_scores_higher_and_says_why(): void
    {
        $strong = $this->campaignWith([], ['quality_score' => 80]);
        $weak = $this->campaignWith([], ['quality_score' => 10]);

        [$a, $b, $c] = $this->asTenant($this->t['tenant'], function () use ($strong, $weak) {
            $make = function (?Campaign $campaign, string $phone) {
                $lead = Lead::create(['full_name' => 'ع', 'phone_normalized' => $phone, 'status' => 'new']);
                DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $campaign?->id]);

                return app(LeadScoringService::class)->evaluate($lead->fresh());
            };

            return [$make($strong, '+966500777001'), $make($weak, '+966500777002'), $make(null, '+966500777003')];
        });

        $this->assertSame($c['score'] + 5, $a['score']);
        $this->assertContains('campaign_quality_high', array_column($a['reasons'], 'key'));
        $this->assertContains('campaign_quality_low', array_column($b['reasons'], 'key'));
        $this->assertNotContains('campaign_quality_high', array_column($c['reasons'], 'key'));
    }
}
