<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSignupTest extends TestCase
{
    use RefreshDatabase;

    private function body(array $over = []): array
    {
        return array_merge(['company' => 'Palm Developments', 'name' => 'Sara', 'email' => 'sara@palm-dev.sa', 'consent' => true], $over);
    }

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

    public function test_a_company_opens_a_trial_and_is_sent_a_link_not_a_password(): void
    {
        $this->postJson('/api/v1/public/signup', $this->body())->assertOk();

        $tenant = Tenant::where('slug', 'palm-developments')->firstOrFail();
        $this->assertSame('trialing', $tenant->subscription_status);
        $this->assertDatabaseHas('password_links', ['email' => 'sara@palm-dev.sa', 'purpose' => 'setup']);
        $this->assertNull(User::withoutGlobalScopes()->where('email', 'sara@palm-dev.sa')->value('email_verified_at'));
    }

    public function test_it_answers_the_same_for_a_known_address_and_creates_nothing(): void
    {
        $this->postJson('/api/v1/public/signup', $this->body())->assertOk();
        $before = Tenant::count();

        $again = $this->postJson('/api/v1/public/signup', $this->body(['company' => 'Another Co']));

        $again->assertOk();
        $this->assertSame($before, Tenant::count());
    }

    public function test_reserved_test_domains_bots_and_missing_consent_open_nothing(): void
    {
        $this->postJson('/api/v1/public/signup', $this->body(['email' => 'x@leadflow-demo.test']))->assertOk();
        $this->postJson('/api/v1/public/signup', $this->body(['website' => 'http://spam']))->assertStatus(422);
        $this->postJson('/api/v1/public/signup', $this->body(['consent' => false]))->assertStatus(422);

        $this->assertSame(0, Tenant::count());
    }

    public function test_it_can_be_switched_off(): void
    {
        config(['tenancy.self_signup' => false]);

        $this->postJson('/api/v1/public/signup', $this->body())->assertNotFound();
        $this->assertSame(0, Tenant::count());
    }
}
