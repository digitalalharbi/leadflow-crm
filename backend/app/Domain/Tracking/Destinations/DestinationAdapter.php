<?php

namespace App\Domain\Tracking\Destinations;

use App\Models\TrackingDestination;

/** One place's server API, in its own words. */
interface DestinationAdapter
{
    /**
     * Why this event is not for this destination's server API, or null when it is.
     *
     *   browser_covers   the page's own tag already reports it
     *   via_conversions  the offline-conversion pipeline for this ad platform carries it
     */
    public function declines(string $canonicalEvent): ?string;

    /**
     * @param  array<string,mixed>  $payload  the neutral payload
     * @return array{ok:bool, summary:array<string,mixed>, error?:string} `summary` is what was sent with every identifier removed
     */
    public function send(TrackingDestination $destination, array $payload): array;
}
