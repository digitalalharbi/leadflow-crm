<?php

namespace App\Domain\Tracking;

use App\DataTransferObjects\IncomingLead;
use App\Models\Lead;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who gets the credit.
 *
 * A lead's trail is the ordered list of links that touched it — each click, visit, WhatsApp tap
 * or call tap that carried a tracking link. The four models are four ways of dividing one lead's
 * worth of credit across that list:
 *
 *   first     — everything to the first link
 *   last      — everything to the last link
 *   linear    — an equal share to every touch
 *   position  — 40% first, 40% last, the middle shares 20%
 *
 * First and last are what the screens lead with; linear and position are here and correct, so a
 * workspace that wants them has them without a rebuild.
 */
class Attribution
{
    public const MODELS = ['first', 'last', 'linear', 'position'];

    /** Platforms that describe delivery rather than origin. */
    private const GENERIC_PLATFORMS = ['webhook', 'website', 'other', 'manual', 'import', 'google_sheets'];

    /** A link's channel, as the platform a lead is filed under. Channels with no platform stay unset. */
    private const PLATFORM_OF = ['meta' => 'meta', 'tiktok' => 'tiktok', 'snapchat' => 'snapchat', 'google' => 'google_ads', 'linkedin' => 'linkedin', 'landing_page' => 'landing_page'];

    /**
     * Put a lead on the trail it left: its earlier events, its first and last touch, and what the
     * links it came through already say about it (project, campaign, ad).
     */
    public function attachLead(Lead $lead, IncomingLead $incoming, ?TrackingLink $viaToken = null): void
    {
        $visitor = Visitor::clean($incoming->visitorId);

        if ($visitor !== null) {
            TrackingEvent::where('visitor_id', $visitor)->whereNull('lead_id')->update(['lead_id' => $lead->id]);
        }

        $touches = $this->touchLinks($lead->id, $visitor);

        // A lead that arrived with a token but no recorded events (a server-to-server form post)
        // still came through that link.
        if ($touches->isEmpty() && $viaToken !== null) {
            $touches = collect([['link' => $viaToken, 'at' => now()]]);
        }

        $first = $touches->first();
        $last = $touches->last();

        $updates = array_filter([
            'visitor_id' => $visitor,
            'first_touch_link_id' => $first['link']->id ?? null,
            'last_touch_link_id' => $last['link']->id ?? null,
            'first_touch_at' => $first['at'] ?? null,
            'last_touch_at' => $last['at'] ?? null,
            'referrer' => $incoming->referrer,
            'landing_url' => $incoming->landingUrl,
            'device' => $incoming->device,
            'browser' => $incoming->browser,
        ], fn ($v) => $v !== null);

        // What the first link already says, for whatever the lead did not bring with it.
        if ($first !== null) {
            /** @var TrackingLink $link */
            $link = $first['link'];
            $updates += array_filter([
                // A form that arrived by webhook says how it was delivered, not where the customer came from.
                'platform' => (blank($lead->platform) || in_array($lead->platform, self::GENERIC_PLATFORMS, true)) ? self::PLATFORM_OF[$link->channel] ?? null : null,
                'project_id' => $lead->project_id === null ? $link->project_id : null,
                'campaign_ref_id' => $lead->campaign_ref_id === null ? $link->campaign_id : null,
                'ad_ref_id' => $lead->ad_ref_id === null ? $link->ad_id : null,
                'interested_property_id' => $lead->interested_property_id === null ? $link->property_id : null,
                'utm_source' => blank($lead->utm_source) ? $link->utm_source : null,
                'utm_medium' => blank($lead->utm_medium) ? $link->utm_medium : null,
                'utm_campaign' => blank($lead->utm_campaign) ? $link->utm_campaign : null,
                'utm_content' => blank($lead->utm_content) ? $link->utm_content : null,
            ], fn ($v) => $v !== null);
        }

        if ($updates !== []) {
            $lead->forceFill($updates)->saveQuietly();
        }

        if ($last !== null) {
            app(EventRecorder::class)->record(EventType::FormSubmit, [
                'lead_id' => $lead->id,
                'visitor_id' => $visitor,
                'tracking_link_id' => $last['link']->id,
                'project_id' => $lead->project_id,
                'url' => $incoming->landingUrl,
                'dedupe_key' => "form_submit:{$lead->id}",
            ]);
        }
    }

    /**
     * The links that touched this lead, oldest first, a link that touched twice in a row counted once.
     *
     * @return Collection<int, array{link: TrackingLink, at: Carbon}>
     */
    public function touchLinks(int $leadId, ?string $visitor = null): Collection
    {
        $events = TrackingEvent::query()
            ->whereIn('event_type', EventType::touchValues())
            ->whereNotNull('tracking_link_id')
            ->where(fn ($q) => $q->where('lead_id', $leadId)->when($visitor, fn ($w) => $w->orWhere('visitor_id', $visitor)))
            ->orderBy('occurred_at')->orderBy('id')
            ->get(['tracking_link_id', 'occurred_at']);

        $links = TrackingLink::withTrashed()->whereIn('id', $events->pluck('tracking_link_id')->unique())->get()->keyBy('id');

        return $events
            ->map(fn (TrackingEvent $e) => ['link' => $links->get($e->tracking_link_id), 'at' => $e->occurred_at])
            ->filter(fn (array $t) => $t['link'] !== null)
            ->reduce(function (Collection $carry, array $touch) {
                return $carry->isNotEmpty() && $carry->last()['link']->id === $touch['link']->id ? $carry : $carry->push($touch);
            }, collect())
            ->values();
    }

    /**
     * How one lead's credit divides across its touches.
     *
     * @param  array<int,int>  $linkIds  the touching links, oldest first
     * @return array<int,float> link id → share, summing to 1
     */
    public static function weights(array $linkIds, string $model): array
    {
        $n = count($linkIds);

        if ($n === 0) {
            return [];
        }

        $shares = array_fill(0, $n, 0.0);

        match (true) {
            $n === 1 => $shares[0] = 1.0,
            $model === 'first' => $shares[0] = 1.0,
            $model === 'last' => $shares[$n - 1] = 1.0,
            $model === 'position' && $n === 2 => [$shares[0], $shares[1]] = [0.5, 0.5],
            $model === 'position' => (function () use (&$shares, $n) {
                $shares[0] = 0.4;
                $shares[$n - 1] = 0.4;
                foreach (range(1, $n - 2) as $i) {
                    $shares[$i] = 0.2 / ($n - 2);
                }
            })(),
            default => $shares = array_fill(0, $n, 1 / $n),
        };

        // Only links that were credited: a link that touched and got nothing is not a bucket.
        $byLink = [];
        foreach ($linkIds as $i => $id) {
            if ($shares[$i] > 0) {
                $byLink[$id] = ($byLink[$id] ?? 0.0) + $shares[$i];
            }
        }

        return $byLink;
    }
}
