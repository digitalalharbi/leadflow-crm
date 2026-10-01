<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The public ingestion door. These cover both halves of the contract: it must
 * accept real leads from anywhere, and it must refuse everything else.
 */
class LeadIngestionTest extends TestCase
{
    use RefreshDatabase;

    private array $t;
    private string $key;
    private string $secret = 'test-secret-0123456789abcdefghij';

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Inbound Realty');

        $endpoint = $this->asTenant($this->t['tenant'], fn () => WebhookEndpoint::first());
        $endpoint->forceFill(['secret_hash' => Hash::make($this->secret)])->saveQuietly();
        $this->key = $endpoint->key;
    }

    private function send(array $payload, ?string $secret = null, ?string $key = null)
    {
        return $this->withHeader('X-Webhook-Secret', $secret ?? $this->secret)
            ->postJson("/api/v1/integrations/webhooks/".($key ?? $this->key)."/leads", $payload);
    }

    public function test_a_webhook_creates_a_lead_and_maps_common_field_names(): void
    {
        $this->send([
            'name' => 'سعود الحربي',
            'mobile' => '0512345678',
            'city_name' => 'الرياض',
            'campaign' => 'حملة النرجس',
        ])->assertOk()->assertJsonPath('data.outcome', 'created');

        $lead = Lead::withoutGlobalScopes()->first();

        $this->assertSame('سعود الحربي', $lead->full_name);
        $this->assertSame('+966512345678', $lead->phone_normalized);
        $this->assertSame('الرياض', $lead->city);
        $this->assertSame('حملة النرجس', $lead->campaign_name);
        $this->assertSame($this->t['tenant']->id, $lead->tenant_id);
    }

    public function test_the_same_number_in_a_different_format_re_engages_the_existing_lead(): void
    {
        $this->send(['name' => 'سعود', 'phone' => '0512345678'])->assertOk();
        $this->send(['name' => 'سعود', 'phone' => '+966 51 234 5678', 'campaign' => 'حملة ثانية'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 're_engaged');

        $this->assertSame(1, Lead::withoutGlobalScopes()->count());

        $lead = Lead::withoutGlobalScopes()->first();
        $this->assertSame(1, $lead->re_engaged_count);

        // Both touches are preserved for attribution.
        $this->assertSame(2, $lead->sourceEvents()->withoutGlobalScopes()->count());
    }

    public function test_a_returning_lost_lead_is_reopened(): void
    {
        $this->send(['name' => 'سعود', 'phone' => '0512345678'])->assertOk();

        $lead = Lead::withoutGlobalScopes()->first();
        $lead->forceFill(['status' => 'lost', 'lost_at' => now()])->saveQuietly();

        $this->send(['name' => 'سعود', 'phone' => '0512345678'])->assertOk();

        $lead->refresh();
        $this->assertSame('new', $lead->status->value);
        $this->assertNull($lead->lost_at);
    }

    public function test_an_invalid_secret_is_rejected_without_revealing_anything(): void
    {
        $bad = $this->send(['phone' => '0512345678'], secret: 'wrong-secret');
        $unknown = $this->send(['phone' => '0512345678'], key: 'this-key-does-not-exist');

        $bad->assertStatus(401);
        $unknown->assertStatus(401);

        // Identical responses: an attacker cannot probe for valid endpoint keys.
        $this->assertSame($bad->json('message'), $unknown->json('message'));
        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    public function test_a_missing_secret_is_rejected(): void
    {
        $this->postJson("/api/v1/integrations/webhooks/{$this->key}/leads", ['phone' => '0512345678'])
            ->assertStatus(401);
    }

    public function test_a_payload_without_a_phone_or_email_is_rejected(): void
    {
        $this->send(['name' => 'No Contact'])->assertStatus(422);
        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    public function test_an_inactive_endpoint_stops_accepting_leads(): void
    {
        $this->asTenant($this->t['tenant'], fn () => WebhookEndpoint::first()->update(['is_active' => false]));

        $this->send(['phone' => '0512345678'])->assertStatus(401);
    }

    public function test_a_suspended_tenant_stops_accepting_leads(): void
    {
        $this->t['tenant']->update(['is_active' => false]);

        $this->send(['phone' => '0512345678'])->assertStatus(403);
    }

    public function test_every_attempt_is_logged(): void
    {
        $this->send(['phone' => '0512345678']);
        $this->send(['phone' => '0512345679'], secret: 'wrong');

        $logs = \App\Models\WebhookLog::withoutGlobalScopes()->get();

        $this->assertCount(2, $logs);
        $this->assertEqualsCanonicalizing(['accepted', 'rejected'], $logs->pluck('status')->all());
    }

    public function test_a_secret_sent_in_the_body_is_redacted_from_the_log(): void
    {
        $this->postJson("/api/v1/integrations/webhooks/{$this->key}/leads", [
            'phone' => '0512345678',
            'secret' => $this->secret,
            'api_token' => 'super-sensitive',
        ])->assertOk();

        $log = \App\Models\WebhookLog::withoutGlobalScopes()->first();

        $this->assertArrayNotHasKey('secret', $log->payload);
        $this->assertSame('[redacted]', $log->payload['api_token']);
    }

    public function test_an_oversized_payload_is_refused(): void
    {
        config(['integrations.webhooks.max_body_bytes' => 100]);

        $this->send(['phone' => '0512345678', 'notes' => str_repeat('x', 500)])
            ->assertStatus(413);
    }

    public function test_round_robin_distributes_leads_across_active_sales_users(): void
    {
        $this->t['tenant']->settings->update(['assignment_mode' => 'round_robin']);

        $reps = collect(range(1, 3))->map(fn () => $this->makeUser($this->t['tenant'], Role::Sales));

        foreach (range(1, 6) as $i) {
            $this->send(['name' => "Lead {$i}", 'phone' => '05100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)])
                ->assertOk();
        }

        $assignments = Lead::withoutGlobalScopes()->pluck('assigned_to');

        $this->assertCount(6, $assignments);
        $this->assertCount(0, $assignments->filter(fn ($a) => $a === null));

        // Each of the three reps should have received exactly two.
        $this->assertEqualsCanonicalizing(
            [2, 2, 2],
            $assignments->countBy()->values()->all(),
        );
    }

    public function test_manual_mode_leaves_leads_unassigned_for_a_manager_to_distribute(): void
    {
        $this->t['tenant']->settings->update(['assignment_mode' => 'manual']);
        $this->makeUser($this->t['tenant'], Role::Sales);

        $this->send(['name' => 'Unassigned', 'phone' => '0512345678'])->assertOk();

        $this->assertNull(Lead::withoutGlobalScopes()->first()->assigned_to);
    }

    public function test_an_inactive_rep_is_skipped_by_round_robin(): void
    {
        $this->t['tenant']->settings->update(['assignment_mode' => 'round_robin']);

        $active = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->makeUser($this->t['tenant'], Role::Sales, ['is_active' => false]);

        foreach (range(1, 3) as $i) {
            $this->send(['phone' => '05100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)])->assertOk();
        }

        // Only the active rep and the tenant admin's manager-level peers qualify.
        $this->assertSame(
            0,
            Lead::withoutGlobalScopes()->whereNotNull('assigned_to')
                ->whereNotIn('assigned_to', [$active->id])->count(),
        );
    }

    public function test_an_assigned_rep_is_notified(): void
    {
        $this->t['tenant']->settings->update(['assignment_mode' => 'round_robin']);
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->send(['name' => 'Notify Me', 'phone' => '0512345678'])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $rep->id,
            'type' => 'lead_assigned',
            'tenant_id' => $this->t['tenant']->id,
        ]);
    }

    public function test_a_meta_style_field_data_payload_is_understood(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::where('provider', 'generic_webhook')
            ->first()->update(['provider' => 'meta']));

        $this->send([
            'id' => 'meta-lead-99',
            'form_id' => 'form-1',
            'form_name' => 'نموذج النرجس',
            'campaign_name' => 'حملة ميتا',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['نورة الشمري']],
                ['name' => 'phone_number', 'values' => ['+966512345678']],
                ['name' => 'city', 'values' => ['جدة']],
            ],
        ])->assertOk()->assertJsonPath('data.outcome', 'created');

        $lead = Lead::withoutGlobalScopes()->first();

        $this->assertSame('نورة الشمري', $lead->full_name);
        $this->assertSame('+966512345678', $lead->phone_normalized);
        $this->assertSame('جدة', $lead->city);
        $this->assertSame('meta-lead-99', $lead->provider_lead_id);
    }

    public function test_a_tenant_field_mapping_is_applied(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::where('provider', 'generic_webhook')->first()
            ->update(['field_mapping' => [
                'اسم_العميل' => 'full_name',
                'رقم_الاتصال' => 'phone',
                'المنطقة' => 'city',
            ]]));

        $this->send([
            'اسم_العميل' => 'ماجد الدوسري',
            'رقم_الاتصال' => '0512345678',
            'المنطقة' => 'الدمام',
        ])->assertOk();

        $lead = Lead::withoutGlobalScopes()->first();

        $this->assertSame('ماجد الدوسري', $lead->full_name);
        $this->assertSame('+966512345678', $lead->phone_normalized);
        $this->assertSame('الدمام', $lead->city);
    }

    public function test_integration_defaults_fill_in_missing_fields(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::where('provider', 'generic_webhook')->first()
            ->update(['defaults' => ['platform' => 'tiktok', 'source' => 'landing_page']]));

        $this->send(['phone' => '0512345678', 'name' => 'Defaults'])->assertOk();

        $lead = Lead::withoutGlobalScopes()->first();

        $this->assertSame('tiktok', $lead->platform);
        $this->assertSame('landing_page', $lead->source);
    }

    public function test_the_endpoint_counters_are_updated(): void
    {
        $this->send(['phone' => '0512345678'])->assertOk();

        $endpoint = WebhookEndpoint::withoutGlobalScopes()->first();

        $this->assertSame(1, (int) $endpoint->received_count);
        $this->assertNotNull($endpoint->last_received_at);
    }
}
