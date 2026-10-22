<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A nudge to whoever has a project's Development tab open.
 *
 * Same shape and same reasoning as the lead feed: what travels is that
 * something changed and which project, never what changed. The browser
 * refetches through the ordinary endpoint, where the policies run — so a
 * payload cannot show somebody a repository the API would not have given them.
 *
 * Queued, like the lead feed. A delivery that had to wait for a socket call
 * before answering GitHub would be a delivery GitHub retried.
 */
class RepositoryFeedUpdate implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $projectUuid,
        /** The kind of thing that happened, so a toast can be specific. */
        public readonly string $kind,
    ) {}

    /** @return array<int,PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->tenantUuid.'.development')];
    }

    public function broadcastAs(): string
    {
        return 'repository.feed';
    }

    /** @return array<string,string> */
    public function broadcastWith(): array
    {
        return ['project_id' => $this->projectUuid, 'kind' => $this->kind];
    }
}
