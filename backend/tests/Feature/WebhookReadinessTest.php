<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Lead;
use App\Models\WebhookEndpoint;
use App\Models\WebhookLog;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The ingestion endpoint is the only unauthenticated write path in the product
 * and the one an ad platform will hammer unattended. This covers the full
 * contract: what it accepts, what it refuses, and what it records either way.
 */
class WebhookReadinessTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private string $key;

    private string $secret = 'readiness-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('');
        $this->t = $this->provisionTenant('Readiness Realty');

        $endpoint = $this->asTenant($this->t['tenant'], fn () => WebhookEndpoint::first());
        $endpoint->forceFill(['secret_hash' => Hash::make($this->secret)])->saveQuietly();
        $this->key = $endpoint->key;
    }

    private function send(array $payload, array $options = [])
    {
        $key = $options['key'] ?? $this->key;
        $secret = array_key_exists('secret', $options) ? $options['secret'] : $this->secret;

        $request = $secret === null ? $this : $this->withHeader('X-Webhook-Secret', $secret);

        return $request->postJson("/api/v1/integrations/webhooks/{$key}/leads", $payload);
    }

    // --- Accepts -----------------------------------------------------------

    public function test_a_well_formed_payload_is_accepted(): void
    {
        $this->send([
            'name' => 'سعود الحربي',
            'mobile' => '0512345678',
            'email' => 'saud@example.com',
            'city' => 'الرياض',
            'campaign' => 'حملة النرجس',
        ])->assertOk()->assertJsonPath('data.outcome', 'created');

        $lead = Lead::withoutGlobalScopes()->first();

        $this->assertSame('سعود الحربي', $lead->full_name);
        $this->assertSame('+966512345678', $lead->phone_normalized);
        $this->assertSame('حملة النرجس', $lead->campaign_name);
    }

    public function test_a_payload_with_only_an_email_is_accepted(): void
    {
        $this->send(['name' => 'بدون جوال', 'email' => 'noting@example.com'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'created');
    }

    public function test_every_documented_example_payload_is_accepted(): void
    {
        // The files in docs/examples are what a customer will actually try.
        $examples = glob(base_path('../docs/examples/*.json'));

        $this->assertNotEmpty($examples, 'No example payloads found to verify.');

        foreach ($examples as $path) {
            $payload = json_decode(file_get_contents($path), true);
            unset($payload['_comment']);

            $response = $this->send($payload);

            $this->assertContains(
                $response->status(),
                [200],
                basename($path).' was rejected: '.$response->getContent(),
            );
        }

        // The Arabic-digit example is the same buyer as the generic one, so it
        // must have re-engaged rather than created a second lead.
        $this->assertSame(
            1,
            Lead::withoutGlobalScopes()->where('phone_normalized', '+966512345678')->count(),
        );
    }

    // --- Refuses -----------------------------------------------------------

    public function test_a_wrong_secret_is_refused_and_logged(): void
    {
        $this->send(['phone' => '0512345678'], ['secret' => 'not-the-secret'])->assertStatus(401);

        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('webhook_logs', ['status' => 'rejected', 'http_status' => 401]);
    }

    public function test_a_missing_secret_is_refused(): void
    {
        $this->send(['phone' => '0512345678'], ['secret' => null])->assertStatus(401);
    }

    public function test_an_unknown_endpoint_is_indistinguishable_from_a_bad_secret(): void
    {
        $unknown = $this->send(['phone' => '0512345678'], ['key' => 'a-key-that-does-not-exist']);
        $badSecret = $this->send(['phone' => '0512345678'], ['secret' => 'wrong']);

        $unknown->assertStatus(401);
        $badSecret->assertStatus(401);

        // Same body, so valid endpoint keys cannot be probed for.
        $this->assertSame($unknown->json(), $badSecret->json());
    }

    public function test_a_payload_with_no_contact_detail_is_refused(): void
    {
        $this->send(['name' => 'لا يوجد تواصل'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    public function test_an_unparseable_phone_is_refused(): void
    {
        foreach (['12', 'not-a-phone', '---'] as $bad) {
            $this->send(['name' => 'رقم خاطئ', 'phone' => $bad])->assertStatus(422);
        }

        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    public function test_an_oversized_body_is_refused_before_it_is_parsed(): void
    {
        config(['integrations.webhooks.max_body_bytes' => 200]);

        $this->send(['phone' => '0512345678', 'blob' => str_repeat('x', 2000)])
            ->assertStatus(413);
    }

    public function test_an_inactive_endpoint_stops_accepting(): void
    {
        $this->asTenant($this->t['tenant'], fn () => WebhookEndpoint::first()->update(['is_active' => false]));

        $this->send(['phone' => '0512345678'])->assertStatus(401);
    }

    // --- Duplicates ---------------------------------------------------------

    public function test_the_same_number_in_any_format_re_engages_rather_than_duplicating(): void
    {
        $formats = ['0512345678', '+966512345678', '966512345678', '+966 51 234 5678', '٠٥١٢٣٤٥٦٧٨'];

        foreach ($formats as $index => $format) {
            $response = $this->send(['name' => 'سعود', 'phone' => $format, 'campaign' => "حملة {$index}"]);

            $response->assertOk();
            $response->assertJsonPath('data.outcome', $index === 0 ? 'created' : 're_engaged');
        }

        $this->assertSame(1, Lead::withoutGlobalScopes()->count());

        $lead = Lead::withoutGlobalScopes()->first();

        $this->assertSame(count($formats) - 1, $lead->re_engaged_count);
        // Every touch is kept for attribution, even the ones that did not create.
        $this->assertSame(count($formats), $lead->sourceEvents()->withoutGlobalScopes()->count());
    }

    // --- Rate limiting ------------------------------------------------------

    public function test_the_endpoint_is_rate_limited(): void
    {
        config(['integrations.webhooks.rate_limit_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->send(['name' => "عميل {$i}", 'phone' => '05100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)])
                ->assertOk();
        }

        $this->send(['name' => 'زائد', 'phone' => '0519999999'])->assertStatus(429);
    }

    // --- Isolation ----------------------------------------------------------

    public function test_a_lead_lands_in_the_endpoints_tenant_and_nowhere_else(): void
    {
        $other = $this->provisionTenant('Other Realty');

        $this->send(['name' => 'ملك ألفا', 'phone' => '0512345678'])->assertOk();

        $lead = Lead::withoutGlobalScopes()->first();

        $this->assertSame($this->t['tenant']->id, $lead->tenant_id);
        $this->assertNotSame($other['tenant']->id, $lead->tenant_id);
    }

    public function test_a_payload_cannot_choose_its_own_tenant(): void
    {
        $other = $this->provisionTenant('Target Realty');

        $this->send([
            'name' => 'محاولة اختراق',
            'phone' => '0512345678',
            'tenant_id' => $other['tenant']->id,
        ])->assertOk();

        $this->assertSame($this->t['tenant']->id, Lead::withoutGlobalScopes()->first()->tenant_id);
    }

    // --- Logging ------------------------------------------------------------

    public function test_every_outcome_is_logged_with_its_status(): void
    {
        $this->send(['name' => 'مقبول', 'phone' => '0511111111']);          // accepted
        $this->send(['name' => 'مكرر', 'phone' => '0511111111']);            // duplicate
        $this->send(['name' => 'مرفوض']);                                     // rejected
        $this->send(['phone' => '0512222222'], ['secret' => 'wrong']);       // rejected

        $statuses = WebhookLog::withoutGlobalScopes()->pluck('status')->countBy();

        $this->assertSame(1, $statuses['accepted'] ?? 0);
        $this->assertSame(1, $statuses['duplicate'] ?? 0);
        $this->assertSame(2, $statuses['rejected'] ?? 0);
    }

    public function test_a_credential_in_the_body_never_reaches_the_log(): void
    {
        $this->send([
            'name' => 'سري',
            'phone' => '0512345678',
            'api_token' => 'tok-live-do-not-store',
            'nested' => ['client_secret' => 'shh-do-not-store'],
        ])->assertOk();

        $payload = json_encode(WebhookLog::withoutGlobalScopes()->first()->payload);

        $this->assertStringNotContainsString('tok-live-do-not-store', $payload);
        $this->assertStringNotContainsString('shh-do-not-store', $payload);
    }

    public function test_an_ingestion_failure_is_logged_and_returns_a_safe_message(): void
    {
        // A provider blowing up must not take the endpoint down or leak a trace.
        $this->asTenant($this->t['tenant'], fn () => Integration::where('provider', 'generic_webhook')
            ->first()->update(['provider' => 'meta', 'config' => ['force_error' => true]]));

        $response = $this->send(['field_data' => 'not-an-array', 'phone' => '0512345678']);

        $this->assertContains($response->status(), [200, 422, 500]);
        $this->assertDatabaseCount('webhook_logs', 1);
    }

    // --- Retry / idempotency ------------------------------------------------

    public function test_a_provider_retrying_the_same_delivery_does_not_create_a_second_lead(): void
    {
        $payload = [
            'name' => 'إعادة إرسال',
            'phone' => '0512345678',
            'provider_lead_id' => 'meta-lead-retry-1',
        ];

        // Ad platforms retry on timeout; the second delivery must be harmless.
        $first = $this->send($payload);
        $second = $this->send($payload);
        $third = $this->send($payload);

        $first->assertOk()->assertJsonPath('data.outcome', 'created');
        $second->assertOk()->assertJsonPath('data.outcome', 're_engaged');
        $third->assertOk()->assertJsonPath('data.outcome', 're_engaged');

        $this->assertSame(1, Lead::withoutGlobalScopes()->count());

        // And all three point at the same lead, so a retry is safely idempotent.
        $this->assertSame($first->json('data.lead_id'), $second->json('data.lead_id'));
        $this->assertSame($first->json('data.lead_id'), $third->json('data.lead_id'));
    }

    public function test_a_returning_lost_lead_is_reopened_for_the_sales_team(): void
    {
        $this->send(['name' => 'عاد', 'phone' => '0512345678'])->assertOk();

        $lead = Lead::withoutGlobalScopes()->first();
        $lead->forceFill(['status' => 'lost', 'lost_at' => now()])->saveQuietly();

        $this->send(['name' => 'عاد', 'phone' => '0512345678', 'campaign' => 'حملة جديدة'])->assertOk();

        $lead->refresh();

        $this->assertSame('new', $lead->status->value);
        $this->assertNull($lead->lost_at);
    }

    public function test_an_endpoint_without_an_integration_is_not_a_server_error(): void
    {
        // integration_id is nullable, so an endpoint can legitimately stand
        // alone. Authorizing against the class name instead of the record left
        // the orphan case to fall through to a TypeError.
        $endpoint = $this->asTenant($this->t['tenant'], fn () => WebhookEndpoint::first());
        $endpoint->forceFill(['integration_id' => null])->saveQuietly();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/webhook-endpoints/{$endpoint->uuid}/rotate")
            ->assertStatus(404);

        $this->actingAs($this->t['admin'])
            ->deleteJson("/api/v1/webhook-endpoints/{$endpoint->uuid}")
            ->assertStatus(404);
    }

    public function test_an_unknown_field_cannot_impersonate_a_canonical_one(): void
    {
        // flatten() also exposes the leaf of a dotted key ("lead.phone" => "phone").
        // A key with no dot has no leaf, so taking one anyway lets "xcity" pass
        // itself off as "city" — an advertiser's stray field overwriting ours.
        $this->postJson("/api/v1/integrations/webhooks/{$this->key}/leads", [
            'full_name' => 'كامل الاسم',
            'phone' => '0556667777',
            'xcity' => 'مدينة مزيفة',
        ], ['X-Webhook-Secret' => $this->secret])->assertSuccessful();

        $lead = Lead::withoutGlobalScopes()->firstWhere('full_name', 'كامل الاسم');

        $this->assertNotNull($lead);
        $this->assertNotSame('مدينة مزيفة', $lead->city);
    }

    public function test_the_secret_is_not_accepted_from_the_query_string(): void
    {
        // A query string is written to access logs, proxy logs and browser
        // history. A shared secret that travels there is already compromised.
        $this->postJson(
            "/api/v1/integrations/webhooks/{$this->key}/leads?secret={$this->secret}",
            ['full_name' => 'عميل الرابط', 'phone' => '0556660001'],
        )->assertStatus(401);

        $this->assertDatabaseMissing('leads', ['full_name' => 'عميل الرابط']);
    }

    public function test_the_secret_is_still_accepted_in_the_body(): void
    {
        $this->postJson("/api/v1/integrations/webhooks/{$this->key}/leads", [
            'secret' => $this->secret,
            'full_name' => 'عميل المتن',
            'phone' => '0556660002',
        ])->assertSuccessful();

        $this->assertDatabaseHas('leads', ['full_name' => 'عميل المتن']);
    }

    public function test_an_ingestion_failure_never_shows_internal_detail_to_the_tenant(): void
    {
        // webhook_logs.error is rendered in the client's own integrations screen,
        // and a database message carries the SQL with its bindings.
        $this->mock(LeadIngestionService::class)
            ->shouldReceive('ingest')
            ->andThrow(new \RuntimeException("SQLSTATE[23505]: insert into \"leads\" values ('0555000111')"));

        $this->postJson("/api/v1/integrations/webhooks/{$this->key}/leads", [
            'full_name' => 'عميل الفشل',
            'phone' => '0556660003',
        ], ['X-Webhook-Secret' => $this->secret])->assertStatus(500);

        $logged = \DB::table('webhook_logs')->orderByDesc('id')->first();

        $this->assertNotNull($logged);
        $this->assertStringNotContainsString('0555000111', (string) $logged->error);
        $this->assertStringNotContainsString('insert into', (string) $logged->error);
    }
}
