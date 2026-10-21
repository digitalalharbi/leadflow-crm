<?php

namespace App\Domain\Leads;

use App\Domain\Conversation\Vocabulary;
use App\Enums\LeadStatus;
use App\Events\LeadSignal;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\LeadEngagement;
use App\Models\LeadFact;
use App\Models\LeadMessage;
use App\Models\Reservation;
use App\Models\TrackingEvent;
use Illuminate\Support\Facades\Log;

/**
 * How interested a lead is, from what they do — a number, a level, and the reasons.
 *
 * Lead score asks "is this a good fit?"; this asks "are they coming toward us?". Every signal is a
 * thing somebody could point at (a reply, a brochure opened twice, the price asked, a visit
 * requested, a reservation asked for) and every one is worth a fixed, capped number of points, so
 * the screen can say why a lead is where it is and the same facts always give the same answer.
 *
 *   + replying · opening the brochure · coming back to the project page · asking the price ·
 *     asking about financing · asking for a visit · booking one · asking to reserve
 *   − answering nothing after three of our messages · no working way to reach them · unsubscribing ·
 *     going quiet for two weeks · saying they are not interested
 *
 * Six levels: cold, warm, interested, hot, visit ready (a visit is asked for or booked) and
 * reservation ready (they want to hold a unit). A person's "interested" is a floor and "not
 * interested" forces cold; a won lead is reservation ready by definition.
 */
class InterestEngine
{
    public const LEVELS = ['cold', 'warm', 'interested', 'hot', 'visit_ready', 'reservation_ready'];

    /** From this level up a lead is "interested" for the funnel, the queue and the reports. */
    public const INTERESTED = ['interested', 'hot', 'visit_ready', 'reservation_ready'];

    /** Score lines between the score-driven levels. */
    private const LINES = ['warm' => 20, 'interested' => 45, 'hot' => 70];

    private const WINDOW_DAYS = 30;

    private const QUIET_DAYS = 14;

    public static function rank(?string $level): int
    {
        $i = array_search($level, self::LEVELS, true);

        return $i === false ? 0 : $i;
    }

    /**
     * @return array{score:int, level:string, signals:list<array{key:string, points:int, count?:int}>}
     */
    public function evaluate(Lead $lead): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $signals = [];
        $add = function (string $key, int $points, ?int $count = null) use (&$signals) {
            if ($points !== 0) {
                $signals[] = ['key' => $key, 'points' => $points] + ($count !== null ? ['count' => $count] : []);
            }
        };

        // ---- what they said and did
        $inbound = LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->where('direction', 'inbound');
        $replies = (clone $inbound)->where('created_at', '>=', $since)->count();
        $lastInbound = (clone $inbound)->max('created_at');
        $everReplied = $replies > 0 || (clone $inbound)->exists();

        $add('replied', match (true) {
            $replies >= 5 => 20, $replies >= 2 => 15, $replies === 1 => 10, default => 0
        }, $replies);

        if ($lastInbound && now()->diffInHours($lastInbound, true) <= 48) {
            $add('recent_reply', 5);
        }

        $engagement = LeadEngagement::withoutGlobalScopes()->where('lead_id', $lead->id)->where('happened_at', '>=', $since)->get(['type', 'happened_at']);
        $opens = $engagement->where('type', LeadEngagement::FILE_OPENED)->count();
        $add('brochure_opened', match (true) {
            $opens >= 2 => 14, $opens === 1 => 8, default => 0
        }, $opens);
        $add('email_engaged', min(8, 4 * $engagement->whereIn('type', [LeadEngagement::EMAIL_OPENED, LeadEngagement::LINK_CLICKED])->count()));

        $web = TrackingEvent::withoutGlobalScopes()->where('lead_id', $lead->id)->where('occurred_at', '>=', $since)->whereIn('event_type', ['visit', 'project_view', 'property_view'])->get(['event_type', 'occurred_at']);
        $views = $web->whereIn('event_type', ['project_view', 'property_view'])->count();
        $add('project_viewed', match (true) {
            $views >= 3 => 10, $views >= 1 => 6, default => 0
        }, $views);
        $days = $web->map(fn ($e) => $e->occurred_at->toDateString())->unique()->count();
        $add('came_back', $days >= 2 ? 8 : 0, $days);

        // ---- what they asked
        $facts = LeadFact::withoutGlobalScopes()->where('lead_id', $lead->id)->get(['kind', 'value']);
        $inquiries = $facts->where('kind', Vocabulary::INQUIRY)->pluck('value');
        $add('asked_price', $inquiries->contains('price') ? 8 : 0);
        $add('asked_financing', $inquiries->contains('financing') ? 8 : 0);

        $visitAsked = $lead->appointment_interest === true || $facts->contains(fn ($f) => $f->kind === Vocabulary::APPOINTMENT && $f->value === 'requested');
        $add('asked_for_visit', $visitAsked ? 20 : 0);

        $appointments = Appointment::withoutGlobalScopes()->where('lead_id', $lead->id)->whereNotIn('status', ['cancelled'])->get(['status', 'scheduled_at']);
        $visitBooked = $appointments->contains(fn ($a) => in_array($a->status, Appointment::BOOKED, true) && $a->scheduled_at?->isFuture());
        // Booked means a time was agreed: a request nobody has answered yet is the "asked for a visit" line above,
        // and somebody who did not come has not booked anything.
        $add('visit_booked', $appointments->contains(fn ($a) => in_array($a->status, [...Appointment::BOOKED, Appointment::COMPLETED], true)) ? 25 : 0);
        $add('visited', $appointments->contains(fn ($a) => $a->status === 'completed') ? 10 : 0);

        $reservationOpen = Reservation::withoutGlobalScopes()->where('lead_id', $lead->id)->whereIn('status', ['pending', 'confirmed'])->exists();
        $reservationAsked = $lead->reservation_intent || $facts->contains(fn ($f) => $f->kind === Vocabulary::RESERVATION && $f->value === 'ready') || $reservationOpen;
        $add('asked_to_reserve', $reservationAsked ? 30 : ($facts->contains(fn ($f) => $f->kind === Vocabulary::RESERVATION && $f->value === 'interested') ? 12 : 0));

        $add('qualified', match ($lead->qualification_status?->value) {
            'qualified' => 10, 'partially_qualified' => 4, default => 0
        });

        // ---- what is going cold
        $unanswered = LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->where('direction', 'outbound')->whereNull('user_id')->where('status', '!=', 'failed')->count();
        if (! $everReplied && $unanswered >= 3) {
            $add('no_response', -15, $unanswered);
        }

        $reachable = ($lead->acceptsWhatsapp() && (filled($lead->phone_normalized) || filled($lead->phone))) || $lead->acceptsEmail();
        $unsubscribed = $lead->whatsapp_opt_out_at !== null && ($lead->email_opt_out_at !== null || blank($lead->email));
        $add('unsubscribed', $unsubscribed ? -25 : 0);
        $add('unreachable', ! $reachable && ! $unsubscribed ? -20 : 0);

        $lastTouch = collect([$lastInbound, $engagement->max('happened_at'), $web->max('occurred_at')])->filter()->max();
        $quiet = $lastTouch
            ? now()->diffInDays($lastTouch, true) >= self::QUIET_DAYS
            : ($lead->created_at !== null && now()->diffInDays($lead->created_at, true) >= self::QUIET_DAYS);
        $add('gone_quiet', $quiet ? -10 : 0);

        $intent = $facts->firstWhere('kind', Vocabulary::INTENT)?->value;
        $add('not_interested', $intent === 'not_interested' ? -40 : 0);
        $add('wrong_contact', $intent === 'wrong_contact' ? -60 : 0);

        $score = max(0, min(100, array_sum(array_column($signals, 'points'))));

        return [
            'score' => $score,
            'level' => $this->level($lead, $score, $intent, $unsubscribed, $visitAsked, $visitBooked, $reservationAsked),
            'signals' => $signals,
        ];
    }

    private function level(Lead $lead, int $score, ?string $intent, bool $unsubscribed, bool $visitAsked, bool $visitBooked, bool $reservationAsked): string
    {
        // A sale is the end of the road: reservation ready by definition.
        if ($lead->status === LeadStatus::Won) {
            return 'reservation_ready';
        }

        if ($lead->interest_override === 'not_interested' || in_array($intent, ['not_interested', 'wrong_contact'], true) || $unsubscribed) {
            return 'cold';
        }

        $level = match (true) {
            $reservationAsked => 'reservation_ready',
            // A booked visit is a commitment; a visit merely asked for needs a lead already warming up.
            $visitBooked, $visitAsked && $score >= 40 => 'visit_ready',
            $score >= self::LINES['hot'] => 'hot',
            $score >= self::LINES['interested'] => 'interested',
            $score >= self::LINES['warm'] => 'warm',
            default => 'cold',
        };

        // "Interested", said by a person, is a floor.
        if ($lead->interest_override === 'interested' && self::rank($level) < self::rank('interested')) {
            return 'interested';
        }

        return $level;
    }

    /** For the places that know a lead id and not a model: an event, a booking, a page view. */
    public static function touch(?int $leadId, string $reason): void
    {
        if ($leadId === null) {
            return;
        }

        $lead = Lead::withoutGlobalScopes()->whereNull('deleted_at')->find($leadId);

        $lead && app(self::class)->refresh($lead, $reason);
    }

    /**
     * Re-read the lead and keep the answer. Returns whether the LEVEL changed.
     *
     * Quiet saves, so nothing downstream re-scores the lead for a number that is not its score;
     * a change of level is announced as a signal instead, for the rules and the alerts to hear.
     */
    public function refresh(Lead $lead, string $reason = 'event'): bool
    {
        try {
            $before = $lead->interest_level ?: 'cold';
            $result = $this->evaluate($lead);

            $lead->forceFill([
                'interest_score' => $lead->interest_override === 'not_interested' ? min(10, $result['score']) : max($result['score'], $lead->interest_override === 'interested' ? self::LINES['interested'] : 0),
                'interest_level' => $result['level'],
                'interest_signals' => $result['signals'],
                'interest_at' => now(),
                'interest_level_changed_at' => $before !== $result['level'] ? now() : $lead->interest_level_changed_at,
            ])->saveQuietly();

            if ($before !== $result['level']) {
                LeadSignal::dispatch($lead->refresh(), 'lead.interest_changed', ['from' => $before, 'to' => $result['level'], 'reason' => $reason]);

                return true;
            }
        } catch (\Throwable $e) {
            // The number is a convenience; the event that asked for it has already happened.
            Log::warning('interest_refresh_failed', ['lead' => $lead->id, 'reason' => $reason, 'error' => $e->getMessage()]);
        }

        return false;
    }
}
