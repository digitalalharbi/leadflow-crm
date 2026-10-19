<?php

namespace App\Domain\Agents\Brain;

use App\Support\TenantContext;

/** The workspace's key if it has one, else the platform's, else blocked. */
final class BrainFactory
{
    public function __construct(private TenantContext $context) {}

    public function make(): AgentBrain
    {
        $key = $this->context->get()?->settings?->ai_api_key ?: config('services.anthropic.key');

        return filled($key) ? new ClaudeBrain((string) $key) : new BlockedBrain('no_api_key');
    }
}
