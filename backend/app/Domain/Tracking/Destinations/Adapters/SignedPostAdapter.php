<?php

namespace App\Domain\Tracking\Destinations\Adapters;

use App\Domain\Tracking\Destinations\SafeUrl;
use App\Models\TrackingDestination;

/**
 * The workspace's own endpoint: a webhook, or a Google Tag Manager server container. It receives the neutral payload
 * as JSON, signed with the secret the workspace set so the receiver can tell it is ours — the signature is over exactly
 * the bytes sent. The address is checked again, against what its name resolves to, before it is called.
 */
class SignedPostAdapter extends AbstractAdapter
{
    public function send(TrackingDestination $destination, array $payload): array
    {
        $url = $this->config($destination, 'endpoint_url');

        if (! SafeUrl::isAcceptable($url) || ! SafeUrl::resolvesPublicly($url)) {
            return ['ok' => false, 'summary' => [], 'error' => 'The address is not a public one.'];
        }

        // A GTM server container reads event_name; a webhook reads event. Both get both, so neither has to translate.
        $body = json_encode($payload + ['event_name' => $payload['event']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secret = $this->credential($destination, 'secret');
        $headers = $secret !== '' ? ['X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret)] : [];

        return $this->post($url, $body, $headers, ['event' => $payload['event'], 'event_id' => $payload['event_id']]);
    }
}
