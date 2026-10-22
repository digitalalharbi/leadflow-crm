<?php

namespace App\Events;

class LeadWon extends LeadEvent
{
    public function name(): string
    {
        return 'lead.won';
    }
}
