<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Conversions\ConversionEvent;
use App\Domain\Conversions\ConversionRecorder;
use App\Domain\Conversions\ExporterRegistry;
use App\Enums\IntegrationProvider;
use App\Jobs\SendConversionExport;
use App\Models\ConversionExport;
use App\Models\Integration;
use App\Models\Lead;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sending sales outcomes back to the ad platform that produced the lead.
 *
 * This is the half of the loop that makes spend optimisable: the platform
 * learns which clicks became buyers rather than which became form fills. The
 * failure modes are all quiet ones — an upload that matches nobody, a
 * conversion dropped because the integration was not connected yet, the same
 * sale counted twice — so most of these tests are about those.
 */
class ConversionExportTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Conversions Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+9665005'.random_int(10000, 99999),
            'status' => 'new',
            'platform' => 'meta',
            'budget_min' => 800000,
            'budget_max' => 1200000,
        ], $attributes)));
    }

    private function record(Lead $lead, ConversionEvent $event = ConversionEvent::Won): ?ConversionExport
    {
        return $this->asTenant(
            $this->t['tenant'],
            fn () => app(ConversionRecorder::class)->record($lead, $event),
        );
    }

    public function test_a_won_lead_is_recorded_for_upload(): void
    {
        $export = $this->record($this->lead());

        $this->assertNotNull($export);
        $this->assertSame('meta', $export->platform);
        $this->assertSame(ConversionEvent::Won, $export->event);
        $this->assertSame(ConversionExport::PENDING, $export->status);
    }

    /** A lead from a spreadsheet has no ad account waiting to hear about it. */
    public function test_a_lead_with_no_ad_platform_records_nothing(): void
    {
        $this->assertNull($this->record($this->lead(['platform' => null])));
        $this->assertNull($this->record($this->lead(['platform' => 'csv_import'])));
    }

    /**
     * The optimiser must not be told the same sale twice: a lead that is won,
     * reopened and won again is one deal.
     */
    public function test_the_same_outcome_is_never_recorded_twice(): void
    {
        $lead = $this->lead();

        $first = $this->record($lead);
        $second = $this->record($lead);

        $this->assertSame($first->id, $second->id);
        $this->asTenant($this->t['tenant'], fn () => $this->assertSame(1, ConversionExport::count()));
    }

    /** Different outcomes on one lead are different signals, and both go. */
    public function test_qualified_and_won_are_separate_conversions(): void
    {
        $lead = $this->lead();

        $this->record($lead, ConversionEvent::Qualified);
        $this->record($lead, ConversionEvent::Won);

        $this->asTenant($this->t['tenant'], fn () => $this->assertSame(2, ConversionExport::count()));
    }

    /**
     * An upload with nothing to match on is accepted by the platform, matches
     * nobody, and reports success. Marking it is the only way a workspace ever
     * finds out.
     */
    public function test_a_lead_with_no_identifier_is_marked_unmatchable(): void
    {
        $export = $this->record($this->lead([
            'phone_normalized' => null,
            'email' => null,
            'click_id' => null,
            'provider_lead_id' => null,
        ]));

        $this->assertSame(ConversionExport::UNMATCHABLE, $export->status);
    }

    public function test_a_won_deal_carries_an_estimated_value(): void
    {
        $export = $this->record($this->lead(['budget_min' => 800000, 'budget_max' => 1200000]));

        $this->assertSame('1000000.00', $export->value);
    }

    public function test_a_won_deal_carries_what_it_actually_closed_for_when_known(): void
    {
        $export = $this->record($this->lead(['status' => 'won', 'deal_value_minor' => 95000000]));

        $this->assertSame('950000.00', $export->value);
    }

    public function test_facebook_and_instagram_leads_report_to_the_meta_account(): void
    {
        foreach (['facebook', 'instagram'] as $platform) {
            $export = $this->record($this->lead(['platform' => $platform]));
            $this->assertNotNull($export, "A {$platform} lead's sale was never reported.");
            $this->assertSame('meta', $export->platform);
        }
    }

    public function test_booking_a_visit_is_reported_as_an_appointment(): void
    {
        $lead = $this->lead(['status' => 'qualified']);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/appointments', [
            'lead_id' => $lead->uuid, 'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        $this->assertTrue($this->asTenant($this->t['tenant'], fn () => ConversionExport::query()
            ->where('lead_id', $lead->id)->where('event', 'appointment')->exists()));
    }

    /** Qualifying is a signal, not a sale; attaching a price to it is a guess. */
    public function test_a_qualification_carries_no_value(): void
    {
        $export = $this->record($this->lead(), ConversionEvent::Qualified);

        $this->assertNull($export->value);
    }

    /**
     * The behaviour that matters most. A workspace that has not connected Meta
     * has not done anything wrong, and three retries plus a failed-job alert
     * for every won lead is how a team learns to ignore alerts.
     */
    public function test_an_unconnected_platform_waits_rather_than_failing(): void
    {
        Http::fake();
        $export = $this->record($this->lead());

        (new SendConversionExport($export->id))->handle(
            app(ExporterRegistry::class),
            app(TenantContext::class),
        );

        $export->refresh();
        $this->assertSame(ConversionExport::AWAITING_CREDENTIALS, $export->status);
        $this->assertNull($export->last_error);
        Http::assertNothingSent();
    }

    /** And it is picked up again once the account is connected. */
    public function test_a_waiting_conversion_is_retried_after_the_account_is_connected(): void
    {
        $export = $this->record($this->lead());
        $export->update(['status' => ConversionExport::AWAITING_CREDENTIALS]);

        $this->assertTrue(
            ConversionExport::withoutGlobalScopes()->sendable()->pluck('id')->contains($export->id),
            'a conversion waiting on credentials must come back into the sweep',
        );
    }

    public function test_a_configured_platform_receives_the_conversion(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        $this->connectMeta();
        $export = $this->record($this->lead());

        $this->send($export);

        $export->refresh();
        $this->assertSame(ConversionExport::SENT, $export->status);
        $this->assertNotNull($export->sent_at);
    }

    /**
     * Identifiers are hashed before they leave. A platform hashes its own copy
     * the same way, so an un-normalised value matches nobody — and a phone
     * number in the clear is a phone number we have handed to a third party.
     */
    public function test_personal_identifiers_are_hashed_before_they_are_sent(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);

        $this->connectMeta();
        $lead = $this->lead(['phone_normalized' => '+966500111222', 'email' => 'Buyer@Example.COM']);

        $this->send($this->record($lead));

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return ! str_contains($body, '966500111222')
                && ! str_contains($body, 'Buyer@Example.COM')
                && ! str_contains($body, 'buyer@example.com')
                && str_contains($body, hash('sha256', 'buyer@example.com'));
        });
    }

    /** What we keep about a send must not be a second copy of the customer. */
    public function test_the_stored_summary_holds_no_identifiers(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);

        $this->connectMeta();
        $lead = $this->lead(['phone_normalized' => '+966500111222']);

        $this->send($this->record($lead));

        $summary = json_encode(
            $this->asTenant($this->t['tenant'], fn () => ConversionExport::first()->request_summary),
        );

        $this->assertStringNotContainsString('966500111222', $summary);
        $this->assertStringNotContainsString(hash('sha256', '966500111222'), $summary);
    }

    public function test_a_platform_error_is_recorded_rather_than_lost(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => 'bad token'], 401)]);

        $this->connectMeta();
        $export = $this->record($this->lead());

        $this->send($export);

        $export->refresh();
        $this->assertSame(ConversionExport::FAILED, $export->status);
        $this->assertStringContainsString('401', $export->last_error);
    }

    /** One workspace's conversions never carry another's. */
    public function test_conversions_do_not_cross_tenants(): void
    {
        $other = $this->provisionTenant('Elsewhere Co');

        $this->asTenant($other['tenant'], function () {
            $lead = Lead::create([
                'full_name' => 'سر', 'phone_normalized' => '+966500999222',
                'status' => 'new', 'platform' => 'meta',
            ]);
            app(ConversionRecorder::class)->record($lead, ConversionEvent::Won);
        });

        $this->asTenant($this->t['tenant'], function () {
            $this->assertSame(0, ConversionExport::count());
        });
    }

    /**
     * The click id has to survive ingestion or none of the above matters: the
     * columns exist, the adapters read them, and every lead arrives without
     * one. Each platform forwards it under its own name.
     */
    public function test_a_click_id_survives_ingestion_whatever_it_is_called(): void
    {
        foreach (['gclid', 'fbclid', 'ttclid', 'sccid', 'click_id'] as $key) {
            $incoming = IncomingLead::fromArray(
                IntegrationProvider::GenericWebhook,
                ['full_name' => 'عميل', 'phone' => '0500000000', $key => "value-for-{$key}"],
            );

            $this->assertSame("value-for-{$key}", $incoming->clickId, "{$key} was dropped");
        }
    }

    public function test_utm_parameters_survive_ingestion(): void
    {
        $incoming = IncomingLead::fromArray(
            IntegrationProvider::GenericWebhook,
            [
                'full_name' => 'عميل', 'phone' => '0500000000',
                'utm_source' => 'google', 'utm_medium' => 'cpc',
                'utm_campaign' => 'riyadh-q4', 'utm_term' => 'شقق',
                'utm_content' => 'variant-b',
            ],
        );

        $this->assertSame('google', $incoming->utmSource);
        $this->assertSame('riyadh-q4', $incoming->utmCampaign);
        $this->assertSame('variant-b', $incoming->utmContent);
    }

    private function connectMeta(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'meta',
            'name' => 'Meta',
            'status' => Integration::CONNECTED,
            'is_active' => true,
            'credentials' => ['pixel_id' => '123', 'access_token' => 'tok'],
        ]));
    }

    private function send(ConversionExport $export): void
    {
        (new SendConversionExport($export->id))->handle(
            app(ExporterRegistry::class),
            app(TenantContext::class),
        );
    }
}
