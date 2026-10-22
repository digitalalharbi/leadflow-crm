<?php

namespace App\Events;

class LeadReplied extends LeadEvent
{
    public function name(): string
    {
        return 'lead.replied';
    }
}
