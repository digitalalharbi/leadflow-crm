<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A nudge to the browsers that are already looking at this workspace.
 *
 * Deliberately not one of the domain events with `ShouldBroadcast` bolted on.
 * Those carry the whole Lead model — provider payloads, internal ids, every
 * column — and broadcasting is the one place where "who is allowed to see this"
 * is decided by a channel name rather than by a policy. Sending the lead itself
 * would mean re-implementing LeadPolicy in a channel callback and getting it
 * right forever.
 *
 * So what travels is the smallest thing that is still useful: something
 * happened, and which lead it was. The client refetches through the ordinary
 * endpoints, where the policies run. The worst case if a channel were ever
 * wrong is that somebody learns a uuid changed — not what it says.
 *
 * `ShouldBroadcast`, not `ShouldBroadcastNow`: the publish is an HTTP call to
 * the websocket server, and the request that raised it is often a provider's
 * webhook that must be answered in milliseconds. A queue worker therefore has
 * to be running or nothing arrives — which is the first thing to check when the
 * feed is silent, before anybody goes looking at the socket.
 */
class LeadFeedUpdate implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * @param  string  $event  the domain event's own name, e.g. `lead.created`
     * @param  string  $leadUuid  which lead, so a screen showing one can refetch just it
     * @param  string  $tenantUuid  the workspace whose feed this belongs on
     * @param  string|null  $assigneeUuid  the person it now belongs to, if anyone
     */
    public function __construct(
        public readonly string $event,
        public readonly string $leadUuid,
        public readonly string $tenantUuid,
        public readonly ?string $assigneeUuid = null,
    ) {}

    /**
     * The workspace feed, and the owner's own feed when there is an owner.
     *
     * Both, not one or the other: a manager watching arrivals and the rep the
     * lead just landed on are two different people who both need to know, and
     * the rep is not necessarily allowed on the workspace channel.
     *
     * @return array<int,PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('tenant.'.$this->tenantUuid.'.leads')];

        if ($this->assigneeUuid !== null) {
            $channels[] = new PrivateChannel('user.'.$this->assigneeUuid);
        }

        return $channels;
    }

    /**
     * One name for every kind of change, with the kind inside the payload.
     *
     * The client's job is the same whichever it was — refetch what is on screen
     * — so a listener per event name would be five listeners doing one thing,
     * and a sixth event type would silently reach none of them.
     */
    public function broadcastAs(): string
    {
        return 'lead.feed';
    }

    /** @return array<string,string|null> */
    public function broadcastWith(): array
    {
        return [
            'event' => $this->event,
            'lead_id' => $this->leadUuid,
            'assignee_id' => $this->assigneeUuid,
        ];
    }
}
