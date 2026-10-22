<?php

namespace App\Domain\Files;

use App\Enums\ActivityType;
use App\Models\FileShare;
use App\Models\Lead;
use App\Models\ProjectFile;
use App\Models\User;
use App\Services\Leads\ActivityLogger;
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
    public function __construct(private readonly ActivityLogger $activities) {}

    public function forLead(ProjectFile $file, Lead $lead, ?User $by = null, string $channel = 'manual'): FileShare
    {
        $share = FileShare::firstOrCreate(
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

        /*
         * On the timeline, once.
         *
         * Handing somebody a brochure is a contact, and it was the only kind
         * that left no trace: the lead's story went from "no answer" to "opened
         * the deck twice" with nothing in between to explain where the deck
         * came from. Logged here rather than in either caller, so a rule and a
         * rep produce the same record.
         *
         * Only when the share is new. `firstOrCreate` returns the existing one
         * for a second send, and a timeline that repeats the same hand-off
         * every time a rule re-fires is a timeline people stop reading.
         */
        if ($share->wasRecentlyCreated) {
            $this->activities->log($lead, ActivityType::FileShared, $by, $file->name, [
                'file_id' => $file->uuid,
                'channel' => $channel,
            ]);
        }

        return $share;
    }
}
