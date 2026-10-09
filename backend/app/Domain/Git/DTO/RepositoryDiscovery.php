<?php

namespace App\Domain\Git\DTO;

/**
 * What a repository says about itself, so nobody has to type it.
 *
 * Asking a manager to name the deploy workflow is asking them to read a YAML
 * file they have never opened. Every field here is read from the repository and
 * offered as a default; the mapping screen lets them disagree.
 */
class RepositoryDiscovery
{
    public function __construct(
        public readonly RepositorySummary $repository,
        /** @var array<int,string> */
        public readonly array $branches = [],
        /** @var array<int,array{id:int,name:string,path:string,state:string}> */
        public readonly array $workflows = [],
        /** @var array<int,string> */
        public readonly array $environments = [],
        public readonly ?string $latestRelease = null,
        /**
         * Why a field is missing, when the answer is "we were not allowed to
         * look" rather than "there is none". A repository whose Actions are
         * disabled and one whose token lacks the scope look identical in a list
         * of zero workflows, and only one of them is the user's problem.
         *
         * @var array<string,string>
         */
        public readonly array $unavailable = [],
    ) {}

    /** `develop` and `main` when they exist — a suggestion, never applied silently. */
    public function suggestedMapping(): array
    {
        $has = fn (string $branch) => in_array($branch, $this->branches, true);

        return [
            'develop_branch' => $has('develop') ? 'develop' : ($has('staging') ? 'staging' : null),
            'production_branch' => $has('main') ? 'main' : ($has('master') ? 'master' : $this->repository->defaultBranch),
        ];
    }

    public function toArray(): array
    {
        return [
            'repository' => $this->repository->toArray(),
            'branches' => $this->branches,
            'workflows' => $this->workflows,
            'environments' => $this->environments,
            'latest_release' => $this->latestRelease,
            'unavailable' => $this->unavailable,
            'suggested' => $this->suggestedMapping(),
        ];
    }
}
