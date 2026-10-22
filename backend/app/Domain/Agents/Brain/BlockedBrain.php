<?php

namespace App\Domain\Agents\Brain;

use RuntimeException;

/** No key, or the key was refused: a stated state, never a silent failure. */
final class BlockedBrain implements AgentBrain
{
    public function __construct(private string $reason = 'no_api_key') {}

    public function status(): array
    {
        return ['state' => 'blocked', 'reason' => $this->reason];
    }

    public function think(string $agent, array $system, array $messages, array $tools): BrainReply
    {
        throw new RuntimeException("The AI is not available: {$this->reason}.");
    }
}
