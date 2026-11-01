<?php

namespace Tests\Feature;

use App\Domain\Sla\BusinessHours;
use App\Domain\Sla\SlaSweeper;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A lead that arrived at two in the morning is not a rep who ignored it.
 *
 * The response-time clock counted wall-clock minutes, so that lead was reminded
 * at ten past two, escalated to the management at half past, and taken off its
 * rep by three — for a call nobody could have made. The rep opened their laptop
 * to a breach they could not have avoided, which is the fastest way to teach a
 * team that the SLA is noise and the notifications can be ignored.
 *
 * Unconfigured means always open, so none of this changes anything for a
 * workspace until somebody says when they work.
 */
class TheSlaClockStopsAfterHoursTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Hours Co');
        $this->rep = $this->makeUser($this->t['tenant']);

        $this->t['tenant']->settings->forceFill([
            'timezone' => 'Asia/Riyadh',
            'sla_remind_after_minutes' => 10,
            'sla_escalate_after_minutes' => 30,
            'sla_reassign_after_minutes' => 60,
        ])->save();
    }

    /** Sunday to Thursday, nine to six — a normal week here. */
    private function openWeekdays(): void
    {
        $this->t['tenant']->settings->forceFill([
            'work_days' => [7, 1, 2, 3, 4],
            'work_starts_at' => '09:00',
            'work_ends_at' => '18:00',
        ])->save();
    }

    private function leadArrivingAt(Carbon $moment): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($moment) {
            $lead = Lead::create([
                'full_name' => 'عميل',
                'phone_normalized' => '+96650021'.self::uniqueDigits(4),
                'status' => 'new',
                'assigned_to' => $this->rep->id,
            ]);

            $lead->forceFill(['created_at' => $moment])->saveQuietly();

            return $lead->fresh();
        });
    }

    private function sweep(): void
    {
        $this->asTenant($this->t['tenant'], fn () => app(SlaSweeper::class)->sweep($this->t['tenant']));
    }

    /** The case the whole thing is for. */
    public function test_a_lead_arriving_overnight_is_not_a_breach_by_morning(): void
    {
        $this->openWeekdays();

        // Monday 02:00 Riyadh, swept at 04:00 — two hours of night.
        $this->travelTo(Carbon::parse('2026-10-12 02:00', 'Asia/Riyadh'));
        $lead = $this->leadArrivingAt(now());

        $this->travelTo(Carbon::parse('2026-10-12 04:00', 'Asia/Riyadh'));
        $this->sweep();

        $fresh = $lead->fresh();

        $this->assertNull($fresh->sla_reminded_at, 'nobody could have called at two in the morning');
        $this->assertNull($fresh->sla_escalated_at);
        $this->assertNull($fresh->sla_reassigned_at);
    }

    public function test_the_same_lead_breaches_once_the_office_has_been_open(): void
    {
        $this->openWeekdays();

        $this->travelTo(Carbon::parse('2026-10-12 02:00', 'Asia/Riyadh'));
        $lead = $this->leadArrivingAt(now());

        // 09:20 — twenty working minutes after opening, past the ten-minute rung
        // but not yet the thirty-minute one.
        $this->travelTo(Carbon::parse('2026-10-12 09:20', 'Asia/Riyadh'));
        $this->sweep();

        $fresh = $lead->fresh();

        $this->assertNotNull($fresh->sla_reminded_at, 'twenty working minutes is late');
        $this->assertNull($fresh->sla_escalated_at, 'and not yet an escalation');
    }

    /** A Friday lead does not spend the weekend accruing a breach. */
    public function test_the_weekend_does_not_count(): void
    {
        $this->openWeekdays();

        // Friday 10:00 — a closed day under this workspace's week.
        $this->travelTo(Carbon::parse('2026-10-16 10:00', 'Asia/Riyadh'));
        $lead = $this->leadArrivingAt(now());

        $this->travelTo(Carbon::parse('2026-10-17 10:00', 'Asia/Riyadh'));
        $this->sweep();

        $this->assertNull($lead->fresh()->sla_reminded_at, 'a day of the weekend is not a day of silence');
    }

    /** Nothing said about hours means the clock runs as it always did. */
    public function test_a_workspace_with_no_hours_set_is_unchanged(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 02:00', 'Asia/Riyadh'));
        $lead = $this->leadArrivingAt(now());

        $this->travelTo(Carbon::parse('2026-10-12 04:00', 'Asia/Riyadh'));
        $this->sweep();

        // Two wall-clock hours, which is what it always counted.
        $this->assertNotNull($lead->fresh()->sla_reminded_at);
        $this->assertNotNull($lead->fresh()->sla_escalated_at);
    }

    public function test_it_counts_only_the_minutes_the_office_was_open(): void
    {
        $this->openWeekdays();
        $hours = BusinessHours::for($this->t['tenant']->settings->fresh());

        $from = Carbon::parse('2026-10-12 17:30', 'Asia/Riyadh');   // Monday, half an hour left
        $to = Carbon::parse('2026-10-13 09:30', 'Asia/Riyadh');     // Tuesday, half an hour in

        $this->assertSame(60, $hours->minutesBetween($from, $to));
    }

    public function test_a_span_inside_one_working_day_is_just_the_minutes(): void
    {
        $this->openWeekdays();
        $hours = BusinessHours::for($this->t['tenant']->settings->fresh());

        $this->assertSame(45, $hours->minutesBetween(
            Carbon::parse('2026-10-12 10:00', 'Asia/Riyadh'),
            Carbon::parse('2026-10-12 10:45', 'Asia/Riyadh'),
        ));
    }

    /** Clocks that run backwards are a data problem, not negative lateness. */
    public function test_a_span_that_ends_before_it_starts_is_nothing(): void
    {
        $this->openWeekdays();
        $hours = BusinessHours::for($this->t['tenant']->settings->fresh());

        $this->assertSame(0, $hours->minutesBetween(
            Carbon::parse('2026-10-12 12:00', 'Asia/Riyadh'),
            Carbon::parse('2026-10-12 11:00', 'Asia/Riyadh'),
        ));
    }

    public function test_it_knows_whether_the_office_is_open_right_now(): void
    {
        $this->openWeekdays();
        $hours = BusinessHours::for($this->t['tenant']->settings->fresh());

        $this->assertTrue($hours->isOpenAt(Carbon::parse('2026-10-12 09:00', 'Asia/Riyadh')));
        $this->assertFalse($hours->isOpenAt(Carbon::parse('2026-10-12 18:00', 'Asia/Riyadh')));
        $this->assertFalse($hours->isOpenAt(Carbon::parse('2026-10-16 12:00', 'Asia/Riyadh')));
    }

    /**
     * The workspace's own timezone, not the server's. A workspace in Riyadh
     * opening at nine is not a workspace in London opening at nine.
     */
    public function test_the_hours_are_the_workspaces_own(): void
    {
        $this->t['tenant']->settings->forceFill([
            'timezone' => 'Europe/London',
            'work_days' => [1, 2, 3, 4, 5],
            'work_starts_at' => '09:00',
            'work_ends_at' => '17:00',
        ])->save();

        $hours = BusinessHours::for($this->t['tenant']->settings->fresh());

        // 09:30 in London is half past noon in Riyadh; the workspace is open.
        $this->assertTrue($hours->isOpenAt(Carbon::parse('2026-10-12 09:30', 'Europe/London')));
        $this->assertFalse($hours->isOpenAt(Carbon::parse('2026-10-12 09:30', 'Asia/Riyadh')));
    }

    public function test_a_workspace_can_say_when_it_works(): void
    {
        $this->actingAs($this->t['admin'])
            ->patchJson('/api/v1/settings', [
                'work_days' => [7, 1, 2, 3, 4],
                'work_starts_at' => '09:00',
                'work_ends_at' => '18:00',
            ])
            ->assertOk();

        $settings = $this->t['tenant']->settings->fresh();

        $this->assertSame([7, 1, 2, 3, 4], $settings->work_days);
        $this->assertFalse(BusinessHours::for($settings)->alwaysOpen());
    }

    /** A day that ends before it starts is not a day. */
    public function test_hours_that_end_before_they_start_are_refused(): void
    {
        $this->actingAs($this->t['admin'])
            ->patchJson('/api/v1/settings', [
                'work_days' => [1],
                'work_starts_at' => '18:00',
                'work_ends_at' => '09:00',
            ])
            ->assertStatus(422);
    }

    public function test_a_weekday_number_that_is_not_one_is_refused(): void
    {
        $this->actingAs($this->t['admin'])
            ->patchJson('/api/v1/settings', ['work_days' => [0, 9]])
            ->assertStatus(422);
    }

    /** Clearing them puts the workspace back to always open. */
    public function test_clearing_the_hours_starts_the_clock_again(): void
    {
        $this->openWeekdays();

        $this->actingAs($this->t['admin'])
            ->patchJson('/api/v1/settings', ['work_days' => null])
            ->assertOk();

        $this->assertTrue(BusinessHours::for($this->t['tenant']->settings->fresh())->alwaysOpen());
    }

    public function test_a_sales_rep_cannot_change_the_workspaces_hours(): void
    {
        $this->actingAs($this->rep)
            ->patchJson('/api/v1/settings', ['work_days' => [1]])
            ->assertForbidden();
    }
}
