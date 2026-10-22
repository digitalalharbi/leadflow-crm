<?php

namespace App\Domain\Tracking;

use App\Models\TrackingLink;
use Illuminate\Support\Str;

/** Making links, and saying where one goes. */
class TrackingLinks
{
    /** A token short enough for a WhatsApp message, long enough that nobody guesses one. */
    public function newToken(): string
    {
        do {
            $token = Str::random(10);
        } while (TrackingLink::withoutGlobalScopes()->withTrashed()->where('token', $token)->exists());

        return $token;
    }

    /** @param  array<string,mixed>  $data */
    public function create(array $data, ?int $createdBy = null): TrackingLink
    {
        return TrackingLink::create($data + ['token' => $this->newToken(), 'created_by' => $createdBy]);
    }

    /**
     * Where /t/{token} sends the visitor: the link's own destination with the UTM set the link
     * carries (never over what the destination already has), and the visitor's identity so the
     * landing page can hand it back with the form.
     */
    public function destination(TrackingLink $link, string $visitorId): string
    {
        $url = (string) ($link->destination_url ?: config('app.frontend_url', config('app.url')));

        // A phone call and a WhatsApp chat are not pages: nothing can be appended to them.
        if (preg_match('#^(tel:|mailto:|https?://(wa\.me|api\.whatsapp\.com)/)#i', $url)) {
            return $url;
        }

        $params = array_filter([
            'utm_source' => $link->utm_source ?? $this->sourceOf($link),
            'utm_medium' => $link->utm_medium,
            'utm_campaign' => $link->utm_campaign,
            'utm_term' => $link->utm_term,
            'utm_content' => $link->utm_content,
        ], fn ($v) => filled($v));

        parse_str((string) parse_url($url, PHP_URL_QUERY), $existing);
        $params = array_diff_key($params, $existing) + ['mt_vid' => $visitorId, 'mt_t' => $link->token];

        $base = Str::before(Str::before($url, '#'), '?');
        $fragment = str_contains($url, '#') ? '#'.Str::after($url, '#') : '';
        $query = http_build_query($existing + $params);

        return $base.'?'.$query.$fragment;
    }

    /** The event a click on this link is: a call tap and a chat tap are not a page click. */
    public function clickEvent(TrackingLink $link): EventType
    {
        $url = strtolower((string) $link->destination_url);

        return match (true) {
            str_starts_with($url, 'tel:') => EventType::CallClick,
            str_contains($url, 'wa.me/') || str_contains($url, 'api.whatsapp.com/') => EventType::WhatsappClick,
            default => EventType::Click,
        };
    }

    private function sourceOf(TrackingLink $link): ?string
    {
        return in_array($link->channel, ['other', 'landing_page', 'qr'], true) ? null : $link->channel;
    }
}
