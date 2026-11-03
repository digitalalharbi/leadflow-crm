<?php

namespace App\Events;

use App\Events\Contracts\LeadDomainEvent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * What happened to a lead, as a fact rather than a function call.
 *
 * Automation lives downstream of these: a controller records that a stage
 * changed and is finished, and whatever should follow — a message, a task, an
 * escalation — is decided by rules the tenant owns, not by the controller.
 * That separation is the whole reason this base class exists.
 *
 * `context` carries why it happened, which is what makes the trace readable
 * later: "assigned by round robin", "stage changed by rule #4", "replied on
 * WhatsApp". Never put a credential or a message body in it.
 */
abstract class LeadEvent implements LeadDomainEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Lead $lead,
        /** The person who caused it, or null when the system did. */
        public readonly ?User $actor = null,
        /** @var array<string,scalar|null> */
        public readonly array $context = [],
    ) {}

    /** The stable name a rule matches on. */
    /** One occurrence, kept when a queued job is retried: what a rule's steps are counted against. */
    private ?string $eventId = null;

    public function id(): string
    {
        return $this->eventId ??= (string) Str::uuid();
    }

    abstract public function name(): string;

    public function tenantId(): int
    {
        return $this->lead->tenant_id;
    }

    public function lead(): Lead
    {
        return $this->lead;
    }
}
