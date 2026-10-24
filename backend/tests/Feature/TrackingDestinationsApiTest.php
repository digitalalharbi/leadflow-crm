<?php

namespace Tests\Feature;

use App\Domain\Tracking\Destinations\DestinationKind;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\TrackingDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a workspace's events can go: GA4, Google Ads, Meta, TikTok, Snapchat, LinkedIn, X, a GTM web or server
 * container, or a webhook of its own. A destination is configuration and nothing more — no credential is invented,
 * none is ever returned, and a destination that is waiting for one says so instead of pretending to work.
 */
class TrackingDestinationsApiTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $rival;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Dest Co');
        $this->rival = $this->provisionTenant('Rival Dest Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function make(array $overrides = [], ?array $as = null)
    {
        return $this->actingAs(($as ?? $this->t)['admin'])->postJson('/api/v1/tracking/destinations', $overrides + [
            'kind' => 'ga4', 'name' => 'GA4 النرجس', 'project_id' => $this->project->uuid,
            'config' => ['measurement_id' => 'G-ABC123DEF4'], 'credentials' => ['api_secret' => 'super-secret-value'],
            'is_enabled' => true,
        ]);
    }

    public function test_the_catalogue_lists_every_destination_with_what_it_can_do_and_needs(): void
    {
        $kinds = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/tracking/destinations/catalogue')->assertOk()->json('data.kinds'));

        foreach (['ga4', 'google_ads', 'meta', 'tiktok', 'snapchat', 'linkedin', 'x', 'gtm_web', 'gtm_server', 'webhook'] as $kind) {
            $this->assertTrue($kinds->contains('kind', $kind), "{$kind} is not offered");
        }
        $ga4 = $kinds->firstWhere('kind', 'ga4');
        $this->assertTrue($ga4['browser'] && $ga4['server']);
        $this->assertSame(['measurement_id'], $ga4['config']);
        $this->assertSame(['api_secret'], $ga4['credentials']);
        $this->assertTrue($kinds->firstWhere('kind', 'gtm_web')['browser']);
        $this->assertFalse($kinds->firstWhere('kind', 'gtm_web')['server']);
    }

    public function test_a_destination_is_made_but_its_secret_is_never_given_back(): void
    {
        $response = $this->make()->assertCreated();
        $body = $response->getContent();

        $this->assertStringNotContainsString('super-secret-value', $body, 'the secret came back');
        $this->assertSame(['api_secret'], $response->json('data.destination.credentials_set'));
        $this->assertSame('G-ABC123DEF4', $response->json('data.destination.config.measurement_id'));
        $this->assertSame('ready', $response->json('data.destination.readiness'));

        $stored = TrackingDestination::withoutGlobalScopes()->first();
        $this->assertStringNotContainsString('super-secret-value', (string) $stored->getRawOriginal('credentials'), 'the secret is stored in the clear');
        $this->assertSame('super-secret-value', $stored->credentials['api_secret']);

        $this->assertNotNull(AuditLog::where('action', 'tracking_destination.created')->first());
        $this->assertStringNotContainsString('super-secret-value', json_encode(AuditLog::where('action', 'tracking_destination.created')->first()->changes), 'the secret reached the audit trail');
    }

    public function test_a_destination_without_its_credential_is_waiting_not_working(): void
    {
        $meta = $this->make(['kind' => 'meta', 'name' => 'Meta', 'config' => ['pixel_id' => '123456789012345'], 'credentials' => []])->assertCreated();

        $this->assertSame('browser_only', $meta->json('data.destination.readiness'), 'a pixel with no API token can only report from the browser');

        $webhook = $this->make(['kind' => 'webhook', 'name' => 'نظامنا', 'config' => ['endpoint_url' => 'https://hooks.example.test/in'], 'credentials' => []])->assertCreated();
        $this->assertSame('awaiting_credentials', $webhook->json('data.destination.readiness'), 'a webhook with no signing secret is not ready');
    }

    public function test_every_identifier_is_checked_for_its_own_shape(): void
    {
        $bad = [
            ['kind' => 'ga4', 'config' => ['measurement_id' => 'UA-1234-1']],
            ['kind' => 'gtm_web', 'config' => ['container_id' => '<script>alert(1)</script>']],
            ['kind' => 'meta', 'config' => ['pixel_id' => 'abc']],
            ['kind' => 'webhook', 'config' => ['endpoint_url' => 'http://insecure.example.test']],
            ['kind' => 'webhook', 'config' => ['endpoint_url' => 'https://127.0.0.1/steal']],
            ['kind' => 'gtm_server', 'config' => ['endpoint_url' => 'ftp://x.example.test']],
            ['kind' => 'nonsense', 'config' => []],
        ];

        foreach ($bad as $case) {
            $this->make($case + ['name' => 'x', 'credentials' => []])->assertStatus(422);
        }
        $this->assertSame(0, TrackingDestination::withoutGlobalScopes()->count());
    }

    public function test_only_managers_configure_destinations_and_every_change_is_audited(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->actingAs($rep)->postJson('/api/v1/tracking/destinations', ['kind' => 'ga4', 'name' => 'x', 'config' => ['measurement_id' => 'G-ABC123DEF4']])->assertForbidden();

        $id = $this->make()->json('data.destination.id');
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/destinations/{$id}", ['is_enabled' => false, 'events' => ['form_submitted', 'won']])->assertOk()->assertJsonPath('data.destination.is_enabled', false);
        $this->assertNotNull(AuditLog::where('action', 'tracking_destination.updated')->first());

        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/tracking/destinations/{$id}")->assertOk();
        $this->assertNotNull(AuditLog::where('action', 'tracking_destination.deleted')->first());
    }

    public function test_the_event_allowlist_is_a_closed_list(): void
    {
        $id = $this->make()->json('data.destination.id');

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/destinations/{$id}", ['events' => ['form_submitted', 'delete_everything']])->assertStatus(422);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/destinations/{$id}", ['events' => ['lead_created', 'qualified', 'interested', 'appointment_booked', 'reservation_created', 'won', 'revenue_recorded']])->assertOk();
    }

    public function test_a_workspace_sees_only_its_own_destinations_and_cannot_touch_another(): void
    {
        $mine = $this->make()->json('data.destination.id');
        $theirs = $this->make(['name' => 'GA4 المنافس', 'project_id' => null], $this->rival)->json('data.destination.id');

        $listed = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/tracking/destinations')->assertOk()->json('data.destinations'))->pluck('id');
        $this->assertTrue($listed->contains($mine));
        $this->assertFalse($listed->contains($theirs), 'another workspace\'s destination is in the list');

        $this->actingAs($this->t['admin'])->getJson("/api/v1/tracking/destinations/{$theirs}")->assertNotFound();
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/tracking/destinations/{$theirs}", ['name' => 'سطو'])->assertNotFound();
        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/tracking/destinations/{$theirs}")->assertNotFound();
        $this->assertSame('GA4 المنافس', TrackingDestination::withoutGlobalScopes()->where('uuid', $theirs)->value('name'));
    }

    public function test_a_destination_cannot_be_pointed_at_another_workspaces_project(): void
    {
        $theirProject = $this->asTenant($this->rival['tenant'], fn () => Project::create(['name' => 'مشروعهم']));

        $this->make(['project_id' => $theirProject->uuid])->assertNotFound();
    }

    public function test_the_kind_knows_what_it_needs(): void
    {
        $this->assertSame('ready', DestinationKind::Ga4->readiness(['measurement_id' => 'G-ABC123DEF4'], ['api_secret' => 'x']));
        $this->assertSame('browser_only', DestinationKind::Ga4->readiness(['measurement_id' => 'G-ABC123DEF4'], []));
        $this->assertSame('not_configured', DestinationKind::Ga4->readiness([], ['api_secret' => 'x']));
        $this->assertSame('ready', DestinationKind::GtmWeb->readiness(['container_id' => 'GTM-ABCD12'], []), 'a web container needs nothing secret');
    }
}
