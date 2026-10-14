<?php

namespace App\Domain\Calls\Providers;

use App\Domain\Calls\Contracts\CallProviderInterface;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;

/**
 * No provider. The rep dials.
 *
 * This is what the product does today, said plainly. It places no call, claims
 * no duration and leaves the status at `initiated` until a person says what
 * happened — because nothing is watching a rep's phone, and a status that
 * advanced on its own would be a guess printed as a fact.
 *
 * The alternative — a mock provider that reports connected calls with invented
 * durations — would put fiction into the reports people make decisions from.
 */
class ManualCallProvider implements CallProviderInterface
{
    public function key(): string
    {
        return 'manual';
    }

    public function canPlace(): bool
    {
        return false;
    }

    public function place(Lead $lead, User $by): Call
    {
        return Call::create([
            'lead_id' => $lead->id,
            'user_id' => $by->id,
            'provider' => $this->key(),
            'direction' => 'outbound',
            'status' => Call::INITIATED,
            'started_at' => now(),
        ]);
    }
}
