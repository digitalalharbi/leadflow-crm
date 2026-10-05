<?php

namespace App\Events;

class LeadQualified extends LeadEvent
{
    public function name(): string
    {
        return 'lead.qualified';
    }
}
