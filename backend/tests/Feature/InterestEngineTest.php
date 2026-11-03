<?php

namespace Tests\Feature;

use App\Domain\Conversation\RulesExtractor;
use App\Domain\Leads\InterestEngine;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\Role;
use App\Events\LeadSignal;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadEngagement;
use App\Models\LeadFact;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\TrackingEvent;
use App\Services\Leads\LeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Interest is what a lead DOES — replies, the brochure, the project page, the price asked, a visit
 * or a reservation requested — and what is going cold. It is its own number, apart from the score.
 */
class InterestEngineTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Interest Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create($attributes + [
            'full_name' => 'سلمان', 'phone' => '0551234567', 'phone_normalized' => '+9665512'.self::uniqueDigits(5),
            'email' => 'a'.random_int(1, 99999).'@example.com', 'project_id' => $this->project->id, 'status' => 'contacted',
        ]));
    }

    private function fresh(Lead $lead): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => $lead->fresh());
    }

    private function eval(Lead $lead): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(InterestEngine::class)->evaluate($this->fresh($lead)));
    }

    private function signals(Lead $lead): array
    {
        return collect($this->eval($lead)['signals'])->pluck('points', 'key')->all();
    }

    private function message(Lead $lead, string $direction, array $extra = []): LeadMessage
    {
        return $this->asTenant($this->t['tenant'], fn () => LeadMessage::create($extra + [
            'lead_id' => $lead->id, 'direction' => $direction === 'in' ? MessageDirection::Inbound : MessageDirection::Outbound,
            'channel' => MessageChannel::Whatsapp, 'provider' => 'mock', 'message_type' => 'text', 'body' => 'x', 'status' => 'sent',
        ]));
    }

    private function fact(Lead $lead, string $kind, string $value): void
    {
        $this->asTenant($this->t['tenant'], fn () => LeadFact::create(['lead_id' => $lead->id, 'kind' => $kind, 'value' => $value, 'confidence' => 80, 'source' => 'rules', 'extracted_at' => now()]));
    }

    private function open(Lead $lead, int $times): void
    {
        $this->asTenant($this->t['tenant'], function () use ($lead, $times) {
            foreach (range(1, $times) as $i) {
                LeadEngagement::create(['lead_id' => $lead->id, 'type' => LeadEngagement::FILE_OPENED, 'happened_at' => now()->subHours($i)]);
            }
        });
    }

    // ------------------------------------------------------------ the signals

    public function test_a_lead_nobody_has_heard_from_is_cold(): void
    {
        $result = $this->eval($this->lead());

        $this->assertSame(0, $result['score']);
        $this->assertSame('cold', $result['level']);
    }

    public function test_a_reply_warms_and_each_further_reply_and_a_recent_one_count_for_more(): void
    {
        $lead = $this->lead();
        $this->message($lead, 'in');

        $this->assertSame(['replied' => 10, 'recent_reply' => 5], $this->signals($lead));
        $this->assertSame('cold', $this->eval($lead)['level'], '15 is not yet warm');

        $this->message($lead, 'in');
        $this->assertSame(15, $this->signals($lead)['replied']);
        $this->assertSame('warm', $this->eval($lead)['level']);
    }

    public function test_the_brochure_opened_twice_is_worth_more_than_once(): void
    {
        $lead = $this->lead();
        $this->open($lead, 1);
        $this->assertSame(8, $this->signals($lead)['brochure_opened']);

        $this->open($lead, 1);
        $this->assertSame(14, $this->signals($lead)['brochure_opened']);
    }

    public function test_pages_viewed_and_coming_back_on_another_day_count(): void
    {
        $lead = $this->lead();
        $this->asTenant($this->t['tenant'], function () use ($lead) {
            TrackingEvent::create(['lead_id' => $lead->id, 'event_type' => 'project_view', 'occurred_at' => now()->subDays(2), 'visitor_id' => 'v_aaaaaaaaaaaaaaaaaaaa']);
            TrackingEvent::create(['lead_id' => $lead->id, 'event_type' => 'visit', 'occurred_at' => now(), 'visitor_id' => 'v_aaaaaaaaaaaaaaaaaaaa']);
        });

        $signals = $this->signals($lead);

        $this->assertSame(6, $signals['project_viewed']);
        $this->assertSame(8, $signals['came_back']);
    }

    public function test_asking_the_price_and_about_financing_are_buying_questions(): void
    {
        $lead = $this->lead();
        $this->fact($lead, 'inquiry', 'price');
        $this->fact($lead, 'inquiry', 'financing');

        $this->assertSame(['asked_price' => 8, 'asked_financing' => 8], $this->signals($lead));
    }

    public function test_the_rules_hear_a_question_about_price_but_not_an_objection_to_it(): void
    {
        $asked = collect((new RulesExtractor)->extract(['كم السعر؟ وفيه تمويل']))->map(fn ($f) => $f->kind.':'.$f->value);
        $this->assertTrue($asked->contains('inquiry:price'));
        $this->assertTrue($asked->contains('inquiry:financing'));

        $objected = collect((new RulesExtractor)->extract(['السعر غالي']))->map(fn ($f) => $f->kind.':'.$f->value);
        $this->assertTrue($objected->contains('objection:price_high'));
        $this->assertFalse($objected->contains('inquiry:price'));
    }

    // ------------------------------------------------------------ the levels

    public function test_enough_signals_make_a_lead_interested_then_hot(): void
    {
        $lead = $this->lead();
        $this->message($lead, 'in');
        $this->message($lead, 'in');
        $this->open($lead, 2);
        $this->fact($lead, 'inquiry', 'price');
        $this->fact($lead, 'inquiry', 'financing');

        // 15 + 5 + 14 + 8 + 8 = 50
        $this->assertSame(50, $this->eval($lead)['score']);
        $this->assertSame('interested', $this->eval($lead)['level']);

        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['qualification_status' => 'qualified'])->saveQuietly());
        $this->message($lead, 'in');
        $this->message($lead, 'in');
        $this->message($lead, 'in');
        $this->asTenant($this->t['tenant'], function () use ($lead) {
            foreach (range(1, 3) as $i) {
                TrackingEvent::create(['lead_id' => $lead->id, 'event_type' => 'project_view', 'occurred_at' => now()->subHours($i), 'visitor_id' => 'v_aaaaaaaaaaaaaaaaaaaa']);
            }
        });

        // 20 + 5 + 14 + 8 + 8 + 10 + 10 = 75
        $this->assertSame('hot', $this->eval($lead)['level'], 'five replies, the brochure twice, three page views, the price and financing asked, qualified');
    }

    public function test_a_visit_asked_for_makes_a_warm_enough_lead_visit_ready_and_a_booking_always_does(): void
    {
        $asked = $this->lead(['appointment_interest' => true]);
        $this->assertSame('warm', $this->eval($asked)['level'], 'the request alone is 20 points: warm, not yet visit ready');

        $this->message($asked, 'in');
        $this->message($asked, 'in');
        $this->assertSame('visit_ready', $this->eval($asked)['level']);

        $booked = $this->lead();
        $this->asTenant($this->t['tenant'], fn () => Appointment::create(['lead_id' => $booked->id, 'scheduled_at' => now()->addDays(2), 'status' => 'scheduled']));
        $this->message($booked, 'in');
        $this->assertSame('visit_ready', $this->eval($booked)['level']);
    }

    public function test_a_reservation_asked_for_is_the_top_level_and_so_is_a_sale(): void
    {
        $reserve = $this->lead(['reservation_intent' => true]);
        $this->assertSame('reservation_ready', $this->eval($reserve)['level']);

        $held = $this->lead();
        $this->asTenant($this->t['tenant'], fn () => Reservation::create(['lead_id' => $held->id, 'status' => 'pending', 'reserved_at' => now()]));
        $this->assertSame('reservation_ready', $this->eval($held)['level']);

        $won = $this->lead(['status' => 'won']);
        $this->assertSame('reservation_ready', $this->eval($won)['level']);
    }

    // ------------------------------------------------------------ what is going cold

    public function test_three_unanswered_messages_cost_points_and_a_reply_removes_the_penalty(): void
    {
        $lead = $this->lead();
        foreach ([1, 2, 3] as $i) {
            $this->message($lead, 'out');
        }

        $this->assertSame(-15, $this->signals($lead)['no_response']);

        $this->message($lead, 'in');
        $this->assertArrayNotHasKey('no_response', $this->signals($lead));
    }

    public function test_no_working_channel_and_unsubscribing_are_marked_and_unsubscribing_forces_cold(): void
    {
        $dead = $this->lead(['phone' => null, 'phone_normalized' => null, 'email' => null]);
        $this->assertSame(-20, $this->signals($dead)['unreachable']);

        $gone = $this->lead();
        $this->message($gone, 'in');
        $this->message($gone, 'in');
        $this->open($gone, 2);
        $this->asTenant($this->t['tenant'], fn () => $gone->forceFill(['whatsapp_opt_out_at' => now(), 'email_opt_out_at' => now()])->saveQuietly());

        $this->assertSame(-25, $this->signals($gone)['unsubscribed']);
        $this->assertSame('cold', $this->eval($gone)['level'], 'whatever they did before');
    }

    public function test_saying_they_are_not_interested_forces_cold_and_a_wrong_number_costs_even_more(): void
    {
        $no = $this->lead();
        $this->message($no, 'in');
        $this->open($no, 2);
        $this->fact($no, 'intent', 'not_interested');
        $this->assertSame(-40, $this->signals($no)['not_interested']);
        $this->assertSame('cold', $this->eval($no)['level']);

        $wrong = $this->lead();
        $this->fact($wrong, 'intent', 'wrong_contact');
        $this->assertSame(-60, $this->signals($wrong)['wrong_contact']);
    }

    public function test_two_quiet_weeks_cool_a_lead_that_was_warm(): void
    {
        $lead = $this->lead();
        $this->open($lead, 2);
        $this->asTenant($this->t['tenant'], function () use ($lead) {
            LeadEngagement::where('lead_id', $lead->id)->update(['happened_at' => now()->subDays(35)]);
            $lead->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();
        });

        $this->assertArrayNotHasKey('brochure_opened', $this->signals($lead), 'outside the thirty-day window');
        $this->assertSame(-10, $this->signals($lead)['gone_quiet']);
    }

    // ------------------------------------------------------------ apart from the score

    public function test_a_perfect_fit_who_never_answers_is_high_score_and_low_interest(): void
    {
        $fit = $this->lead(['budget_max' => 3_000_000, 'purchase_purpose' => 'residence', 'payment_method' => 'cash', 'purchase_timeline' => 'within_month', 'preferred_property_type' => 'villa']);
        $curious = $this->lead(['budget_max' => 400_000]);
        $this->message($curious, 'in');
        $this->message($curious, 'in');
        $this->open($curious, 2);
        $this->fact($curious, 'inquiry', 'price');

        foreach ([$fit, $curious] as $lead) {
            $this->asTenant($this->t['tenant'], fn () => app(LeadScoringService::class)->refresh($this->fresh($lead), 'test'));
            $this->asTenant($this->t['tenant'], fn () => app(InterestEngine::class)->refresh($this->fresh($lead)));
        }

        $this->assertGreaterThan($this->fresh($curious)->score, $this->fresh($fit)->score, 'the fit scores higher');
        $this->assertGreaterThan($this->fresh($fit)->interest_score, $this->fresh($curious)->interest_score, 'the curious one is the interested one');
    }

    // ------------------------------------------------------------ a person's word

    public function test_a_persons_word_sets_a_floor_or_forces_cold_and_is_written_to_the_history(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/interest", ['override' => 'interested'])->assertOk()
            ->assertJsonPath('data.lead.interest_level', 'interested')
            ->assertJsonPath('data.lead.interest_override', 'interested');

        // The numbers cannot take it back...
        $this->asTenant($this->t['tenant'], fn () => app(InterestEngine::class)->refresh($this->fresh($lead)));
        $this->assertSame('interested', $this->fresh($lead)->interest_level);

        // ...until the person does.
        $this->message($lead, 'in');
        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/interest", ['override' => 'not_interested'])->assertOk()->assertJsonPath('data.lead.interest_level', 'cold');
        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/interest", ['override' => null])->assertOk()->assertJsonPath('data.lead.interest_override', null);

        $this->assertSame(3, $this->asTenant($this->t['tenant'], fn () => LeadActivity::where('lead_id', $lead->id)->where('type', 'interest_changed')->count()));
        $this->assertSame(3, $this->asTenant($this->t['tenant'], fn () => AuditLog::where('action', 'lead.interest_set')->count()));
        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/interest", ['override' => 'maybe'])->assertStatus(422);
    }

    public function test_a_rep_sets_it_on_their_own_lead_only(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $other = $this->makeUser($this->t['tenant'], Role::Sales);
        $mine = $this->lead(['assigned_to' => $rep->id]);
        $theirs = $this->lead(['assigned_to' => $other->id]);

        $this->actingAs($rep)->postJson("/api/v1/leads/{$mine->uuid}/interest", ['override' => 'interested'])->assertOk();
        $this->actingAs($rep)->postJson("/api/v1/leads/{$theirs->uuid}/interest", ['override' => 'interested'])->assertForbidden();
    }

    // ------------------------------------------------------------ it follows events as they happen

    public function test_a_reply_moves_the_number_the_moment_it_arrives(): void
    {
        $lead = $this->lead();
        $this->message($lead, 'in');

        $this->assertSame(15, $this->fresh($lead)->interest_score);
    }

    public function test_a_brochure_open_a_page_view_a_visit_and_a_reservation_each_move_it(): void
    {
        $lead = $this->lead();

        $this->open($lead, 1);
        $this->assertSame(8, $this->fresh($lead)->interest_score);

        $this->asTenant($this->t['tenant'], fn () => TrackingEvent::create(['lead_id' => $lead->id, 'event_type' => 'project_view', 'occurred_at' => now(), 'visitor_id' => 'v_aaaaaaaaaaaaaaaaaaaa']));
        $this->assertSame(14, $this->fresh($lead)->interest_score);

        $this->asTenant($this->t['tenant'], fn () => Appointment::create(['lead_id' => $lead->id, 'scheduled_at' => now()->addDay(), 'status' => 'scheduled']));
        $this->assertSame('visit_ready', $this->fresh($lead)->interest_level);

        $this->asTenant($this->t['tenant'], fn () => Reservation::create(['lead_id' => $lead->id, 'status' => 'pending', 'reserved_at' => now()]));
        $this->assertSame('reservation_ready', $this->fresh($lead)->interest_level);
    }

    public function test_a_change_of_level_is_announced_once_for_the_rules_to_hear(): void
    {
        $lead = $this->lead();
        Event::fake([LeadSignal::class]);

        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['reservation_intent' => true])->save());
        $this->asTenant($this->t['tenant'], fn () => app(InterestEngine::class)->refresh($this->fresh($lead)));

        Event::assertDispatchedTimes(LeadSignal::class, 1);
        Event::assertDispatched(LeadSignal::class, fn (LeadSignal $e) => $e->signal === 'lead.interest_changed' && $e->context['from'] === 'cold' && $e->context['to'] === 'reservation_ready');
    }

    public function test_a_rule_may_listen_for_it_and_read_the_level(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/automation/rules', [
            'name' => 'تنبيه الاهتمام العالي', 'trigger' => 'lead.interest_changed', 'is_enabled' => true,
            'conditions' => [['field' => 'interest_level', 'operator' => 'in', 'value' => ['hot', 'visit_ready', 'reservation_ready']]],
            'actions' => [['type' => 'notify', 'audience' => 'managers', 'message' => 'عميل مهتم جدًا']],
        ])->assertCreated();
    }

    // ------------------------------------------------------------ lists

    public function test_the_list_filters_by_level_and_the_interested_view_counts_what_it_lists(): void
    {
        $hot = $this->lead(['full_name' => 'ساخن']);
        $this->asTenant($this->t['tenant'], fn () => $hot->forceFill(['reservation_intent' => true])->save());
        $this->lead(['full_name' => 'بارد']);
        $won = $this->lead(['full_name' => 'مغلق', 'status' => 'won']);
        $this->asTenant($this->t['tenant'], fn () => app(InterestEngine::class)->refresh($this->fresh($won)));

        $byLevel = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads?interest_level[]=reservation_ready')->assertOk()->json('data');
        $this->assertContains('ساخن', array_column($byLevel, 'full_name'));

        $segments = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads/segments')->json('data');
        $view = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads?view=interested')->assertOk()->json('data');

        $this->assertSame(1, $segments['interested'], 'open leads only: the won one is done');
        $this->assertCount($segments['interested'], $view);
        $this->assertSame('reservation_ready', $byLevel[0]['interest_level']);
    }

    // ------------------------------------------------------------ decay

    public function test_the_hourly_refresh_cools_what_silence_made_stale_and_leaves_the_fresh_alone(): void
    {
        $stale = $this->lead();
        $this->open($stale, 2);
        $fresh = $this->lead();
        $this->asTenant($this->t['tenant'], function () use ($stale, $fresh) {
            // Two weeks and a day of silence since the last thing they did, with a number nobody has re-read.
            LeadEngagement::where('lead_id', $stale->id)->update(['happened_at' => now()->subDays(15)]);
            $stale->forceFill(['interest_at' => now()->subDay(), 'created_at' => now()->subDays(40)])->saveQuietly();
            $fresh->forceFill(['interest_at' => now()->subMinutes(10), 'interest_score' => 33])->saveQuietly();
        });
        $before = $this->fresh($stale)->interest_score;

        Artisan::call('leadflow:refresh-interest');

        $this->assertLessThan($before, $this->fresh($stale)->interest_score);
        $this->assertSame(33, $this->fresh($fresh)->interest_score, 'read ten minutes ago: not read again');
        $this->assertStringContainsString('re-read', Artisan::output());
    }
}
