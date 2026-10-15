<?php

namespace App\Domain\Calls;

use App\Domain\Calls\Contracts\CallProviderInterface;
use InvalidArgumentException;

/**
 * Which telephony provider a workspace uses.
 *
 * One entry today, and it is the one that places no calls. It exists so that
 * the controllers never name a provider: connecting a real one is a class and
 * a settings row, not a change to how a call is recorded.
 */
class CallProviderRegistry
{
    /** @var array<string,CallProviderInterface> */
    private array $providers = [];

    public function register(CallProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(?string $key): CallProviderInterface
    {
        $key ??= 'manual';

        return $this->providers[$key]
            ?? throw new InvalidArgumentException("No call provider registered for [{$key}].");
    }

    /**
     * What this workspace can actually use right now.
     *
     * The first registered provider that reports it can place a call, and
     * otherwise the one where the rep dials. Never an error: a workspace with no
     * telephony connected is the normal case, and a connected one whose
     * credentials have just been revoked should still be able to make a call —
     * by hand — rather than find the button dead.
     */
    public function forTenant(): CallProviderInterface
    {
        foreach ($this->providers as $key => $provider) {
            if ($key !== 'manual' && $provider->canPlace()) {
                return $provider;
            }
        }

        return $this->get('manual');
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
