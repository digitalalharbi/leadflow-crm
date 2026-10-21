<?php

namespace App\Domain\Access;

/**
 * What one person may do and see, resolved: the permissions they hold, the ones a workspace has
 * explicitly taken away, and the elements of the interface that are off for them.
 */
final class Access
{
    /**
     * @param  list<string>  $permissions  every "resource.action" held
     * @param  list<string>  $denied  permissions a rule explicitly refused (what a long-standing role is held back from)
     * @param  list<string>  $hiddenElements  keys of features.elements that are off
     */
    public function __construct(
        public readonly array $permissions,
        public readonly array $denied,
        public readonly array $hiddenElements,
    ) {}

    public function has(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function sees(string $element): bool
    {
        return ! in_array($element, $this->hiddenElements, true);
    }
}
