<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Replied = 'replied';
    case Qualified = 'qualified';
    case Appointment = 'appointment';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';

    /*
     * The two below are outcomes, not stages. A lead that turned out to be a
     * wrong number never travelled the pipeline, and one that never answered
     * stopped travelling it — showing either as a board column would imply work
     * is waiting there when none is.
     */
    case Invalid = 'invalid';
    case NoResponse = 'no_response';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Pipeline order used by the Kanban board and reports. */
    public function position(): int
    {
        return match ($this) {
            self::New => 1,
            self::Contacted => 2,
            self::Replied => 3,
            self::Qualified => 4,
            self::Appointment => 5,
            self::Negotiation => 6,
            self::Won => 7,
            self::Lost => 8,
            self::Invalid => 9,
            self::NoResponse => 10,
        };
    }

    /**
     * Nothing further will happen on its own. Covers the two outcomes as well
     * as won and lost: an invalid number and an unanswered lead are both
     * finished, and automation that keeps chasing either is the behaviour
     * customers complain about.
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::Won, self::Lost, self::Invalid, self::NoResponse], true);
    }

    /** Shown as a column on the board. */
    public function isPipelineColumn(): bool
    {
        return ! in_array($this, [self::Invalid, self::NoResponse], true);
    }

    /** @return array<int,self> */
    public static function pipeline(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isPipelineColumn()));
    }

    /**
     * Does this status mean somebody actually reached the lead?
     *
     * Deliberately narrow. These six cannot be true of a lead nobody has
     * contacted — you cannot have replied, qualified or won someone you never
     * spoke to. The others are left out on purpose: `invalid` is usually a
     * wrong number, and `lost` or `no_response` can both be recorded against a
     * lead nobody ever reached. Claiming a first contact for those would
     * silently inflate every response-time metric in the product.
     */
    public function impliesContactWasMade(): bool
    {
        return in_array($this, [
            self::Contacted, self::Replied, self::Qualified,
            self::Appointment, self::Negotiation, self::Won,
        ], true);
    }

    /** The lead is still being worked. */
    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    /**
     * Has the lead reached this stage, counting the ones beyond it?
     *
     * Funnels count leads that *reached* a stage, not leads sitting in it —
     * otherwise a healthy pipeline where everything moved on reads as empty.
     * The two outcomes are excluded: they are exits, not progress.
     */
    public function hasReached(self $stage): bool
    {
        if (! $this->isPipelineColumn() || ! $stage->isPipelineColumn()) {
            return $this === $stage;
        }

        return $this->position() >= $stage->position();
    }
}
