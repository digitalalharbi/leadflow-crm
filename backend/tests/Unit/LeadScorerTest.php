<?php

namespace Tests\Unit;

use App\DataTransferObjects\IncomingLead;
use App\Enums\IntegrationProvider;
use App\Enums\LeadTemperature;
use App\Services\Leads\LeadScorer;
use PHPUnit\Framework\TestCase;

class LeadScorerTest extends TestCase
{
    public function test_a_bare_lead_scores_low_and_lands_cold(): void
    {
        $score = (new LeadScorer)->scoreIncoming(
            IncomingLead::fromArray(IntegrationProvider::GenericWebhook, ['phone' => '0512345678'])
        );

        $this->assertSame(40, $score);
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::fromScore($score));
    }

    public function test_a_complete_cash_buyer_scores_hot(): void
    {
        $score = (new LeadScorer)->scoreIncoming(
            IncomingLead::fromArray(IntegrationProvider::GenericWebhook, [
                'phone' => '0512345678',
                'email' => 'buyer@example.com',
                'city' => 'الرياض',
                'budget_max' => 2000000,
                'payment_method' => 'cash',
                'purchase_timeline' => 'immediate',
                'preferred_property_type' => 'فيلا',
            ])
        );

        $this->assertGreaterThanOrEqual(70, $score);
        $this->assertSame(LeadTemperature::Hot, LeadTemperature::fromScore($score));
    }

    public function test_the_score_is_capped_at_one_hundred(): void
    {
        $score = (new LeadScorer)->scoreIncoming(
            IncomingLead::fromArray(IntegrationProvider::GenericWebhook, [
                'phone' => '0512345678', 'email' => 'a@b.com', 'city' => 'جدة',
                'project_id' => 1, 'budget_min' => 1, 'budget_max' => 2,
                'payment_method' => 'cash', 'purchase_timeline' => 'immediate',
                'preferred_property_type' => 'شقة',
            ])
        );

        $this->assertLessThanOrEqual(100, $score);
    }

    public function test_temperature_thresholds_are_configurable(): void
    {
        // A tenant that treats 50+ as hot should see a 55 as hot, not warm.
        $this->assertSame(LeadTemperature::Hot, LeadTemperature::fromScore(55, warmAt: 30, hotAt: 50));
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::fromScore(55));
    }
}
