<?php

namespace App\Domain\Agents\Brain;

/**
 * The model behind an agent, behind one interface.
 *
 * The agents, their tools, their permissions and their audit trail do not
 * depend on which model answers, or whether one does at all: BlockedBrain is a
 * first-class implementation that says plainly why it cannot think, and the
 * conversation then answers from the agents' rules.
 */
interface AgentBrain
{
    /** @return array{state: 'ready'|'blocked', reason: ?string} */
    public function status(): array;

    /**
     * @param  list<array{type: string, text: string, cache?: bool}>  $system
     * @param  list<array<string, mixed>>  $messages  in the Messages API shape
     * @param  list<array{name: string, description: string, input_schema: array}>  $tools
     */
    public function think(string $agent, array $system, array $messages, array $tools): BrainReply;
}
