<?php

namespace App\Domain\Tracking\Destinations\Adapters;

use App\Domain\Tracking\Destinations\DestinationAdapter;
use App\Models\TrackingDestination;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What every destination's server call shares: a short timeout (an event must never hold a queue worker open), one
 * request, a result that says ok or why not, and a summary that keeps no identifier.
 */
abstract class AbstractAdapter implements DestinationAdapter
{
    protected int $timeoutSeconds = 8;

    /** Stage events the offline-conversion pipeline carries for the ad platforms. */
    protected const STAGES = ['qualified', 'interested', 'appointment_requested', 'appointment_booked', 'visit_completed', 'reservation_created', 'won', 'lost', 'revenue_recorded'];

    public function declines(string $canonicalEvent): ?string
    {
        return null;
    }

    /**
     * @param  array<string,string>  $headers
     * @param  array<string,mixed>|string  $body  an array is sent as JSON; a string is sent as it is (a signature is over exactly these bytes)
     * @return array{ok:bool, summary:array<string,mixed>, error?:string}
     */
    protected function post(string $url, array|string $body, array $headers = [], array $summary = []): array
    {
        try {
            $raw = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = Http::timeout($this->timeoutSeconds)->withHeaders($headers)->withBody($raw, 'application/json')->post($url);
        } catch (\Throwable $e) {
            // The message, never the payload.
            return ['ok' => false, 'summary' => $summary, 'error' => $e->getMessage()];
        }

        return $this->answer($response, $summary);
    }

    /** @return array{ok:bool, summary:array<string,mixed>, error?:string} */
    protected function answer(Response $response, array $summary): array
    {
        if ($response->successful()) {
            return ['ok' => true, 'summary' => $summary];
        }

        return ['ok' => false, 'summary' => $summary, 'error' => 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300)];
    }

    protected function credential(TrackingDestination $destination, string $key): string
    {
        return (string) (((array) $destination->credentials)[$key] ?? '');
    }

    protected function config(TrackingDestination $destination, string $key): string
    {
        return (string) (((array) $destination->config)[$key] ?? '');
    }
}
