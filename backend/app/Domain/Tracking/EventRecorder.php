<?php

namespace App\Domain\Tracking;

use App\Domain\Tracking\Destinations\DestinationFanOut;
use App\Models\Lead;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Models\VisitorSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes events to the trail — once.
 *
 * Every event may carry a dedupe key, and a second event with the same key is the first one: a
 * landing page that retries a beacon, a reservation reported by two code paths, or a sale saved
 * twice must not count twice. The unique index is the guard; the catch makes it a no-op rather
 * than an error.
 */
class EventRecorder
{
    /**
     * @param  array<string,mixed>  $attributes  columns of tracking_events
     */
    public function record(EventType $type, array $attributes = []): ?TrackingEvent
    {
        // An event has an id the browser, the server and every destination share, so one thing that happened is one
        // thing everywhere. A caller that names the id means "this event, once": the id is the idempotency key too.
        if (filled($attributes['event_id'] ?? null) && blank($attributes['dedupe_key'] ?? null)) {
            $attributes['dedupe_key'] = 'evt:'.$attributes['event_id'];
        }

        $attributes['event_id'] = filled($attributes['event_id'] ?? null) ? $attributes['event_id'] : (string) Str::uuid();

        try {
            // In its own savepoint: on Postgres a unique violation aborts the whole surrounding
            // transaction, and a duplicate event must not take a sale or a brochure open with it.
            $event = DB::transaction(fn () => TrackingEvent::create(['event_type' => $type->value, 'occurred_at' => now()] + $attributes));

            // Told to whoever asked to hear of it — queued, and never allowed to cost the event.
            app(DestinationFanOut::class)->queue($event);

            return $event;
        } catch (QueryException $e) {
            // 23505: the same dedupe key arrived twice. That is the point of the key.
            if (($e->errorInfo[0] ?? null) === '23505') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * What the sales process did to a lead, put on the lead's own trail.
     *
     * Never allowed to break the thing that caused it: a sale is saved whether or not the trail
     * could be written.
     *
     * @param  array<string,mixed>  $meta
     */
    public function outcome(Lead $lead, EventType $type, array $meta = [], ?string $uniqueBy = null): ?TrackingEvent
    {
        try {
            return $this->record($type, [
                'lead_id' => $lead->id,
                'visitor_id' => $lead->visitor_id,
                'tracking_link_id' => $lead->last_touch_link_id ?? $lead->first_touch_link_id,
                'project_id' => $lead->project_id,
                'property_id' => $lead->interested_property_id,
                'session_id' => $lead->visitor_id ? VisitorSession::where('visitor_id', $lead->visitor_id)->latest('last_seen_at')->value('session_id') : null,
                'campaign_id' => $lead->campaign_ref_id,
                'platform' => $lead->platform instanceof \BackedEnum ? $lead->platform->value : $lead->platform,
                'consent' => $lead->tracking_consent,
                'meta' => $meta ?: null,
                'dedupe_key' => "{$type->value}:{$lead->id}".($uniqueBy !== null ? ":{$uniqueBy}" : ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('tracking_outcome_failed', ['lead' => $lead->id, 'type' => $type->value, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** The link a token names, in the current tenant. */
    public function link(?string $token): ?TrackingLink
    {
        return filled($token) ? TrackingLink::where('token', $token)->first() : null;
    }
}
