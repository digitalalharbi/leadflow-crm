<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Partner;
use App\Models\Project;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a partner is owed (a percentage of what each sale brought, or a fixed amount per sale), what
 * has been paid, what is left — and the statement they get, on a link only they hold.
 */
class PartnerCommissionTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Commission Co');
    }

    private function api()
    {
        return $this->actingAs($this->t['admin']);
    }

    private function partner(array $extra = []): array
    {
        return $this->api()->postJson('/api/v1/partners', $extra + ['name' => 'وسيط', 'type' => 'broker', 'commission_type' => 'percentage', 'commission_percent' => 2])->assertCreated()->json('data.partner');
    }

    /** Leads this partner's link brought; `won` of them closed at `value` each. */
    private function deals(array $partner, int $leads, int $won, int $valueMajor = 1_000_000): void
    {
        $this->asTenant($this->t['tenant'], function () use ($partner, $leads, $won, $valueMajor) {
            $row = Partner::where('uuid', $partner['id'])->first();
            $link = TrackingLink::create(['name' => 'رابط', 'channel' => 'broker', 'token' => 'tk'.random_int(100000, 999999), 'partner_id' => $row->id]);

            foreach (range(1, $leads) as $i) {
                $lead = Lead::create(['full_name' => "عميل {$i}", 'phone_normalized' => '+9665512'.self::uniqueDigits(5), 'status' => $i <= $won ? 'won' : 'contacted', 'deal_value_minor' => $i <= $won ? $valueMajor * 100 : null]);
                DB::table('leads')->where('id', $lead->id)->update(['first_touch_link_id' => $link->id, 'partner_id' => $row->id, 'won_at' => $i <= $won ? now()->subDays($i) : null]);
            }
        });
    }

    private function ledger(string $id): array
    {
        return collect($this->api()->getJson('/api/v1/partners')->assertOk()->json('data.partners'))->firstWhere('id', $id)['ledger'];
    }

    public function test_a_percentage_is_taken_of_what_each_sale_brought(): void
    {
        $partner = $this->partner(['commission_percent' => 2.5]);
        $this->deals($partner, 4, 2, 1_000_000);

        $ledger = $this->ledger($partner['id']);

        $this->assertSame(2, $ledger['deals']);
        $this->assertEquals(2_000_000, $ledger['revenue']);
        $this->assertEquals(50_000, $ledger['due'], '2.5% of 2,000,000');
        $this->assertEquals(0, $ledger['paid']);
        $this->assertEquals(50_000, $ledger['balance']);
    }

    public function test_a_fixed_amount_is_paid_per_sale_whatever_it_was_worth(): void
    {
        $partner = $this->partner(['commission_type' => 'fixed', 'commission_percent' => null, 'commission_fixed' => 7500]);
        $this->assertSame('fixed', $partner['commission_type']);
        $this->assertEquals(7500, $partner['commission_fixed']);

        $this->deals($partner, 3, 3, 400_000);

        $this->assertEquals(22_500, $this->ledger($partner['id'])['due'], '3 sales × 7,500');
    }

    public function test_without_terms_there_is_no_commission_not_a_zero(): void
    {
        $partner = $this->partner(['commission_percent' => null]);
        $this->deals($partner, 2, 2);

        $ledger = $this->ledger($partner['id']);

        $this->assertNull($ledger['due']);
        $this->assertNull($ledger['balance']);
        $this->assertSame(2, $ledger['deals']);
    }

    public function test_payments_reduce_the_balance_and_each_is_audited(): void
    {
        $partner = $this->partner(['commission_percent' => 5]);
        $this->deals($partner, 2, 2, 1_000_000);   // due 100,000

        $first = $this->api()->postJson("/api/v1/partners/{$partner['id']}/commission-payments", ['amount' => 30_000, 'reference' => 'TRF-1', 'paid_on' => now()->subDay()->toDateString()])->assertCreated()->json('data');
        $this->assertEquals(70_000, $first['ledger']['balance']);

        $this->api()->postJson("/api/v1/partners/{$partner['id']}/commission-payments", ['amount' => 20_000.5])->assertCreated();
        $this->assertEquals(49_999.5, $this->ledger($partner['id'])['balance']);
        $this->assertEquals(50_000.5, $this->ledger($partner['id'])['paid']);

        // Removing a payment puts it back.
        $removed = $this->api()->deleteJson("/api/v1/partners/{$partner['id']}/commission-payments/{$first['payment']['id']}")->assertOk()->json('data.ledger');
        $this->assertEquals(79_999.5, $removed['balance']);

        $actions = $this->asTenant($this->t['tenant'], fn () => AuditLog::where('action', 'like', 'partner.commission%')->orderBy('id')->pluck('action')->all());
        $this->assertSame(['partner.commission_paid', 'partner.commission_paid', 'partner.commission_payment_removed'], $actions);

        foreach ([['amount' => 0], ['amount' => -5], ['amount' => 'x'], ['amount' => 10, 'paid_on' => now()->addDay()->toDateString()]] as $bad) {
            $this->api()->postJson("/api/v1/partners/{$partner['id']}/commission-payments", $bad)->assertStatus(422);
        }
    }

    public function test_the_statement_lists_the_deals_and_payments_behind_the_numbers(): void
    {
        $partner = $this->partner(['commission_percent' => 2]);
        $this->deals($partner, 5, 3, 500_000);
        $this->api()->postJson("/api/v1/partners/{$partner['id']}/commission-payments", ['amount' => 10_000, 'reference' => 'TRF-9'])->assertCreated();

        $statement = $this->api()->getJson("/api/v1/partners/{$partner['id']}/statement")->assertOk()->json('data');

        $this->assertSame('percentage', $statement['terms']['type']);
        $this->assertSame(5, $statement['stats']['leads']);
        $this->assertSame(3, $statement['stats']['won']);
        $this->assertCount(3, $statement['deals']);
        $this->assertEquals(10_000, $statement['deals'][0]['commission'], '2% of 500,000');
        $this->assertEquals(30_000, $statement['ledger']['due']);
        $this->assertEquals(20_000, $statement['ledger']['balance']);
        $this->assertSame('TRF-9', $statement['commission_payments'][0]['reference']);
    }

    public function test_money_is_for_people_who_may_see_money(): void
    {
        $partner = $this->partner();
        $this->deals($partner, 2, 1);

        // A role the workspace built: manages partners, never sees revenue.
        $role = $this->api()->postJson('/api/v1/access/roles', ['name' => 'Partners No Money', 'base_role' => 'marketing_manager', 'grants' => ['dashboard' => ['view'], 'partners' => ['view', 'create', 'edit']]])->assertCreated()->json('data.role.id');
        $person = $this->makeUser($this->t['tenant'], Role::MarketingManager);
        $this->api()->patchJson("/api/v1/users/{$person->uuid}", ['access_role_id' => $role])->assertOk();
        $person = $person->fresh();

        $list = json_encode($this->actingAs($person)->getJson('/api/v1/partners')->assertOk()->json());
        $this->assertStringNotContainsString('"ledger"', $list);
        $this->assertStringNotContainsString('"commission"', $list);

        $this->actingAs($person)->getJson("/api/v1/partners/{$partner['id']}/statement")->assertForbidden();
        $this->actingAs($person)->postJson("/api/v1/partners/{$partner['id']}/commission-payments", ['amount' => 5])->assertForbidden();
        $this->actingAs($person)->postJson("/api/v1/partners/{$partner['id']}/statement-link")->assertForbidden();

        // And another workspace cannot reach it at all.
        $other = $this->provisionTenant('Rival');
        $this->actingAs($other['admin'])->getJson("/api/v1/partners/{$partner['id']}/statement")->assertNotFound();
    }

    public function test_the_partner_gets_a_statement_on_a_link_of_their_own(): void
    {
        $partner = $this->partner(['commission_percent' => 4, 'show_revenue' => false]);
        $other = $this->partner(['name' => 'منافس']);
        $this->deals($partner, 6, 2, 1_000_000);
        $this->deals($other, 3, 3, 9_999_000);
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'سري']));
        $this->asTenant($this->t['tenant'], function () {
            $campaign = Campaign::create(['platform' => 'meta', 'name' => 'حملة الشركة السرية', 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR']);
            MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $campaign->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 777_700, 'currency' => 'SAR']);
        });
        $this->api()->postJson("/api/v1/partners/{$partner['id']}/commission-payments", ['amount' => 20_000, 'reference' => 'TRF-1'])->assertCreated();

        $link = $this->api()->postJson("/api/v1/partners/{$partner['id']}/statement-link")->assertOk()->json('data.share');
        // Asking again is the same link, not a second door.
        $this->assertSame($link['url'], $this->api()->postJson("/api/v1/partners/{$partner['id']}/statement-link")->json('data.share.url'));

        // Anonymous: the link is the credential.
        $response = $this->getJson('/api/v1/shared/reports/'.basename($link['url']))->assertOk();
        $data = $response->json('data');
        $text = $response->getContent();

        $this->assertSame(['kpi_volume', 'statement', 'funnel', 'trend'], $data['sections']);
        $statement = $data['statement'];
        $this->assertSame('وسيط', $statement['partner']);
        $this->assertEquals(['type' => 'percentage', 'percent' => 4, 'fixed' => null, 'agreed' => true], $statement['terms']);
        $this->assertSame(6, $statement['funnel']['leads']);
        $this->assertSame(2, $statement['funnel']['won']);
        $this->assertEquals(80_000, $statement['commission']['due'], '4% of 2,000,000');
        $this->assertEquals(20_000, $statement['commission']['paid']);
        $this->assertEquals(60_000, $statement['commission']['balance']);
        $this->assertSame('TRF-1', $statement['payments'][0]['reference']);

        // Not shown unless the partner is allowed it: what the deals were worth, and revenue.
        $this->assertArrayNotHasKey('revenue', $statement);
        $this->assertArrayNotHasKey('value', $statement['deals'][0]);
        $this->assertNotNull($statement['deals'][0]['commission']);

        // Never: the company's spend, anybody else, any campaign, the team.
        foreach (['منافس', 'حملة الشركة السرية', 'سري', '777', '9999000', '9,999', '"spend"', '"team"', '"campaigns"', '"platforms"'] as $secret) {
            $this->assertStringNotContainsString($secret, $text, "{$secret} must not leave in a partner's statement");
        }

        // Live: a new payment is in the next opening.
        $this->api()->postJson("/api/v1/partners/{$partner['id']}/commission-payments", ['amount' => 10_000])->assertCreated();
        $this->assertEquals(50_000, $this->getJson('/api/v1/shared/reports/'.basename($link['url']))->json('data.statement.commission.balance'));

        // Allowed to see revenue: the deals carry their value and the statement its revenue.
        $this->api()->patchJson("/api/v1/partners/{$partner['id']}", ['show_revenue' => true])->assertOk();
        $shown = $this->getJson('/api/v1/shared/reports/'.basename($link['url']))->json('data.statement');
        $this->assertEquals(2_000_000, $shown['revenue']);
        $this->assertEquals(1_000_000, $shown['deals'][0]['value']);

        // Shut the door and it is gone.
        $this->api()->postJson("/api/v1/report-shares/{$link['id']}/revoke")->assertOk();
        $this->getJson('/api/v1/shared/reports/'.basename($link['url']))->assertNotFound();
    }

    public function test_a_statement_means_nothing_outside_a_partners_own_report(): void
    {
        $share = $this->api()->postJson('/api/v1/report-shares', ['name' => 'عام', 'template' => 'management'])->assertCreated()->json('data.share');

        $data = $this->getJson('/api/v1/shared/reports/'.basename($share['url']))->assertOk()->json('data');

        $this->assertNotContains('statement', $data['sections']);
        $this->assertArrayNotHasKey('statement', $data);
    }

    public function test_a_fixed_amount_and_a_percentage_are_both_checked(): void
    {
        $this->api()->postJson('/api/v1/partners', ['name' => 'x', 'type' => 'broker', 'commission_type' => 'wizard'])->assertStatus(422);
        $this->api()->postJson('/api/v1/partners', ['name' => 'x', 'type' => 'broker', 'commission_percent' => 101])->assertStatus(422);
        $this->api()->postJson('/api/v1/partners', ['name' => 'x', 'type' => 'broker', 'commission_type' => 'fixed', 'commission_fixed' => -1])->assertStatus(422);
    }
}
