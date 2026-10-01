<?php

namespace Tests\Unit;

use App\Enums\LeadTemperature;
use PHPUnit\Framework\TestCase;

/**
 * The scoring bands and the shape of the rule set, without touching a database.
 * Behaviour against real leads lives in QualificationTest.
 */
class LeadScoringServiceTest extends TestCase
{
    public function test_the_documented_bands_hold(): void
    {
        $this->assertSame(LeadTemperature::Cold, LeadTemperature::fromScore(0));
        $this->assertSame(LeadTemperature::Cold, LeadTemperature::fromScore(39));
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::fromScore(40));
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::fromScore(69));
        $this->assertSame(LeadTemperature::Hot, LeadTemperature::fromScore(70));
        $this->assertSame(LeadTemperature::Hot, LeadTemperature::fromScore(100));
    }

    public function test_temperature_thresholds_are_configurable(): void
    {
        // A tenant that treats 50+ as hot should see a 55 as hot, not warm.
        $this->assertSame(LeadTemperature::Hot, LeadTemperature::fromScore(55, warmAt: 30, hotAt: 50));
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::fromScore(55));
    }

    public function test_the_headline_rules_match_the_documented_weights(): void
    {
        // These numbers are a commercial promise as much as a technical one:
        // they are what the product tells a sales manager it rewards.
        $weights = require __DIR__.'/../../config/scoring.php';

        $this->assertSame(25, $weights['weights']['budget_matched']);
        $this->assertSame(20, $weights['weights']['timeline_within_3_months']);
        $this->assertSame(15, $weights['weights']['financing_approved']);
        $this->assertSame(25, $weights['weights']['wants_appointment']);
        $this->assertSame(10, $weights['weights']['replied_on_whatsapp']);
        $this->assertSame(-30, $weights['weights']['budget_mismatch']);
        $this->assertSame(-100, $weights['weights']['wrong_number']);
    }

    public function test_no_rule_keys_on_who_the_person_is(): void
    {
        // A weight named for a nationality, a language or an origin would be
        // discrimination with a number attached. Failing here is the point.
        $config = require __DIR__.'/../../config/scoring.php';

        $keys = array_merge(
            array_keys($config['weights']),
            array_keys($config['secondary']),
        );

        foreach ($keys as $key) {
            foreach (['nationality', 'origin', 'language', 'gender', 'age', 'name', 'country', 'religion'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $key);
            }
        }
    }
}
