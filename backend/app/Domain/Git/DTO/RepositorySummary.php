<?php

namespace App\Domain\Git\DTO;

/** One repository, as little of it as a chooser needs. */
class RepositorySummary
{
    public function __construct(
        public readonly int $id,
        public readonly string $fullName,
        public readonly string $defaultBranch,
        public readonly bool $private,
        public readonly ?string $description = null,
        public readonly ?string $pushedAt = null,
    ) {}

    public static function fromGitHub(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            fullName: (string) ($row['full_name'] ?? ''),
            defaultBranch: (string) ($row['default_branch'] ?? 'main'),
            private: (bool) ($row['private'] ?? true),
            description: $row['description'] ?? null,
            pushedAt: $row['pushed_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->fullName,
            'default_branch' => $this->defaultBranch,
            'private' => $this->private,
            'description' => $this->description,
            'pushed_at' => $this->pushedAt,
        ];
    }
}
