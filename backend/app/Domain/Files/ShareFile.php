<?php

namespace App\Domain\Files;

use App\Models\FileShare;
use App\Models\Lead;
use App\Models\ProjectFile;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Handing a file to one customer, in a way that reports back.
 *
 * The link is per lead rather than per file, because the question is never "how
 * popular is this brochure" — it is "did *this* customer open it", and that is
 * what changes who gets called next.
 *
 * One share per file per lead, reused. Sending the same brochure twice should
 * accumulate opens on one record rather than produce two links whose counts
 * have to be added up by whoever is reading the lead.
 */
class ShareFile
{
    public function forLead(ProjectFile $file, Lead $lead, ?User $by = null, string $channel = 'manual'): FileShare
    {
        return FileShare::firstOrCreate(
            ['project_file_id' => $file->id, 'lead_id' => $lead->id],
            [
                'sent_by' => $by?->id,
                'channel' => $channel,
                // Long enough that guessing one is not a strategy, short enough
                // to survive a WhatsApp message without wrapping.
                'token' => Str::random(40),
                /*
                 * Named even though the column defaults to it: a database
                 * default never reaches a model that was created without
                 * mentioning the column, so the row returned to the caller
                 * would carry a null where the screen expects a count.
                 */
                'opens' => 0,
            ],
        );
    }
}
