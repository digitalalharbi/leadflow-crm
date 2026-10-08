<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\LeadAssigned;
use App\Events\LeadCreated;
use App\Events\LeadFeedUpdate;
use App\Events\LeadQualified;
use App\Events\LeadReplied;
use App\Listeners\BroadcastLeadChange;
use App\Models\Lead;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The lead that arrives at 10:04 should be on a screen at 10:04.
 *
 * The work queue refetches every sixty seconds, which is right for a screen
 * left open all day and wrong for the one thing this product promises. A minute
 * is also exactly long enough for a rep to wander off.
 *
 * Most of what is tested here is who may listen. A live feed is the one place
 * where "who can see this" is decided by a channel name instead of a policy, so
 * the channel callbacks are the policy, and they are tested as such — including
 * the case that matters most, which is somebody from another workspace.
 */
class ALeadArrivesWhileYouAreLookingTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Live Co');

        /*
         * The null broadcaster runs no channel callback at all — it answers
         * every request with nothing — so an authorisation test against the
         * default connection passes for everybody and proves nothing. Point the
         * app at a real driver with throwaway credentials: the endpoint signs
         * locally and never reaches a server.
         */
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        /*
         * Channels are registered on the broadcaster the application resolved
         * at boot, and switching the connection above makes a different one.
         * Loading the same file the application loads puts them on it — without
         * this, no channel matches, every subscription is refused, and the
         * tests that assert a refusal all pass while proving nothing.
         */
        require base_path('routes/channels.php');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+96650040'.random_int(1000, 9999),
            'status' => 'new',
        ], $attributes)));
    }

    /**
     * The shape Pusher's client sends: a socket id and the channel it wants.
     *
     * `sanctum` named explicitly. Without it the default guard is used, the
     * broadcaster finds no user on the request, and every subscription is
     * refused — which would leave each "may not listen" test below passing for
     * the wrong reason and each "may listen" test failing for no reason.
     */
    private function subscribe(User $as, string $channel)
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/broadcasting/auth', [
            'socket_id' => '12345.67890',
            'channel_name' => $channel,
        ]);
    }

    private function tenantChannel(?string $uuid = null): string
    {
        return 'private-tenant.'.($uuid ?? $this->t['tenant']->uuid).'.leads';
    }

    // ------------------------------------------------------------- listening

    public function test_an_admin_may_watch_their_own_workspace(): void
    {
        $this->subscribe($this->t['admin'], $this->tenantChannel())->assertOk();
    }

    public function test_a_manager_may_watch_their_own_workspace(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);

        $this->subscribe($manager, $this->tenantChannel())->assertOk();
    }

    /**
     * The one that matters. The uuid in a channel name is a label, not a
     * secret: it travels in logs, screenshots and shared links, and a feed that
     * relied on nobody ever seeing one would hand a competitor every arrival.
     */
    public function test_nobody_may_watch_another_workspace(): void
    {
        $other = $this->provisionTenant('Somebody Else');

        $this->subscribe($other['admin'], $this->tenantChannel())->assertForbidden();
        $this->subscribe($this->t['admin'], $this->tenantChannel($other['tenant']->uuid))->assertForbidden();
    }

    /**
     * A rep is told about their own leads and nothing else. Hearing that a lead
     * exists which their queue will never show them is both useless to them and
     * more than they are allowed to know.
     */
    public function test_a_rep_may_not_watch_the_whole_workspace(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->subscribe($rep, $this->tenantChannel())->assertForbidden();
    }

    public function test_a_rep_may_watch_their_own_feed(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->subscribe($rep, 'private-user.'.$rep->uuid)->assertOk();
    }

    public function test_a_rep_may_not_watch_a_colleagues_feed(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $colleague = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->subscribe($rep, 'private-user.'.$colleague->uuid)->assertForbidden();
    }

    /** Not even an admin, who can read the lead but is not that person. */
    public function test_an_admin_may_not_watch_a_reps_feed(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->subscribe($this->t['admin'], 'private-user.'.$rep->uuid)->assertForbidden();
    }

    public function test_a_signed_out_browser_is_refused(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '12345.67890',
            'channel_name' => $this->tenantChannel(),
        ])->assertUnauthorized();
    }

    /**
     * Laravel also registers the endpoint on the `web` stack, which this API
     * does not use: there is no session, so it can authenticate nobody. Asserted
     * rather than removed, because a route that answers 403 to everything is
     * harmless and a provider override to delete it is not.
     */
    public function test_the_session_based_endpoint_authenticates_nobody(): void
    {
        $this->post('/broadcasting/auth', [
            'socket_id' => '12345.67890',
            'channel_name' => $this->tenantChannel(),
        ])->assertForbidden();
    }

    // ------------------------------------------------------------ what is said

    /**
     * Faked rather than listened for: an event that implements ShouldBroadcast
     * queues its own broadcast, the test queue runs synchronously, and the
     * driver would try to reach a server that is not there. Faking stops it at
     * the dispatch, which is the part being asserted.
     */
    private function broadcastsFor(object $event): ?LeadFeedUpdate
    {
        Event::fake([LeadFeedUpdate::class]);

        app(BroadcastLeadChange::class)->handle($event);

        $dispatched = Event::dispatched(LeadFeedUpdate::class)->first();

        return is_array($dispatched) ? ($dispatched[0] ?? null) : $dispatched;
    }

    public function test_an_arriving_lead_reaches_the_workspace_feed(): void
    {
        $update = $this->broadcastsFor(new LeadCreated($this->lead()));

        $this->assertNotNull($update);
        $this->assertSame('lead.created', $update->event);
        $this->assertSame($this->t['tenant']->uuid, $update->tenantUuid);
    }

    public function test_an_assigned_lead_reaches_the_person_it_landed_on(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $lead = $this->lead(['assigned_to' => $rep->id]);

        $update = $this->broadcastsFor(new LeadAssigned($lead));

        $channels = array_map(fn ($c) => $c->name, $update->broadcastOn());

        $this->assertContains('private-tenant.'.$this->t['tenant']->uuid.'.leads', $channels);
        $this->assertContains('private-user.'.$rep->uuid, $channels);
    }

    /** Nobody to tell personally is not a reason to say nothing at all. */
    public function test_an_unassigned_lead_still_reaches_the_workspace(): void
    {
        $update = $this->broadcastsFor(new LeadCreated($this->lead()));

        $this->assertSame(
            ['private-tenant.'.$this->t['tenant']->uuid.'.leads'],
            array_map(fn ($c) => $c->name, $update->broadcastOn()),
        );
    }

    public function test_a_reply_is_worth_saying(): void
    {
        $this->assertNotNull($this->broadcastsFor(new LeadReplied($this->lead())));
    }

    /**
     * A stage change is usually the person on that screen doing it, and telling
     * somebody about their own click is how a live feed becomes a thing people
     * switch off.
     */
    public function test_an_everyday_change_is_not_announced(): void
    {
        $this->assertNull($this->broadcastsFor(new LeadQualified($this->lead())));
    }

    /**
     * The payload is a nudge, not a record. Whatever travels here has escaped
     * LeadPolicy — a rep on their own channel would otherwise be handed fields
     * the API would never give them — so it carries an identifier and nothing
     * a person could read.
     */
    public function test_it_sends_an_identifier_and_nothing_readable(): void
    {
        $lead = $this->lead(['full_name' => 'سلمان القحطاني', 'email' => 'salman@example.test']);

        $payload = $this->broadcastsFor(new LeadCreated($lead))->broadcastWith();

        $this->assertSame(['event', 'lead_id', 'assignee_id'], array_keys($payload));
        $this->assertSame($lead->uuid, $payload['lead_id']);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('سلمان', $encoded);
        $this->assertStringNotContainsString('example.test', $encoded);
        $this->assertStringNotContainsString((string) $lead->phone_normalized, $encoded);
    }

    /**
     * The listener runs wherever a lead event is raised, including a queued job
     * and a scheduled sweep, where the tenant scope fails closed. Reading the
     * workspace through the scope would find no tenant for a lead that plainly
     * has one, and the feed would go silent in the places that need it most.
     */
    public function test_it_still_names_the_workspace_outside_a_request(): void
    {
        $lead = $this->lead();

        app()->forgetInstance(TenantContext::class);

        $update = $this->broadcastsFor(new LeadCreated($lead->fresh()));

        $this->assertSame($this->t['tenant']->uuid, $update?->tenantUuid);
    }
}
