<?php

namespace App\Events;

class LeadLost extends LeadEvent
{
    public function name(): string
    {
        return 'lead.lost';
    }
}
