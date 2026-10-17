<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationHealthReport;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadSourceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Implemented and externally verified are not the same claim.
 *
 * Every adapter in this product has tests, and tests prove the code does what
 * we think when the other end behaves as we think. They cannot prove the other
 * end exists. A product that showed those as one state will eventually tell
 * somebody a channel is ready when nothing has ever been sent through it — and
 * they will find out on the day it matters.
 *
 * So verification is computed from evidence: something real came through, or it
 * has not yet. Pasting a credential is not evidence. Writing the adapter is
 * not evidence.
 */
class ImplementedIsNotVerifiedTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Honest Co');
    }

    private function row(string $key): array
    {
        $rows = $this->asTenant($this->t['tenant'], fn () => app(IntegrationHealthReport::class)->all());

        return collect($rows)->firstWhere('key', $key) ?? [];
    }

    public function test_an_adapter_nobody_has_used_is_implemented_not_verified(): void
    {
        $this->assertSame(
            IntegrationHealthReport::IMPLEMENTED,
            $this->row(IntegrationProvider::Meta->value)['verification'],
        );
    }

    /**
     * The one that matters. Credentials being present proves that somebody
     * typed them, and nothing else — a token can be wrong, revoked, or for the
     * wrong account, and all three look identical until something arrives.
     */
    public function test_pasting_a_credential_does_not_verify_anything(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => IntegrationProvider::Meta->value,
            'name' => 'Meta',
            'status' => Integration::CONNECTED,
            'credentials' => ['access_token' => 'looks-real'],
            'is_active' => true,
        ]));

        $row = $this->row(IntegrationProvider::Meta->value);

        $this->assertTrue($row['connected']);
        $this->assertTrue($row['configured']);
        $this->assertSame(IntegrationHealthReport::IMPLEMENTED, $row['verification']);
    }

    /**
     * A real arrival is evidence — from a workspace that could have received one.
     *
     * An event for a platform the workspace holds no credentials for was posted by
     * hand, by the demo button or by a test. It has the right shape and it did not
     * come from the platform, so it is not proof of the platform.
     */
    public function test_a_lead_that_actually_arrived_verifies_the_source(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            Integration::create([
                'provider' => IntegrationProvider::Meta->value,
                'name' => 'Meta',
                'status' => Integration::CONNECTED,
                'credentials' => ['access_token' => 'EAAB-real', 'page_id' => '123'],
                'is_active' => true,
            ]);

            $this->arrive(IntegrationProvider::Meta);
        });

        $row = $this->row(IntegrationProvider::Meta->value);

        $this->assertSame(IntegrationHealthReport::EXTERNALLY_VERIFIED, $row['verification']);
        $this->assertNotNull($row['last_event_at']);
    }

    public function test_an_event_for_a_platform_with_no_credentials_is_not_proof_of_the_platform(): void
    {
        $this->asTenant($this->t['tenant'], fn () => $this->arrive(IntegrationProvider::Meta));

        $row = $this->row(IntegrationProvider::Meta->value);

        $this->assertSame(IntegrationHealthReport::IMPLEMENTED, $row['verification']);
        $this->assertSame('credentials_missing', $row['blocker']);
    }

    private function arrive(IntegrationProvider $provider): void
    {
        $lead = Lead::create([
            'full_name' => 'عميل',
            'phone_normalized' => '+9665512'.random_int(10000, 99999),
            'status' => 'new',
        ]);

        LeadSourceEvent::create([
            'lead_id' => $lead->id,
            'provider' => $provider->value,
            'platform' => $provider->value,
            'was_duplicate' => false,
            'received_at' => now(),
        ]);
    }

    /**
     * Telephony gets its own word rather than "implemented but unproven". The
     * only registered provider places no calls, so the honest thing to say is
     * that the rep dials — which is a different state, not a worse one.
     */
    public function test_telephony_says_the_rep_dials_rather_than_claiming_a_channel(): void
    {
        $this->assertSame('manual_only', $this->row('calls')['verification']);
        $this->assertFalse($this->row('calls')['connected']);
    }

    public function test_every_integration_the_product_has_appears(): void
    {
        $keys = collect($this->asTenant($this->t['tenant'], fn () => app(IntegrationHealthReport::class)->all()))
            ->pluck('key')
            ->all();

        foreach (['meta', 'tiktok', 'google_ads', 'snapchat', 'generic_webhook', 'google_sheets', 'whatsapp', 'calls', 'github'] as $expected) {
            $this->assertContains($expected, $keys);
        }
    }

    public function test_it_never_counts_another_workspaces_evidence(): void
    {
        $other = $this->provisionTenant('Somebody Else');

        $this->asTenant($other['tenant'], function () {
            $lead = Lead::create(['full_name' => 'x', 'phone_normalized' => '+966559999999', 'status' => 'new']);

            LeadSourceEvent::create([
                'lead_id' => $lead->id,
                'provider' => IntegrationProvider::TikTok->value,
                'platform' => 'tiktok',
                'was_duplicate' => false,
                'received_at' => now(),
            ]);
        });

        // Their TikTok works. Ours has still never received anything.
        $this->assertSame(
            IntegrationHealthReport::IMPLEMENTED,
            $this->row(IntegrationProvider::TikTok->value)['verification'],
        );
    }

    public function test_a_rep_may_not_read_the_integration_health_of_the_workspace(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/integrations/health')->assertForbidden();
    }

    public function test_an_admin_reads_it_over_the_api(): void
    {
        $rows = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/integrations/health')
            ->assertOk()
            ->json('data.integrations');

        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('verification', $rows[0]);
    }
}
