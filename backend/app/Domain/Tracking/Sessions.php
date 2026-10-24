<?php

namespace App\Domain\Tracking;

use App\Models\TrackingLink;
use App\Models\VisitorSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * One visit, from the first touch to the last.
 *
 * A visitor (the long-lived anonymous id) has sessions (a stretch of activity ended by half an hour of
 * silence). The session remembers the link that first touched it, the link that touched it last, and
 * what each said — the platform click ids and the UTM set — because the first and last touch are what
 * attribution credits, and a click id that is not kept at the moment it arrives cannot be recovered.
 */
class Sessions
{
    public const COOKIE = 'mt_sid';

    public const IDLE_MINUTES = 30;

    public static function newId(): string
    {
        return 's_'.Str::lower(Str::random(20));
    }

    /** Only ids this class issued are trusted back. */
    public static function clean(?string $id): ?string
    {
        $id = trim((string) $id);

        return preg_match('/^s_[a-z0-9]{20}$/', $id) === 1 ? $id : null;
    }

    /**
     * Start a visit, or carry on the one the visitor is in.
     *
     * @param  array{click_ids?:array<string,string>, utm?:array<string,string>, landing_url?:?string, referrer?:?string, device?:?string, browser?:?string, os?:?string, ip_hash?:?string, consent?:?string}  $context
     */
    public function touch(string $visitor, ?string $sessionId, ?TrackingLink $link, array $context = []): VisitorSession
    {
        $sessionId = self::clean($sessionId);
        $session = $sessionId !== null ? VisitorSession::where('session_id', $sessionId)->where('visitor_id', $visitor)->first() : null;

        // A visitor who went quiet for half an hour is starting again, not carrying on.
        if ($session !== null && $session->last_seen_at->lt(now()->subMinutes(self::IDLE_MINUTES))) {
            $session = null;
            $sessionId = null;
        }

        $touch = $this->touchOf($link, $context);
        $changesLink = $link !== null && ($session === null || $session->last_link_id !== $link->id);

        if ($session === null) {
            return $this->start($visitor, $sessionId, $link, $touch, $context);
        }

        $updates = ['last_seen_at' => now(), 'events_count' => $session->events_count + 1];

        // A new link — or a click id that arrived with this one — is a new last touch; the first never changes.
        if ($changesLink || ($context['click_ids'] ?? []) !== []) {
            $updates += ['last_link_id' => $link?->id ?? $session->last_link_id, 'last_touch' => $touch];
        }

        if (($context['consent'] ?? null) !== null) {
            $updates['consent'] = $context['consent'];
        }

        $session->forceFill($updates)->save();

        return $session;
    }

    /** @param  array<string,mixed>  $touch */
    private function start(string $visitor, ?string $sessionId, ?TrackingLink $link, array $touch, array $context): VisitorSession
    {
        $attributes = [
            'visitor_id' => $visitor,
            'session_id' => $sessionId ?? self::newId(),
            'first_link_id' => $link?->id,
            'last_link_id' => $link?->id,
            'first_touch' => $touch,
            'last_touch' => $touch,
            'device' => $context['device'] ?? null,
            'browser' => $context['browser'] ?? null,
            'os' => $context['os'] ?? null,
            'ip_hash' => $context['ip_hash'] ?? null,
            'consent' => $context['consent'] ?? null,
            'events_count' => 1,
            'started_at' => now(),
            'last_seen_at' => now(),
        ];

        try {
            return VisitorSession::create($attributes);
        } catch (QueryException $e) {
            // Two requests of one visit raced to start it: the loser reads the winner's.
            if (($e->errorInfo[0] ?? null) === '23505' && ($existing = VisitorSession::where('session_id', $attributes['session_id'])->first()) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function touchOf(?TrackingLink $link, array $context): array
    {
        return array_filter([
            'link_id' => $link?->id,
            'at' => now()->toIso8601String(),
            'click_ids' => $context['click_ids'] ?? [],
            'utm' => $context['utm'] ?? [],
            'landing_url' => $context['landing_url'] ?? null,
            'referrer' => $context['referrer'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
