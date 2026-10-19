<?php

namespace App\Domain\Automation;

use App\Enums\LeadStatus;
use App\Events\LeadSignal;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\Tenant;

/**
 * "No reply" is not something that happens, so something has to notice it.
 *
 * For every open lead we last wrote to and who has not answered since, this
 * fires `lead.no_reply` as each moment passes — 15 minutes, an hour, four
 * hours, a day, three days, a week — once per moment per message, carrying how
 * long they have been quiet. A rule then says "quiet 15 min → reminder" in
 * those words. A new message from us starts the clock again.
 */
class NoReplySweeper
{
    public const MOMENTS = [15, 60, 240, 1440, 4320, 10080];

    public function sweep(Tenant $tenant): int
    {
        $fired = 0;
        $closed = array_map(fn (LeadStatus $s) => $s->value, array_filter(LeadStatus::cases(), fn (LeadStatus $s) => $s->isClosed()));

        Lead::query()
            ->whereNotIn('status', $closed)
            ->where('last_contact_at', '>=', now()->subMinutes(max(self::MOMENTS) + 60))
            ->orderBy('id')
            ->chunkById(200, function ($leads) use (&$fired) {
                foreach ($leads as $lead) {
                    $fired += $this->check($lead);
                }
            });

        return $fired;
    }

    private function check(Lead $lead): int
    {
        $last = LeadMessage::where('lead_id', $lead->id)->latest('id')->first(['id', 'direction', 'created_at']);

        // Nothing sent, or the last word was theirs: nobody is waiting on them.
        if (! $last || ($last->direction->value ?? $last->direction) !== 'outbound') {
            return 0;
        }

        $quiet = (int) $last->created_at->diffInMinutes(now(), true);
        $state = $lead->no_reply_state ?? [];
        $done = ($state['message'] ?? null) === $last->id ? ($state['fired'] ?? []) : [];
        $fired = 0;

        foreach (self::MOMENTS as $moment) {
            if ($quiet < $moment || in_array($moment, $done, true)) {
                continue;
            }

            $done[] = $moment;
            $lead->forceFill(['no_reply_state' => ['message' => $last->id, 'fired' => $done]])->saveQuietly();
            LeadSignal::dispatch($lead, 'lead.no_reply', ['no_reply_minutes' => $moment]);
            $fired++;
        }

        return $fired;
    }
}
