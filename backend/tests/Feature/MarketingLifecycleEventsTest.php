<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Conversions\ConversionEvent;
use App\Domain\Tracking\Destinations\DestinationKind;
use App\Domain\Tracking\EventType;
use App\Domain\Visits\ReservationService;
use App\Enums\IntegrationProvider;
use App\Events\LeadQualified;
use App\Events\LeadSignal;
use App\Models\ConversionExport;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Project;
use App\Models\TrackingDestination;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The whole journey on one trail: a lead is created, qualified, becomes interested, books a visit, reserves, is won
 * for an amount — or lost — and each of those is an event on the same store, with the id every destination shares,
 * told to the destinations that asked, and (for the ad platforms) turned into the conversion they can optimise on.
 */
class MarketingLifecycleEventsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    private TrackingLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->t = $this->provisionTenant('Life Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $this->link = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create(['token' => 'life'.random_int(100000, 999999), 'name' => 'إعلان ميتا', 'channel' => 'meta', 'project_id' => $this->project->id]));
        $this->asTenant($this->t['tenant'], fn () => TrackingDestination::create([
            'kind' => 'webhook', 'name' => 'نظامنا', 'is_enabled' => true, 'config' => ['endpoint_url' => 'https://hooks.example.test/in'], 'credentials' => ['secret' => 's'],
            'events' => DestinationKind::EVENTS, 'send_value' => true, 'hash_identifiers' => true, // A lead is created before its consent is known to this test; what the consent does is pinned in the fan-out tests.
            'consent_mode' => 'unless_denied',
        ]));
    }

    private function arrive(array $extra = []): Lead
    {
        $result = app(LeadIngestionService::class)->ingest($this->t['tenant'], IncomingLead::fromArray(IntegrationProvider::GenericWebhook, $extra + [
            'full_name' => 'سلمان القحطاني', 'phone' => '0551234567', 'email' => 'salman@example.com', 'platform' => 'meta', 'project_id' => $this->project->id,
            'tracking_token' => $this->link->token,
        ]));

        return $this->asTenant($this->t['tenant'], function () use ($result) {
            $result->lead->forceFill(['tracking_consent' => 'granted', 'first_touch_link_id' => $this->link->id, 'last_touch_link_id' => $this->link->id, 'visitor_id' => 'v_'.str_repeat('a', 20)])->saveQuietly();

            return $result->lead->fresh();
        });
    }

    private function events(string $type)
    {
        return TrackingEvent::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->where('event_type', $type)->get();
    }

    private function told(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0]->data()['event'] ?? null)->filter()->values()->all();
    }

    public function test_a_new_lead_is_an_event_with_everything_the_trail_knows_about_it(): void
    {
        $lead = $this->arrive();

        $event = $this->events(EventType::LeadCreated->value)->first();
        $this->assertNotNull($event, 'a created lead left no trace on the trail');
        $this->assertSame($lead->id, $event->lead_id);
        $this->assertSame($this->link->id, $event->tracking_link_id);
        $this->assertSame($this->project->id, $event->project_id);
        $this->assertSame('meta', $event->platform);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $event->event_id);
        $this->assertCount(1, $this->events(EventType::LeadCreated->value), 'told twice');
        $this->assertContains('lead_created', $this->told());
    }

    public function test_every_stage_of_the_journey_is_one_event_told_once(): void
    {
        $lead = $this->arrive();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $fresh = $lead->fresh();
            // Qualified
            $fresh->forceFill(['qualification_status' => 'qualified', 'qualified_at' => now()])->save();
            LeadQualified::dispatch($fresh->fresh());
            // Interested — twice, as the interest level flaps
            foreach ([1, 2] as $_) {
                $fresh->forceFill(['interest_level' => 'interested'])->saveQuietly();
                LeadSignal::dispatch($fresh->fresh(), 'lead.interest_changed', ['from' => 'cold', 'to' => 'interested']);
            }
            // A visit is booked
            LeadSignal::dispatch($fresh->fresh(), 'lead.appointment_created', ['appointment' => (string) str()->uuid()]);
        });

        foreach ([EventType::Qualified, EventType::Interested, EventType::AppointmentBooked] as $type) {
            $this->assertCount(1, $this->events($type->value), "{$type->value} is not exactly one event");
        }
        foreach (['qualified', 'interested', 'appointment_booked'] as $name) {
            $this->assertContains($name, $this->told(), "{$name} did not reach the destination");
        }
    }

    public function test_winning_a_lead_records_the_sale_and_the_revenue_and_a_corrected_amount_is_a_new_revenue_event(): void
    {
        $lead = $this->arrive();

        $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->forceFill(['status' => 'won', 'won_at' => now(), 'first_contact_at' => now()->subDay(), 'last_contact_at' => now()->subDay(), 'deal_value_minor' => 90000000])->save());
        $this->assertCount(1, $this->events(EventType::Won->value));
        $this->assertCount(1, $this->events(EventType::Revenue->value));
        $this->assertSame(90000000, (int) $this->events(EventType::Revenue->value)->first()->meta['deal_value_minor']);

        $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->forceFill(['deal_value_minor' => 95000000])->save());

        $this->assertCount(2, $this->events(EventType::Revenue->value), 'a corrected amount is a new figure, not a replay');
        $this->assertCount(1, $this->events(EventType::Won->value), 'and the sale is still one sale');
    }

    public function test_a_lost_lead_is_an_event_too(): void
    {
        $lead = $this->arrive();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $reason = LostReason::firstOrCreate(['key' => 'price'], ['label' => 'السعر', 'is_active' => true]);
            $lead->fresh()->forceFill(['status' => 'lost', 'lost_at' => now(), 'lost_reason_id' => $reason->id])->save();
        });

        $this->assertCount(1, $this->events(EventType::Lost->value));
        $this->assertContains('lost', $this->told());
    }

    // ---------------------------------------------------------------- optimise on more than the lead

    public function test_interested_and_reservation_become_conversions_an_ad_platform_can_optimise_on(): void
    {
        $lead = $this->arrive();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $fresh = $lead->fresh();
            $fresh->forceFill(['interest_level' => 'interested'])->saveQuietly();
            LeadSignal::dispatch($fresh->fresh(), 'lead.interest_changed', ['from' => 'cold', 'to' => 'interested']);
            app(ReservationService::class)->create($fresh->fresh(), $this->t['admin']);
        });

        $events = ConversionExport::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->pluck('event')->map(fn ($e) => $e->value)->all();
        $this->assertContains('interested', $events, 'the platform was never told the lead was interested');
        $this->assertContains('reservation', $events, 'the platform was never told a unit was reserved');
        $this->assertSame('Contact', ConversionEvent::Interested->standardName());
        $this->assertSame('InitiateCheckout', ConversionEvent::Reservation->standardName());
    }

    public function test_a_lead_that_refused_tracking_is_not_reported_to_an_ad_platform(): void
    {
        $lead = $this->arrive();
        $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->forceFill(['tracking_consent' => 'denied'])->saveQuietly());

        $this->asTenant($this->t['tenant'], fn () => LeadSignal::dispatch($lead->fresh(), 'lead.interest_changed', ['from' => 'cold', 'to' => 'interested']));

        $this->assertSame(0, ConversionExport::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->where('event', 'interested')->count());
    }

    public function test_the_lifecycle_of_one_workspace_never_reaches_anothers_trail(): void
    {
        $rival = $this->provisionTenant('Rival Life Co');
        $this->arrive();

        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->where('tenant_id', $rival['tenant']->id)->count());
    }
}
