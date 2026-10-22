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
        /**
         * The matched lead had been deleted, and taking this one restored it.
         *
         * Worth carrying all the way out: "this number already exists" is a
         * reasonable thing to tell somebody about a lead they can see, and a
         * baffling thing to tell them about one they deleted and cannot.
         */
        public readonly bool $restored = false,
    ) {}

    public static function created(Lead $lead): self
    {
        return new self(self::CREATED, $lead);
    }

    public static function reEngaged(Lead $lead, bool $restored = false): self
    {
        return new self(self::RE_ENGAGED, $lead, null, $restored);
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
