<?php

namespace App\Domain\Git;

use App\Domain\Git\Contracts\GitProviderInterface;
use InvalidArgumentException;

/**
 * Which code host a connection speaks to.
 *
 * One entry today. It exists so that the controllers and the jobs never name
 * GitHub: when GitLab arrives it is registered here and nothing above it
 * changes.
 */
class GitProviderRegistry
{
    /** @var array<string,GitProviderInterface> */
    private array $providers = [];

    public function register(GitProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): GitProviderInterface
    {
        return $this->providers[$key]
            ?? throw new InvalidArgumentException("No git provider registered for [{$key}].");
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
