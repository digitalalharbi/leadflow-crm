<?php

namespace Tests\Feature;

use App\Domain\Api\ApiKeys;
use App\Enums\LeadStatus;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A company's API keys open that company's data and nothing else: hashed at rest, shown once, scoped, revocable,
 * expiring, and no way across to another company — not even a 403 that confirms another company's lead exists.
 */
class PublicApiKeysTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha');
        $this->b = $this->provisionTenant('Beta');
    }

    private function issue(array $tenant, array $scopes, array $extra = []): string
    {
        return $this->actingAs($tenant['admin'])->postJson('/api/v1/api-keys', ['name' => 'CRM sync', 'scopes' => $scopes] + $extra)
            ->assertCreated()->json('data.plain');
    }

    private function api(string $key)
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$key);
    }

    private function lead(array $tenant, string $name, string $phone): Lead
    {
        return $this->asTenant($tenant['tenant'], fn () => Lead::create(['full_name' => $name, 'phone' => $phone, 'status' => LeadStatus::New]));
    }

    public function test_the_key_is_shown_once_and_only_its_hash_is_kept(): void
    {
        $plain = $this->issue($this->a, ['leads:read']);

        $this->assertStringStartsWith(ApiKeys::MARK, $plain);
        $row = ApiKey::withoutGlobalScopes()->first();
        $this->assertSame(hash('sha256', $plain), $row->key_hash);
        $this->assertStringNotContainsString($plain, json_encode(ApiKey::withoutGlobalScopes()->get()->toArray()));

        $list = $this->actingAs($this->a['admin'])->getJson('/api/v1/api-keys')->assertOk()->json('data');
        $this->assertStringNotContainsString(substr($plain, 13), json_encode($list));
        $this->assertSame('active', $list[0]['status']);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'api_key.created')->first();
        $this->assertStringNotContainsString(substr($plain, 13), json_encode($audit->changes));
    }

    public function test_a_key_reads_its_own_company_and_never_another(): void
    {
        $mine = $this->lead($this->a, 'Alpha Lead', '0500000001');
        $theirs = $this->lead($this->b, 'Beta Lead', '0500000002');
        $key = $this->issue($this->a, ['leads:read']);

        $names = collect($this->api($key)->getJson('/api/v1/public/leads')->assertOk()->json('data'))->pluck('full_name');
        $this->assertTrue($names->contains('Alpha Lead'));
        $this->assertFalse($names->contains('Beta Lead'));

        $this->api($key)->getJson("/api/v1/public/leads/{$mine->uuid}")->assertOk()->assertJsonPath('data.id', $mine->uuid);
        // Another company's lead is not there at all.
        $this->api($key)->getJson("/api/v1/public/leads/{$theirs->uuid}")->assertNotFound();

        // No internal ids leave.
        $this->assertArrayNotHasKey('tenant_id', $this->api($key)->getJson('/api/v1/public/leads')->json('data.0'));
    }

    public function test_scopes_are_enforced(): void
    {
        $key = $this->issue($this->a, ['projects:read']);

        $this->api($key)->getJson('/api/v1/public/projects')->assertOk();
        $this->api($key)->getJson('/api/v1/public/leads')->assertForbidden();
        $this->api($key)->postJson('/api/v1/public/leads', ['full_name' => 'X', 'phone' => '0500000009'])->assertForbidden();
    }

    public function test_a_lead_sent_in_goes_through_the_pipeline_and_lands_in_the_keys_company(): void
    {
        $project = $this->asTenant($this->a['tenant'], fn () => Project::create(['name' => 'Rawaq']));
        $key = $this->issue($this->a, ['leads:write']);

        $this->api($key)->postJson('/api/v1/public/leads', ['full_name' => 'Sara', 'phone' => '0551234567', 'project' => $project->uuid, 'external_id' => 'X-1'])
            ->assertCreated()->assertJsonPath('data.duplicate', false)->assertJsonPath('data.lead.project.id', $project->uuid);
        // The same lead again is a duplicate, not a second lead.
        $this->api($key)->postJson('/api/v1/public/leads', ['full_name' => 'Sara', 'phone' => '0551234567'])
            ->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, $this->asTenant($this->a['tenant'], fn () => Lead::where('phone', 'like', '%551234567')->count()));
        $this->assertSame(0, $this->asTenant($this->b['tenant'], fn () => Lead::count()));

        // A project of another company is refused, not silently ignored.
        $other = $this->asTenant($this->b['tenant'], fn () => Project::create(['name' => 'Other']));
        $this->api($key)->postJson('/api/v1/public/leads', ['full_name' => 'Ali', 'phone' => '0559999999', 'project' => $other->uuid])->assertStatus(422);
    }

    public function test_a_revoked_or_expired_key_opens_nothing(): void
    {
        $key = $this->issue($this->a, ['leads:read']);
        $uuid = ApiKey::withoutGlobalScopes()->value('uuid');

        $this->actingAs($this->a['admin'])->deleteJson("/api/v1/api-keys/{$uuid}")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->api($key)->getJson('/api/v1/public/leads')->assertUnauthorized();

        $expired = $this->issue($this->a, ['leads:read'], ['expires_in_days' => 1]);
        $this->travel(2)->days();
        $this->api($expired)->getJson('/api/v1/public/leads')->assertUnauthorized();

        $this->api('mht_nonsense_key')->getJson('/api/v1/public/leads')->assertUnauthorized();
        $this->api('')->getJson('/api/v1/public/leads')->assertUnauthorized();
    }

    public function test_made_up_keys_from_one_address_meet_a_limit(): void
    {
        // Each made-up key is new to the per-key limit; the API-wide per-address limit still stops the stream.
        foreach (range(1, 120) as $i) {
            $this->api("mht_guess{$i}_x")->getJson('/api/v1/public/leads')->assertUnauthorized();
        }

        $this->api('mht_guess121_x')->getJson('/api/v1/public/leads')->assertStatus(429);
    }

    public function test_one_company_cannot_revoke_anothers_key(): void
    {
        $this->issue($this->b, ['leads:read']);
        $uuid = ApiKey::withoutGlobalScopes()->value('uuid');

        $this->actingAs($this->a['admin'])->deleteJson("/api/v1/api-keys/{$uuid}")->assertNotFound();
        $this->assertNull(ApiKey::withoutGlobalScopes()->first()->revoked_at);
    }

    public function test_a_sales_rep_cannot_issue_keys(): void
    {
        $rep = $this->makeUser($this->a['tenant']);

        $this->actingAs($rep)->postJson('/api/v1/api-keys', ['name' => 'x', 'scopes' => ['leads:read']])->assertForbidden();
    }

    public function test_reading_needs_the_plan_but_a_lead_is_never_turned_away(): void
    {
        $key = $this->issue($this->a, ['leads:read', 'leads:write']);
        $this->putOnPlan($this->a['tenant'], 'starter', ['addons' => null]);
        Plan::where('key', 'starter')->update(['features' => []]);

        $this->api($key)->getJson('/api/v1/public/leads')->assertStatus(402);
        $this->api($key)->postJson('/api/v1/public/leads', ['full_name' => 'Noor', 'phone' => '0557777777'])->assertCreated();
    }

    public function test_revenue_without_a_recorded_deal_is_not_available_rather_than_zero(): void
    {
        $key = $this->issue($this->a, ['revenue:read']);

        $this->api($key)->getJson('/api/v1/public/revenue')->assertOk()->assertJsonPath('data.revenue_minor', null)->assertJsonPath('data.won_deals', 0);
    }

    public function test_last_use_is_recorded(): void
    {
        $key = $this->issue($this->a, ['projects:read']);
        $this->api($key)->getJson('/api/v1/public/projects')->assertOk();

        $this->assertNotNull(ApiKey::withoutGlobalScopes()->first()->last_used_at);
    }
}
