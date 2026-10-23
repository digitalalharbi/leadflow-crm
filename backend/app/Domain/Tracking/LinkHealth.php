<?php

namespace App\Domain\Tracking;

use App\Models\DestinationDelivery;
use App\Models\LeadSourceEvent;
use App\Models\TrackingDestination;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use Illuminate\Support\Carbon;

/**
 * Can this link be trusted? Healthy, warning or critical — and why, check by check.
 *
 * A tracking link that quietly stops crediting is worse than one that never worked, so the things that make credit
 * fail are looked at on their own: nothing has arrived, the UTMs the report groups on are missing, the platform's
 * click id never made it (so the platform cannot credit the ad), the page's tag is not set up, the server's copies are
 * failing, the same customer keeps arriving, or a form submit left a lead with no trail.
 */
class LinkHealth
{
    /** Channels whose reports group on UTMs. */
    private const UTM_CHANNELS = ['meta', 'tiktok', 'snapchat', 'google', 'x', 'linkedin', 'influencer', 'email', 'sms', 'portal'];

    /** The click id each ad platform issues, so a sale can be credited back to its ad. */
    private const CLICK_IDS = ['meta' => ['fbclid'], 'tiktok' => ['ttclid'], 'snapchat' => ['sccid'], 'google' => ['gclid', 'gbraid', 'wbraid'], 'linkedin' => ['li_fat_id'], 'x' => ['twclid']];

    /** @return array{status:string, checks:list<array<string,mixed>>} */
    public function for(TrackingLink $link): array
    {
        $checks = [
            $this->lastEvent($link),
            $this->utm($link),
            $this->clickId($link),
            $this->pixel($link),
            $this->serverEvents($link),
            $this->duplicates($link),
            $this->unattributed($link),
        ];

        $states = array_column($checks, 'state');

        return ['status' => in_array('critical', $states, true) ? 'critical' : (in_array('warning', $states, true) ? 'warning' : 'healthy'), 'checks' => $checks];
    }

    /** @return array<string,mixed> */
    private function lastEvent(TrackingLink $link): array
    {
        $last = TrackingEvent::query()->where('tracking_link_id', $link->id)->max('occurred_at');
        $days = $last ? (int) now()->diffInDays($last, true) : null;

        return ['key' => 'last_event', 'state' => $last === null || $days > 14 ? 'warning' : 'ok', 'last_event_at' => $last ? Carbon::parse($last)->toIso8601String() : null, 'days' => $days];
    }

    /** @return array<string,mixed> */
    private function utm(TrackingLink $link): array
    {
        if (! in_array($link->channel, self::UTM_CHANNELS, true)) {
            return ['key' => 'utm', 'state' => 'ok', 'missing' => []];
        }

        $missing = array_values(array_filter(['utm_source', 'utm_medium', 'utm_campaign'], fn (string $k) => blank($link->{$k})));

        return ['key' => 'utm', 'state' => $missing === [] ? 'ok' : 'warning', 'missing' => $missing];
    }

    /** @return array<string,mixed> */
    private function clickId(TrackingLink $link): array
    {
        $expected = self::CLICK_IDS[$link->channel] ?? null;

        if ($expected === null) {
            return ['key' => 'click_id', 'state' => 'ok', 'expected' => [], 'clicks' => 0, 'with_id' => 0];
        }

        $row = TrackingEvent::query()->where('tracking_link_id', $link->id)->where('occurred_at', '>=', now()->subDays(7))
            ->whereRaw("(event_type = 'click' or meta->>'via' = 'link')")
            ->selectRaw('count(*) as clicks, count(*) filter (where click_id is not null) as with_id')->first();

        $clicks = (int) $row->clicks;
        $with = (int) $row->with_id;

        $state = match (true) {
            $clicks < 10 => 'ok',
            $with === 0 && $clicks >= 20 => 'critical',
            $with / $clicks < 0.2 => 'warning',
            default => 'ok',
        };

        return ['key' => 'click_id', 'state' => $state, 'expected' => $expected, 'clicks' => $clicks, 'with_id' => $with];
    }

    /** @return array<string,mixed> */
    private function pixel(TrackingLink $link): array
    {
        if ($link->mode !== 'landing') {
            return ['key' => 'pixel', 'state' => 'ok', 'tags' => []];
        }

        $tags = TrackingDestination::query()->where('is_enabled', true)->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $link->project_id))->get()
            ->filter(fn (TrackingDestination $d) => $d->destinationKind()->browser());

        $broken = $tags->filter(fn (TrackingDestination $d) => $d->destinationKind()->configErrors((array) $d->config) !== []);

        return [
            'key' => 'pixel',
            'state' => $tags->isEmpty() || $broken->isNotEmpty() ? 'warning' : 'ok',
            'tags' => $tags->map(fn (TrackingDestination $d) => ['kind' => $d->kind, 'ok' => ! $broken->contains($d)])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function serverEvents(TrackingLink $link): array
    {
        $rows = DestinationDelivery::query()->join('tracking_destinations as d', 'd.id', '=', 'destination_deliveries.destination_id')
            ->where('destination_deliveries.created_at', '>=', now()->subDays(7))
            ->where(fn ($q) => $q->whereNull('d.project_id')->orWhere('d.project_id', $link->project_id))
            ->selectRaw('d.name, destination_deliveries.status, count(*) as n')->groupBy('d.name', 'destination_deliveries.status')->get();

        $total = (int) $rows->sum('n');
        $failed = (int) $rows->where('status', 'failed')->sum('n');
        $waiting = (int) $rows->where('status', 'awaiting_credentials')->sum('n');
        $failing = $rows->where('status', 'failed')->pluck('name')->unique()->values()->all();

        $state = match (true) {
            $failed >= 5 && $total > 0 && $failed / $total >= 0.5 => 'critical',
            $failed > 0 || $waiting > 0 => 'warning',
            default => 'ok',
        };

        return ['key' => 'server_events', 'state' => $state, 'total' => $total, 'failed' => $failed, 'awaiting_credentials' => $waiting, 'failing' => $failing];
    }

    /** @return array<string,mixed> */
    private function duplicates(TrackingLink $link): array
    {
        $row = LeadSourceEvent::query()->whereIn('lead_id', fn ($q) => $q->select('id')->from('leads')->where('first_touch_link_id', $link->id))
            ->where('created_at', '>=', now()->subDays(30))->selectRaw('count(*) as n, count(*) filter (where was_duplicate) as dup')->first();

        $n = (int) $row->n;
        $dup = (int) $row->dup;

        return ['key' => 'duplicates', 'state' => $n >= 5 && $dup / $n >= 0.3 ? 'warning' : 'ok', 'total' => $n, 'duplicates' => $dup];
    }

    /** @return array<string,mixed> */
    private function unattributed(TrackingLink $link): array
    {
        $n = (int) TrackingEvent::query()->where('tracking_link_id', $link->id)->where('event_type', 'form_submit')->where('occurred_at', '>=', now()->subDays(30))
            ->whereNotNull('lead_id')->whereIn('lead_id', fn ($q) => $q->select('id')->from('leads')->whereNull('first_touch_link_id'))->count();

        return ['key' => 'unattributed', 'state' => $n > 0 ? 'critical' : 'ok', 'leads' => $n];
    }
}
