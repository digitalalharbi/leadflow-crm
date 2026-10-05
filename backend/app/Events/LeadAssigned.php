<?php

namespace App\Events;

class LeadAssigned extends LeadEvent
{
    public function name(): string
    {
        return 'lead.assigned';
    }
}
