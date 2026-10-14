<?php

namespace App\Domain\Calls\Contracts;

use App\Models\Call;
use App\Models\Lead;
use App\Models\User;

/**
 * What مهتم needs from a telephony provider.
 *
 * Written now, with one implementation that places no calls, because the shape
 * of the record does not change when a provider arrives — the same lead, the
 * same rep, the same outcome. A provider adds an id, a status that moves on its
 * own, and a duration that was measured rather than guessed.
 *
 * Deliberately no "mock provider". A fake that reports connected calls and
 * invented durations would put fictional numbers in the reports, and reports
 * are what people make decisions from.
 */
interface CallProviderInterface
{
    public function key(): string;

    /**
     * Whether this provider can actually place a call right now.
     *
     * False is an ordinary answer, not an error: the manual provider always
     * answers false, and the interface says so instead of making the caller
     * discover it.
     */
    public function canPlace(): bool;

    /**
     * Start a call, or record the intent to make one by hand.
     *
     * Returns the stored Call either way, so the lead's history is the same
     * shape whether a provider dialled or a rep did.
     */
    public function place(Lead $lead, User $by): Call;
}
