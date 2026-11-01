<?php

namespace Tests\Feature;

use App\Domain\Calendar\IcsCalendar;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Visits reach people's calendars: one as a file, or all of theirs through a private address their phone subscribes
 * to. The address is the credential, can be changed at any time, and shows only what that person may see.
 */
class CalendarFeedTest extends TestCase
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

    private function visit(array $t, User $rep, string $name, int $inDays = 2): Appointment
    {
        return $this->asTenant($t['tenant'], function () use ($rep, $name, $inDays) {
            $lead = Lead::create(['full_name' => $name, 'phone' => '05'.random_int(10000000, 99999999), 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'assigned_to' => $rep->id]);

            return Appointment::create(['lead_id' => $lead->id, 'assigned_to' => $rep->id, 'scheduled_at' => now()->addDays($inDays)->setTime(10, 0), 'duration_minutes' => 45, 'status' => 'confirmed', 'location' => 'المعرض, الرياض']);
        });
    }

    private function feedUrl(User $user): string
    {
        $url = $this->actingAs($user)->postJson('/api/v1/auth/calendar-feed/rotate')->assertOk()->json('data.url');
        $this->flushHeaders();
        auth()->forgetGuards();

        return parse_url($url, PHP_URL_PATH);
    }

    public function test_a_rep_feed_lists_their_own_visits_without_phone_numbers(): void
    {
        $rep = $this->makeUser($this->a['tenant'], Role::Sales);
        $other = $this->makeUser($this->a['tenant'], Role::Sales);
        $mine = $this->visit($this->a, $rep, 'سارة');
        $this->visit($this->a, $other, 'خالد');
        $this->visit($this->b, $this->b['admin'], 'Beta Person');

        $ics = $this->get($this->feedUrl($rep))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('UID:'.$mine->uuid.'@mohtam', $ics);
        $this->assertStringContainsString('سارة', $ics);
        $this->assertStringContainsString('LOCATION:المعرض\\, الرياض', $ics);
        $this->assertStringNotContainsString('خالد', $ics);
        $this->assertStringNotContainsString('Beta Person', $ics);
        $this->assertStringNotContainsString('+9665', $ics);
        $this->assertNotNull($rep->fresh()->calendar_feed_fetched_at);
    }

    public function test_a_new_address_retires_the_old_one_and_disabling_retires_both(): void
    {
        $rep = $this->makeUser($this->a['tenant'], Role::Sales);
        $first = $this->feedUrl($rep);
        $second = $this->feedUrl($rep);

        $this->get($first)->assertNotFound();
        $this->get($second)->assertOk();

        $this->actingAs($rep)->deleteJson('/api/v1/auth/calendar-feed')->assertOk();
        $this->flushHeaders();
        auth()->forgetGuards();
        $this->get($second)->assertNotFound();
        $this->get('/api/v1/public/calendar/'.str_repeat('a', 48).'.ics')->assertNotFound();
    }

    public function test_a_deactivated_person_feed_stops(): void
    {
        $rep = $this->makeUser($this->a['tenant'], Role::Sales);
        $url = $this->feedUrl($rep);
        $rep->forceFill(['is_active' => false])->save();

        $this->get($url)->assertNotFound();
    }

    public function test_one_visit_downloads_as_a_file_only_for_who_may_see_it(): void
    {
        $rep = $this->makeUser($this->a['tenant'], Role::Sales);
        $visit = $this->visit($this->a, $rep, 'سارة');

        $this->actingAs($this->a['admin'])->get("/api/v1/appointments/{$visit->uuid}/ics")->assertOk()->assertSee('BEGIN:VEVENT', false);
        $this->actingAs($this->makeUser($this->a['tenant'], Role::Sales))->get("/api/v1/appointments/{$visit->uuid}/ics")->assertForbidden();
        $this->actingAs($this->b['admin'])->get("/api/v1/appointments/{$visit->uuid}/ics")->assertNotFound();
    }

    public function test_lines_are_folded_without_breaking_arabic_and_text_is_escaped(): void
    {
        $line = 'SUMMARY:'.str_repeat('زيارة ', 30);
        $folded = IcsCalendar::fold($line);

        foreach (explode("\r\n", $folded) as $part) {
            $this->assertLessThanOrEqual(75, strlen($part));
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'));
        }
        $this->assertSame($line, str_replace("\r\n ", '', $folded));
        $this->assertSame('a\;b\\,c\\nd\\\\', IcsCalendar::text("a;b,c\nd\\"));
    }

    public function test_the_readiness_screen_lists_the_calendar(): void
    {
        $item = collect($this->actingAs($this->a['admin'])->getJson('/api/v1/readiness')->assertOk()->json('data.items'))->firstWhere('key', 'calendar');

        $this->assertNotNull($item);
        $this->assertSame('awaiting_first_event', $item['blocker']);
    }
}
