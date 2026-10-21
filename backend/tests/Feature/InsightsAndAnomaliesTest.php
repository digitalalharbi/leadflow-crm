<?php

namespace Tests\Feature;

use App\Domain\Insights\AnomalyDetector;
use App\Domain\Insights\Baseline;
use App\Enums\Role;
use App\Models\Anomaly;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ImportantNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Numbers that moved outside their normal range are found, said once and shown; and the few cards
 * that say what to look at first are facts with their numbers, silent when there is nothing to say.
 */
class InsightsAndAnomaliesTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Insights Co');
    }

    /** @param  array<int,int>  $perDay  days ago → how many leads arrived that day */
    private function leadsOnDays(array $perDay, array $attributes = []): void
    {
        $this->asTenant($this->t['tenant'], function () use ($perDay, $attributes) {
            foreach ($perDay as $ago => $n) {
                for ($i = 0; $i < $n; $i++) {
                    $lead = Lead::create($attributes + ['full_name' => "عميل {$ago}-{$i}", 'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => 'contacted', 'first_contact_at' => now()]);
                    DB::table('leads')->where('id', $lead->id)->update(['created_at' => CarbonImmutable::today()->subDays($ago)->setTime(12, 0)]);
                }
            }
        });
    }

    private function detect(): array
    {
        return $this->asTenant($this->t['tenant'], fn () => array_map(fn (Anomaly $a) => $a->kind.($a->subject !== '' ? ":{$a->subject}" : ''), app(AnomalyDetector::class)->run()));
    }

    public function test_the_baseline_and_its_rules_for_a_spike_and_a_drop(): void
    {
        $base = Baseline::of([10, 12, 9, 11, 10, 12, 10, 11]);
        $this->assertEqualsWithDelta(10.625, $base['mean'], 0.001);
        $this->assertTrue(Baseline::isSpike(40, $base));
        $this->assertFalse(Baseline::isSpike(14, $base), 'a busy day is not a spike');
        $this->assertTrue(Baseline::isDrop(2, $base));
        $this->assertFalse(Baseline::isDrop(7, $base), 'a quiet day is not a drop');
        $this->assertFalse(Baseline::isSpike(100, Baseline::of([1, 2])), 'two days of history is not a baseline');
    }

    public function test_a_lead_spike_and_a_lead_drop_are_found_and_a_normal_day_is_not(): void
    {
        $normal = array_fill(2, 14, 10);

        $this->leadsOnDays($normal + [1 => 11]);
        $this->assertSame([], $this->detect());

        $this->leadsOnDays([1 => 40]);
        $this->assertContains('lead_spike', $this->detect());
    }

    public function test_a_drop_in_leads_is_found(): void
    {
        $this->leadsOnDays(array_fill(2, 14, 10) + [1 => 1]);

        $this->assertContains('lead_drop', $this->detect());
    }

    public function test_too_little_history_says_nothing(): void
    {
        $this->leadsOnDays([1 => 50, 2 => 1, 3 => 1]);

        $this->assertSame([], $this->detect());
    }

    public function test_a_cost_per_lead_that_jumps_is_found(): void
    {
        $this->leadsOnDays(array_fill(1, 15, 5));
        $campaign = $this->asTenant($this->t['tenant'], fn () => Campaign::create(['platform' => 'meta', 'name' => 'ح', 'origin' => 'manual', 'currency' => 'SAR']));

        // 100 a day for a lead of 20 — then 400 yesterday for the same five leads.
        $this->asTenant($this->t['tenant'], function () use ($campaign) {
            foreach (range(2, 15) as $ago) {
                MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $campaign->id, 'date' => now()->subDays($ago)->toDateString(), 'spend_minor' => 10_000, 'currency' => 'SAR']);
            }
            MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $campaign->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 40_000, 'currency' => 'SAR']);
        });

        $this->assertContains('cpl_spike', $this->detect());
    }

    public function test_a_campaign_spending_with_nothing_qualified_is_found_by_name(): void
    {
        $campaign = $this->asTenant($this->t['tenant'], fn () => Campaign::create(['platform' => 'meta', 'name' => 'حملة الهدر', 'origin' => 'manual', 'currency' => 'SAR']));
        $this->asTenant($this->t['tenant'], function () use ($campaign) {
            foreach ([1, 2, 3] as $ago) {
                MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $campaign->id, 'date' => now()->subDays($ago)->toDateString(), 'spend_minor' => 20_000, 'currency' => 'SAR']);
            }
        });
        $this->leadsOnDays([2 => 3, 3 => 3], []);
        $this->asTenant($this->t['tenant'], fn () => DB::table('leads')->update(['campaign_ref_id' => $campaign->id, 'status' => 'contacted', 'qualification_status' => 'unqualified']));

        $this->assertContains('spend_no_qualified:حملة الهدر', $this->detect());
    }

    public function test_the_managers_are_told_once_however_often_it_runs(): void
    {
        $this->leadsOnDays(array_fill(2, 14, 10) + [1 => 45]);
        Notification::fake();

        $this->detect();
        $this->detect();
        Artisan::call('leadflow:detect-anomalies', ['--tenant' => $this->t['tenant']->slug]);

        Notification::assertSentToTimes($this->t['admin'], ImportantNotification::class, 1);
        Notification::assertSentTo($this->t['admin'], ImportantNotification::class, fn ($n) => $n->kind === 'budget_anomaly' && $n->title === 'قفزة في العملاء' && $n->link === '/leads');
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => Anomaly::where('kind', 'lead_spike')->count()), 'one record a day');
    }

    public function test_a_growing_backlog_needs_a_history_and_then_says_so(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            foreach (range(1, 5) as $ago) {
                DB::table('daily_figures')->insert(['tenant_id' => $this->t['tenant']->id, 'on_date' => now()->subDays($ago)->toDateString(), 'key' => 'backlog', 'value' => 6]);
            }
        });
        // Thirty new leads nobody has touched, older than the first-contact target.
        $this->leadsOnDays([0 => 30], ['status' => 'new', 'first_contact_at' => null]);
        $this->asTenant($this->t['tenant'], fn () => DB::table('leads')->update(['created_at' => now()->subHours(5)]));

        $this->assertContains('backlog_growth', $this->detect());
    }

    public function test_the_cards_say_what_to_look_at_and_stay_quiet_otherwise(): void
    {
        $empty = $this->actingAs($this->t['admin'])->getJson('/api/v1/insights')->assertOk()->json('data');
        $this->assertSame([], $empty['cards']);
        $this->assertSame([], $empty['anomalies']);

        $this->asTenant($this->t['tenant'], function () {
            $lead = Lead::create(['full_name' => 'يريد الحجز', 'phone_normalized' => '+966501112233', 'status' => 'contacted', 'assigned_to' => $this->t['admin']->id]);
            Reservation::create(['lead_id' => $lead->id, 'status' => 'pending', 'expires_at' => now()->addHours(5)]);
            DB::table('leads')->where('id', $lead->id)->update(['interest_level' => 'reservation_ready', 'interest_level_changed_at' => now()->subHour()]);

            foreach (range(1, 2) as $i) {
                $visit = Appointment::create(['lead_id' => $lead->id, 'scheduled_at' => now()->subDay(), 'status' => 'no_show']);
                DB::table('appointments')->where('id', $visit->id)->update(['no_show_at' => now()->subDay()]);
            }
        });

        $data = $this->actingAs($this->t['admin'])->getJson('/api/v1/insights')->assertOk()->json('data');
        $keys = collect($data['cards'])->pluck('key')->all();

        $this->assertContains('holds_expiring', $keys);
        $this->assertContains('interested_waiting', $keys, 'a reservation request past its five minutes');
        $this->assertContains('no_shows', $keys);
        $this->assertLessThanOrEqual(6, count($data['cards']));

        // Worst first.
        $this->assertSame('bad', $data['cards'][0]['tone']);
        foreach ($data['cards'] as $card) {
            $this->assertNotNull($card['href']);
        }
    }

    public function test_anomalies_are_listed_dismissed_and_kept(): void
    {
        $this->leadsOnDays(array_fill(2, 14, 10) + [1 => 45]);
        $this->detect();

        $list = $this->actingAs($this->t['admin'])->getJson('/api/v1/insights')->assertOk()->json('data.anomalies');
        $this->assertSame('lead_spike', $list[0]['kind']);
        $this->assertSame('critical', $list[0]['severity']);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/anomalies/{$list[0]['id']}/dismiss")->assertOk();
        $this->assertSame([], $this->actingAs($this->t['admin'])->getJson('/api/v1/insights')->json('data.anomalies'));
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => Anomaly::count()), 'the record stays');

        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->actingAs($rep)->postJson("/api/v1/anomalies/{$list[0]['id']}/dismiss")->assertForbidden();
        $this->assertSame([], $this->actingAs($rep)->getJson('/api/v1/insights')->json('data.anomalies'));
    }

    public function test_money_cards_are_only_made_for_those_who_may_see_money(): void
    {
        $this->assertInstanceOf(User::class, $this->t['admin']);
        $client = $this->makeUser($this->t['tenant'], Role::ClientViewer);
        $this->actingAs($client)->getJson('/api/v1/insights')->assertOk();
        $keys = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/insights')->json('data.cards'))->pluck('key')->all();
        $this->assertNotContains('best_campaign', $keys);
    }
}
