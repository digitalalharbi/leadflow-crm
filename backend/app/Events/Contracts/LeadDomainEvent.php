<?php

namespace App\Events\Contracts;

use App\Models\Lead;

/**
 * Marks something that happened to a lead.
 *
 * An interface rather than the abstract base class because Laravel's dispatcher
 * resolves listeners through interfaces and not through parent classes — a
 * listener registered on the base class catches nothing, silently, which is
 * exactly the failure an automation product must not have.
 */
interface LeadDomainEvent
{
    public function name(): string;

    public function tenantId(): int;

    public function lead(): Lead;
}
