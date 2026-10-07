<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CampaignSpend;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a campaign cost, so the leads it produced can be priced.
 *
 * The reports have always counted leads, qualified leads and sales per campaign
 * and never known what any of it cost, so "which campaign is worth scaling"
 * could only be answered by volume — the question that flatters the cheapest
 * traffic. Most of what can go wrong here is a division that should not have
 * happened at all.
 */
class CampaignSpendTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Spend Co');
    }

    private function lead(string $campaign, array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+9665005'.random_int(10000, 99999),
            'status' => 'new',
            'platform' => 'meta',
            'campaign_name' => $campaign,
        ], $attributes)));
    }

    private function spend(string $campaign, float $amount, ?string $date = null): void
    {
        $this->asTenant($this->t['tenant'], fn () => CampaignSpend::create([
            'platform' => 'meta',
            'campaign_name' => $campaign,
            'spend_date' => $date ?? now()->toDateString(),
            'amount_minor' => (int) round($amount * 100),
            'currency' => 'SAR',
        ]));
    }

    private function campaignRow(string $campaign, array $query = []): ?array
    {
        $rows = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/reports/campaign-quality?'.http_build_query($query))
            ->assertOk()
            ->json('data.rows');

        return collect($rows)->firstWhere('campaign', $campaign);
    }

    public function test_it_prices_a_lead_and_a_qualified_lead(): void
    {
        $this->lead('حملة النرجس');
        $this->lead('حملة النرجس');
        $this->lead('حملة النرجس', ['status' => 'qualified']);
        $this->lead('حملة النرجس', ['status' => 'qualified']);

        $this->spend('حملة النرجس', 400);

        $row = $this->campaignRow('حملة النرجس');

        $this->assertEquals(400, $row['spend']);
        $this->assertEquals(100, $row['cost_per_lead']);        // 400 over 4
        $this->assertEquals(200, $row['cost_per_qualified']);   // 400 over 2
    }

    /**
     * A campaign nobody has costed is not free. A column of 0.00 beside a real
     * 41.20 invites exactly the wrong conclusion about which one to scale.
     */
    public function test_a_campaign_with_no_spend_entered_has_no_cost_rather_than_a_cost_of_zero(): void
    {
        $this->lead('حملة بلا تكلفة');

        $row = $this->campaignRow('حملة بلا تكلفة');

        $this->assertNull($row['spend']);
        $this->assertNull($row['cost_per_lead']);
        $this->assertNull($row['cost_per_qualified']);
    }

    /**
     * The cost of nothing is not a cost per unit. The whole budget is not what
     * one qualified lead cost when the campaign qualified nobody — the leads
     * column beside it is the finding.
     */
    public function test_a_campaign_that_qualified_nobody_has_no_cost_per_qualified(): void
    {
        $this->lead('حملة بلا تأهيل');
        $this->spend('حملة بلا تأهيل', 250);

        $row = $this->campaignRow('حملة بلا تأهيل');

        $this->assertEquals(250, $row['spend']);
        $this->assertEquals(250, $row['cost_per_lead']);
        $this->assertNull($row['cost_per_qualified']);
    }

    /**
     * A month of spend divided by a week of leads is not a number anybody
     * should see, so the report's own date filter applies to the day the money
     * was spent as well as to the day the lead arrived.
     */
    public function test_spend_outside_the_reported_period_is_not_counted(): void
    {
        $this->lead('حملة الفترة');
        $this->spend('حملة الفترة', 100, now()->toDateString());
        $this->spend('حملة الفترة', 900, now()->subDays(40)->toDateString());

        $row = $this->campaignRow('حملة الفترة', [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $this->assertEquals(100, $row['spend']);
    }

    /** Re-entering a day corrects it; it does not add to it. */
    public function test_entering_the_same_day_twice_corrects_rather_than_doubles(): void
    {
        $payload = fn (float $amount) => [
            'spends' => [[
                'platform' => 'meta',
                'campaign_name' => 'حملة التصحيح',
                'spend_date' => now()->toDateString(),
                'amount' => $amount,
            ]],
        ];

        $this->actingAs($this->t['admin'])->postJson('/api/v1/campaign-spend', $payload(300))->assertOk();
        $this->actingAs($this->t['admin'])->postJson('/api/v1/campaign-spend', $payload(450))->assertOk();

        $this->lead('حملة التصحيح');

        $this->assertEquals(450, $this->campaignRow('حملة التصحيح')['spend']);
        $this->assertSame(1, CampaignSpend::withoutGlobalScopes()->count());
    }

    /** Money is never a float in the database. */
    public function test_an_amount_is_stored_in_minor_units(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/campaign-spend', [
            'spends' => [[
                'platform' => 'meta', 'campaign_name' => 'حملة الهللات',
                'spend_date' => now()->toDateString(), 'amount' => 15.33,
            ]],
        ])->assertOk();

        $this->assertSame(1533, CampaignSpend::withoutGlobalScopes()->first()->amount_minor);
    }

    public function test_it_is_stored_in_the_workspaces_own_currency(): void
    {
        $this->t['tenant']->settings->forceFill(['currency' => 'AED'])->save();

        $this->actingAs($this->t['admin'])->postJson('/api/v1/campaign-spend', [
            'spends' => [[
                'platform' => 'meta', 'campaign_name' => 'حملة العملة',
                'spend_date' => now()->toDateString(), 'amount' => 10,
            ]],
        ])->assertOk();

        $this->assertSame('AED', CampaignSpend::withoutGlobalScopes()->first()->currency);
    }

    public function test_it_never_prices_a_campaign_with_another_workspaces_spend(): void
    {
        $this->lead('حملة مشتركة');
        $this->spend('حملة مشتركة', 100);

        $other = $this->provisionTenant('Somebody Else');
        $this->asTenant($other['tenant'], fn () => CampaignSpend::create([
            'platform' => 'meta', 'campaign_name' => 'حملة مشتركة',
            'spend_date' => now()->toDateString(), 'amount_minor' => 900000, 'currency' => 'SAR',
        ]));

        $this->assertEquals(100, $this->campaignRow('حملة مشتركة')['spend']);
    }

    public function test_a_sales_rep_may_not_enter_or_read_workspace_spend(): void
    {
        // Spend is a workspace-wide figure, so it takes the same authority the
        // reports and data-quality screens require.
        $rep = User::create([
            'tenant_id' => $this->t['tenant']->id,
            'name' => 'مندوب', 'email' => 'rep@spend.test',
            'password' => bcrypt('secret'), 'role' => Role::Sales->value, 'is_active' => true,
        ]);

        $this->actingAs($rep)
            ->getJson('/api/v1/campaign-spend')
            ->assertForbidden();

        $this->actingAs($rep)
            ->postJson('/api/v1/campaign-spend', [
                'spends' => [[
                    'platform' => 'meta', 'campaign_name' => 'x',
                    'spend_date' => now()->toDateString(), 'amount' => 1,
                ]],
            ])
            ->assertForbidden();
    }
}
