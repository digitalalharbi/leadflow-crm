<?php

namespace Tests\Feature;

use App\Domain\Digest\DailyDigest;
use App\Enums\Role;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DailyDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The one thing in مهتم that goes looking for a person.
 *
 * Everything else waits to be opened, which is fine until a form stops posting
 * or eleven leads sit uncalled — failures that are visible on a screen and
 * invisible until somebody goes to that screen.
 */
class DailyDigestTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private DailyDigest $digest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Digest Co');
        $this->digest = app(DailyDigest::class);
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+9665006'.self::uniqueDigits(5),
            'status' => 'new',
        ], $attributes)));
    }

    /** Past the workspace's first-contact target, so it counts as a backlog. */
    private function neglected(array $attributes = []): Lead
    {
        $lead = $this->lead(array_merge(['status' => 'new'], $attributes));
        $lead->forceFill(['created_at' => now()->subHours(4)])->saveQuietly();

        return $lead;
    }

    private function figures(): array
    {
        return $this->asTenant($this->t['tenant'], fn () => $this->digest->for($this->t['tenant']));
    }

    public function test_it_counts_yesterdays_arrivals_not_todays(): void
    {
        $this->lead()->forceFill(['created_at' => now()->subDay()->setTime(10, 0)])->saveQuietly();
        $this->lead()->forceFill(['created_at' => now()->setTime(9, 0)])->saveQuietly();

        // A morning digest describes the day that finished, not the one that
        // has barely started.
        $this->assertSame(1, $this->figures()['new_leads']);
    }

    /**
     * What arrived is history; what is waiting is work. These two are "right
     * now" on purpose, while the arrivals above are yesterday's.
     */
    public function test_the_backlog_is_counted_as_it_stands_now(): void
    {
        $this->neglected();
        $this->neglected();
        $this->lead(['status' => 'contacted', 'first_contact_at' => now()]);

        $this->assertSame(2, $this->figures()['uncontacted']);
    }

    /**
     * A lead that arrived four minutes ago is not neglected.
     *
     * The dashboard only calls one "awaiting first contact" once it is past the
     * workspace's own target, and the digest has to agree — counting fresh
     * arrivals as a backlog is both unfair and the fastest way to teach
     * somebody to ignore the number.
     */
    public function test_a_lead_still_inside_the_contact_target_is_not_a_backlog(): void
    {
        $fresh = $this->lead(['status' => 'new']);
        $fresh->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();

        $late = $this->lead(['status' => 'new']);
        $late->forceFill(['created_at' => now()->subHours(5)])->saveQuietly();

        $this->assertSame(1, $this->figures()['uncontacted']);
    }

    /**
     * The digest points at the dashboard, so the two must not drift apart. A
     * manager told "11 waiting" who opens the screen and reads 7 stops
     * believing both numbers.
     */
    public function test_its_figures_match_the_dashboard_they_point_at(): void
    {
        foreach (range(1, 3) as $i) {
            $this->neglected();
        }

        $lead = $this->lead();
        $this->asTenant($this->t['tenant'], fn () => FollowUp::create([
            'lead_id' => $lead->id, 'due_at' => now()->subHours(2), 'assigned_to' => $this->t['admin']->id,
        ]));

        $dashboard = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data.today');

        $figures = $this->figures();

        $this->assertSame($dashboard['awaiting_first_contact'], $figures['uncontacted']);
        $this->assertSame($dashboard['follow_ups_overdue'], $figures['overdue_follow_ups']);
    }

    public function test_an_overdue_follow_up_is_counted_and_a_future_one_is_not(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            FollowUp::create(['lead_id' => $lead->id, 'due_at' => now()->subHours(3), 'assigned_to' => $this->t['admin']->id]);
            FollowUp::create(['lead_id' => $lead->id, 'due_at' => now()->addDay(), 'assigned_to' => $this->t['admin']->id]);
        });

        $this->assertSame(1, $this->figures()['overdue_follow_ups']);
    }

    /**
     * The one figure nothing else would ever tell anybody. The arrivals chart
     * shows it, but only to somebody who opens the integrations page — and a
     * form that stopped posting is exactly the failure nobody goes looking for.
     */
    public function test_a_source_that_has_gone_quiet_is_named(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            LeadSourceEvent::create([
                'lead_id' => $lead->id, 'provider' => 'generic_webhook', 'platform' => 'linkedin',
                'was_duplicate' => false, 'received_at' => now()->subDays(3),
            ]);
            LeadSourceEvent::create([
                'lead_id' => $lead->id, 'provider' => 'generic_webhook', 'platform' => 'meta',
                'was_duplicate' => false, 'received_at' => now()->subHours(2),
            ]);
        });

        $silent = $this->figures()['silent_sources'];

        // Meta delivered two hours ago; LinkedIn has not since Tuesday.
        $this->assertSame(['linkedin'], array_column($silent, 'label'));
        $this->assertGreaterThanOrEqual(70, $silent[0]['silent_hours']);
    }

    /**
     * A source that never delivered is unconfigured, not silent. An admin who
     * connected LinkedIn and never ran an ad there does not need telling about
     * it every morning for the rest of the year.
     */
    public function test_a_source_that_never_delivered_is_not_called_silent(): void
    {
        $this->assertSame([], $this->figures()['silent_sources']);
    }

    /**
     * A digest that arrives every morning saying nothing happened is one people
     * learn to delete unread — and the one that matters goes with it.
     */
    public function test_an_empty_workspace_is_not_worth_sending(): void
    {
        $this->assertFalse($this->digest->worthSending($this->figures()));
    }

    public function test_a_backlog_alone_is_worth_sending(): void
    {
        $this->neglected();

        $this->assertTrue($this->digest->worthSending($this->figures()));
    }

    public function test_the_command_notifies_managers_and_admins_but_not_reps(): void
    {
        Notification::fake();
        $this->neglected();

        $rep = User::create([
            'tenant_id' => $this->t['tenant']->id,
            'name' => 'مندوب', 'email' => 'rep@digest.test',
            'password' => bcrypt('secret'), 'role' => Role::Sales->value, 'is_active' => true,
        ]);

        // `--now` because this test is about *who* is told, not *when*. The
        // hour gate is covered by the timezone tests below, and leaving it in
        // play here makes this pass or fail depending on the time of day.
        $this->artisan('leadflow:daily-digest --now')->assertSuccessful();

        // Workspace-wide figures need workspace-wide authority — the same rule
        // the reports and data-quality screens apply.
        Notification::assertSentTo($this->t['admin'], DailyDigestNotification::class);
        Notification::assertNotSentTo($rep, DailyDigestNotification::class);
    }

    /**
     * The tenant arrives without its relations loaded, as it does from the
     * command.
     *
     * This asserts the figures, and deliberately does not claim to guard the
     * lazy-load bug that prompted it. Reading `$tenant->settings` directly
     * threw for every workspace in the real run — lazy loading is disabled
     * outside production — and the digest sent nothing. No test here can catch
     * that: a plainly lazy access on a freshly fetched model does not raise
     * under PHPUnit in this project, verified with the violation callback
     * installed by hand. The fix was confirmed by running the command, and
     * that is the only thing that confirms it.
     */
    public function test_it_works_on_a_tenant_fetched_fresh_from_the_database(): void
    {
        $this->neglected();

        $tenant = Tenant::findOrFail($this->t['tenant']->id);
        $this->assertFalse($tenant->relationLoaded('settings'));

        $figures = $this->asTenant($tenant, fn () => $this->digest->for($tenant));

        $this->assertSame(1, $figures['uncontacted']);
    }

    /**
     * Eight o'clock where the workspace is, not where the server is.
     *
     * The first version fired at one fixed UTC hour on the strength of a
     * comment claiming tenants had no timezone. They do, and a "morning"
     * digest that lands at four in the afternoon is just another notification.
     */
    public function test_it_sends_at_eight_in_the_workspaces_own_timezone(): void
    {
        Notification::fake();
        $this->t['tenant']->settings->forceFill(['timezone' => 'Asia/Riyadh'])->save();

        // Travel first: the backlog is dated relative to "now", so creating it
        // before moving the clock dates it against a time that no longer applies.
        $this->travelTo(now()->setTime(5, 30));
        $this->neglected();

        $this->artisan('leadflow:daily-digest')->assertSuccessful();
        Notification::assertSentTo($this->t['admin'], DailyDigestNotification::class);
    }

    public function test_it_stays_quiet_at_the_wrong_hour_for_that_workspace(): void
    {
        Notification::fake();
        $this->t['tenant']->settings->forceFill(['timezone' => 'Asia/Riyadh'])->save();

        // Eight in the morning UTC is eleven in Riyadh — the wrong hour there.
        $this->travelTo(now()->setTime(8, 0));
        $this->neglected();
        $this->artisan('leadflow:daily-digest')->assertSuccessful();
        Notification::assertNothingSent();
    }

    /** A different timezone gets a different hour, which is the whole point. */
    public function test_two_workspaces_in_different_timezones_are_sent_at_different_hours(): void
    {
        Notification::fake();
        $this->t['tenant']->settings->forceFill(['timezone' => 'Europe/London'])->save();

        $this->travelTo(now()->setTime(5, 30));
        $this->neglected();
        $this->artisan('leadflow:daily-digest')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_a_quiet_workspace_gets_nothing(): void
    {
        Notification::fake();

        $this->artisan('leadflow:daily-digest --now')
            ->expectsOutputToContain('nothing to report')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    /** The backlog leads, because it is the part somebody can still act on. */
    public function test_the_summary_line_puts_the_work_before_the_news(): void
    {
        $notification = new DailyDigestNotification([
            'date' => now()->subDay()->toDateString(),
            'new_leads' => 12, 'won' => 0, 'lost' => 0,
            'uncontacted' => 9, 'overdue_follow_ups' => 0,
            'silent_sources' => [['label' => 'linkedin', 'silent_hours' => 72]],
        ]);

        $body = $notification->toDatabase($this->t['admin'])['body'];

        $this->assertStringStartsWith('9 بانتظار أول تواصل', $body);
        // The brand's own name, not the key it is stored under.
        $this->assertStringContainsString('LinkedIn', $body);
        $this->assertStringNotContainsString('linkedin', $body);
    }
}
