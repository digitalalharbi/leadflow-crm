<?php

namespace App\Domain\Leads;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\FileShare;
use App\Models\Lead;
use App\Models\LeadAnswer;
use App\Models\LeadEngagement;
use App\Models\LeadFact;
use App\Models\LeadMessage;
use App\Models\ProjectFile;
use Illuminate\Support\Collection;

/**
 * Seven numbers that say how a lead is doing, each 0–100, each from facts the
 * product already holds — nothing guessed, nothing learned.
 *
 *   health          one number for "is this lead alive and worth my time"
 *   intent          how much they have said or done that points at buying
 *   engagement      how much they are interacting with what we send
 *   response        how likely a message is to be answered
 *   staleness       how long since anything happened (higher is worse)
 *   contactability  can we actually reach them, and on how many channels
 *   completeness    how much we know about them
 *
 * Computed for a page of leads at once — a handful of grouped queries, never
 * one per lead — and attached to each as the `intelligence` relation, which
 * is where `NextBestAction` and the work queue read it from.
 */
class LeadIntelligence
{
    /** Days of silence at which staleness reads 100. */
    private const STALE_AT_DAYS = 14;

    /** Engagement is counted over this many days. */
    private const WINDOW_DAYS = 30;

    /** @var array<string,int> */
    private const ENGAGEMENT_POINTS = [
        LeadEngagement::FILE_OPENED => 15,
        LeadEngagement::EMAIL_OPENED => 5,
        LeadEngagement::LINK_CLICKED => 12,
        LeadEngagement::WHATSAPP_REPLIED => 25,
    ];

    /**
     * Computes and attaches the `intelligence` relation to every lead.
     *
     * @param  Collection<int,Lead>  $leads
     */
    public function attach(Collection $leads, bool $withTrend = false): Collection
    {
        foreach ($this->many($leads, $withTrend) as $id => $intel) {
            $leads->firstWhere('id', $id)?->setRelation('intelligence', $intel);
        }

        return $leads;
    }

    public function for(Lead $lead, bool $withTrend = true): array
    {
        return $this->many(collect([$lead]), $withTrend)[$lead->id];
    }

    /**
     * @param  Collection<int,Lead>  $leads
     * @return array<int,array<string,mixed>>
     */
    public function many(Collection $leads, bool $withTrend = false): array
    {
        if ($leads->isEmpty()) {
            return [];
        }

        $ids = $leads->pluck('id')->all();
        $since = now()->subDays(self::WINDOW_DAYS);

        $engagements = LeadEngagement::withoutGlobalScopes()
            ->whereIn('lead_id', $ids)->where('happened_at', '>=', $since)
            ->get(['lead_id', 'type', 'happened_at'])->groupBy('lead_id');

        $inbound = LeadMessage::withoutGlobalScopes()
            ->whereIn('lead_id', $ids)->where('direction', MessageDirection::Inbound->value)->where('created_at', '>=', $since)
            ->get(['lead_id', 'channel', 'created_at'])->groupBy('lead_id');

        $outbound = LeadMessage::withoutGlobalScopes()
            ->whereIn('lead_id', $ids)->where('direction', MessageDirection::Outbound->value)->where('created_at', '>=', $since)
            ->selectRaw('lead_id, count(*) as n')->groupBy('lead_id')->pluck('n', 'lead_id');

        // What the conversation said: only the two kinds that move intent.
        $said = LeadFact::withoutGlobalScopes()->whereIn('lead_id', $ids)->whereIn('kind', ['intent', 'appointment_intent'])
            ->get(['lead_id', 'kind', 'value'])->groupBy('lead_id');
        $answered = LeadAnswer::withoutGlobalScopes()->whereIn('lead_id', $ids)->selectRaw('lead_id, count(*) as n')->groupBy('lead_id')->pluck('n', 'lead_id');
        $shared = FileShare::withoutGlobalScopes()->whereIn('lead_id', $ids)->pluck('lead_id')->flip();
        $brochureProjects = ProjectFile::withoutGlobalScopes()
            ->whereIn('project_id', $leads->pluck('project_id')->filter()->unique()->all())
            ->where('kind', 'brochure')->where('is_active', true)->pluck('project_id')->flip();

        $out = [];

        foreach ($leads as $lead) {
            $events = $engagements->get($lead->id, collect());
            $messages = $inbound->get($lead->id, collect());

            $counts = [
                'file_opened' => $events->where('type', LeadEngagement::FILE_OPENED)->count(),
                'email_opened' => $events->where('type', LeadEngagement::EMAIL_OPENED)->count(),
                'link_clicked' => $events->where('type', LeadEngagement::LINK_CLICKED)->count(),
                'replies' => $messages->count(),
                'whatsapp_replies' => $messages->where('channel', MessageChannel::Whatsapp)->count(),
            ];

            $last = collect([$lead->last_contact_at, $messages->max('created_at'), $events->max('happened_at'), $lead->created_at])
                ->filter()->max();
            $days = $last ? (int) $last->diffInDays(now()) : 0;

            $engagement = $this->engagement($events, $counts['replies']);
            $channels = $this->channels($lead);
            $contactability = $this->contactability($channels);
            $completeness = $this->completeness($lead, (int) ($answered[$lead->id] ?? 0));
            $intent = $this->intent($lead, $counts, $said->get($lead->id, collect()));
            $staleness = (int) min(100, round($days / self::STALE_AT_DAYS * 100));
            $response = $this->response($lead, $engagement, $staleness, $contactability, $counts['replies'] > 0);
            $health = $this->health($lead, $engagement, $intent, $staleness, $contactability, $completeness);

            $out[$lead->id] = [
                'health' => ['value' => $health, 'level' => $this->level($health)],
                'intent' => ['value' => $intent, 'level' => $this->level($intent)],
                'engagement' => ['value' => $engagement, 'level' => $this->level($engagement)],
                'response' => ['value' => $response, 'level' => $this->level($response)],
                // Inverted: a high staleness is the bad one.
                'staleness' => ['value' => $staleness, 'days' => $days, 'level' => $this->level(100 - $staleness)],
                'contactability' => ['value' => $contactability, 'level' => $this->level($contactability), 'channels' => $channels],
                'completeness' => ['value' => $completeness['value'], 'level' => $this->level($completeness['value']), 'missing' => $completeness['missing']],
                'counts' => $counts + ['outbound_30d' => (int) ($outbound[$lead->id] ?? 0)],
                'brochure' => [
                    'available' => $lead->project_id !== null && isset($brochureProjects[$lead->project_id]),
                    'shared' => isset($shared[$lead->id]),
                ],
                'reason' => $this->reason($lead, $counts),
                'trend' => $withTrend ? $this->trend($events, $messages) : null,
            ];
        }

        return $out;
    }

    private function engagement(Collection $events, int $replies): int
    {
        $points = $events->sum(fn ($e) => self::ENGAGEMENT_POINTS[$e->type] ?? 0) + $replies * 20;

        return (int) min(100, $points);
    }

    /** @return array{whatsapp:bool,email:bool,call:bool} */
    private function channels(Lead $lead): array
    {
        $phone = filled($lead->phone_normalized) || filled($lead->phone);

        return [
            'whatsapp' => $phone && $lead->whatsapp_opt_out_at === null,
            'email' => filled($lead->email) && $lead->email_opt_out_at === null && $lead->email_bounced_at === null,
            'call' => $phone && ! ($lead->status?->value === 'invalid'),
        ];
    }

    private function contactability(array $channels): int
    {
        $count = count(array_filter($channels));

        return match (true) {
            $count >= 3 => 100,
            $count === 2 => 80,
            $count === 1 => 45,
            default => 0,
        };
    }

    /** @return array{value:int, missing:list<string>} */
    private function completeness(Lead $lead, int $answers): array
    {
        $checks = [
            'name' => [filled($lead->full_name), 15],
            'phone' => [filled($lead->phone_normalized) || filled($lead->phone), 25],
            'email' => [filled($lead->email), 15],
            'city' => [filled($lead->city), 10],
            'project' => [$lead->project_id !== null, 10],
            'budget' => [$lead->budget_min !== null || $lead->budget_max !== null, 10],
            'answers' => [$answers > 0, 15],
        ];

        $value = 0;
        $missing = [];

        foreach ($checks as $key => [$has, $weight]) {
            $has ? $value += $weight : $missing[] = $key;
        }

        return ['value' => $value, 'missing' => $missing];
    }

    private function intent(Lead $lead, array $counts, Collection $said): int
    {
        $spoken = $said->pluck('value', 'kind');

        // A customer who has said no, or that this is the wrong person, has no buying intent to measure.
        if ($lead->wrong_fit || $lead->status?->value === 'invalid' || in_array($spoken['intent'] ?? null, ['not_interested', 'wrong_contact'], true)) {
            return 0;
        }

        $points = 0;
        $points += $lead->appointment_interest ? 35 : (($spoken['appointment_intent'] ?? null) === 'requested' ? 25 : 0);
        $points += match ($spoken['intent'] ?? null) {
            'ready_to_buy' => 20,
            'interested', 'callback_request' => 8,
            default => 0,
        };
        $points += $lead->qualification_status?->value === 'qualified' ? 25 : 0;
        $points += $counts['replies'] > 0 || $lead->first_response_at !== null ? 15 : 0;
        $points += $counts['file_opened'] >= 2 ? 12 : ($counts['file_opened'] === 1 ? 6 : 0);
        $points += $lead->needs_human_reason === 'asked_for_human' ? 10 : 0;
        $points += $counts['link_clicked'] > 0 ? 5 : 0;
        $points += $lead->budget_max !== null ? 5 : 0;
        $points += in_array($lead->status?->value, ['qualified', 'appointment', 'negotiation'], true) ? 10 : 0;
        $points -= $lead->needs_human_reason === 'no_reply' ? 10 : 0;

        return max(0, min(100, $points));
    }

    private function response(Lead $lead, int $engagement, int $staleness, int $contactability, bool $replied): int
    {
        if ($contactability === 0) {
            return 0;
        }

        $value = 25 + $engagement * 0.35 + ($replied ? 25 : 0) + $contactability * 0.1 - $staleness * 0.3;

        return (int) max(5, min(95, round($value)));
    }

    private function health(Lead $lead, int $engagement, int $intent, int $staleness, int $contactability, array $completeness): int
    {
        $value = $engagement * 0.30 + $intent * 0.25 + (100 - $staleness) * 0.20 + $contactability * 0.15 + $completeness['value'] * 0.10;

        if ($lead->status?->isClosed()) {
            return 0;
        }

        return (int) max(0, min(100, round($value)));
    }

    private function level(int $value): string
    {
        return match (true) {
            $value >= 67 => 'high',
            $value >= 34 => 'medium',
            default => 'low',
        };
    }

    /**
     * "ردّ على واتساب + فتح البروشور مرتين" — what the customer did, in a few
     * words, strongest first. The same line explains a suggestion and a rank.
     */
    private function reason(Lead $lead, array $counts): ?string
    {
        $parts = [];

        if ($counts['whatsapp_replies'] > 0) {
            $parts[] = 'ردّ على واتساب';
        } elseif ($counts['replies'] > 0 || $lead->first_response_at !== null) {
            $parts[] = 'ردّ على الرسائل';
        }

        if ($lead->appointment_interest) {
            $parts[] = 'طلب موعدًا';
        }

        $parts[] = match (true) {
            $counts['file_opened'] === 1 => 'فتح البروشور',
            $counts['file_opened'] === 2 => 'فتح البروشور مرتين',
            $counts['file_opened'] > 2 => "فتح البروشور {$counts['file_opened']} مرات",
            default => null,
        };

        if ($counts['link_clicked'] > 0) {
            $parts[] = 'نقر على رابط';
        } elseif ($counts['email_opened'] > 0) {
            $parts[] = 'فتح البريد';
        }

        if ($lead->qualification_status?->value === 'qualified') {
            $parts[] = 'اجتاز التأهيل';
        }

        $parts = array_values(array_filter($parts));

        return $parts === [] ? null : implode(' + ', array_slice($parts, 0, 3));
    }

    /** Events per day for the last two weeks, oldest first. */
    private function trend(Collection $events, Collection $messages): array
    {
        $days = collect(range(13, 0))->map(fn (int $ago) => now()->subDays($ago)->toDateString());
        $perDay = $events->map(fn ($e) => $e->happened_at->toDateString())
            ->merge($messages->map(fn ($m) => $m->created_at->toDateString()))
            ->countBy();

        return $days->map(fn (string $day) => (int) ($perDay[$day] ?? 0))->all();
    }
}
