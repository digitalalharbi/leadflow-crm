<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_plans_page_lists_active_plans_and_never_invents_a_price(): void
    {
        $base = ['currency' => 'SAR', 'limits' => [], 'features' => []];
        Plan::query()->update(['is_active' => false]);
        Plan::create($base + ['key' => 't-starter', 'name' => 'Starter', 'is_active' => true, 'sort_order' => 1, 'price_monthly' => 199]);
        Plan::create($base + ['key' => 't-custom', 'name' => 'Custom', 'is_active' => true, 'sort_order' => 2]);
        Plan::create($base + ['key' => 't-hidden', 'name' => 'Hidden', 'is_active' => false, 'sort_order' => 3]);

        $plans = collect($this->getJson('/api/v1/public/plans')->assertOk()->json('data.plans'));

        $this->assertSame(['t-starter', 't-custom'], $plans->pluck('key')->all());
        $this->assertTrue($plans[0]['priced']);
        $this->assertFalse($plans[1]['priced']);
        $this->assertNull($plans[1]['price_monthly']);
    }
}
