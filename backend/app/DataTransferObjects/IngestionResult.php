<?php

namespace App\DataTransferObjects;

use App\Models\Lead;

class IngestionResult
{
    public const CREATED = 'created';

    public const RE_ENGAGED = 're_engaged';

    public const REJECTED = 'rejected';

    private function __construct(
        public readonly string $outcome,
        public readonly ?Lead $lead = null,
        public readonly ?string $reason = null,
    ) {}

    public static function created(Lead $lead): self
    {
        return new self(self::CREATED, $lead);
    }

    public static function reEngaged(Lead $lead): self
    {
        return new self(self::RE_ENGAGED, $lead);
    }

    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, null, $reason);
    }

    public function wasAccepted(): bool
    {
        return $this->outcome !== self::REJECTED;
    }

    public function isDuplicate(): bool
    {
        return $this->outcome === self::RE_ENGAGED;
    }
}
