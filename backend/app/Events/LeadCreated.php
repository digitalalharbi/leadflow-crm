<?php

namespace App\Events;

class LeadCreated extends LeadEvent
{
    public function name(): string
    {
        return 'lead.created';
    }
}
