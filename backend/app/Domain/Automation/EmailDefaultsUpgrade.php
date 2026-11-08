<?php

namespace App\Domain\Automation;

use App\Models\MessageTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Moves every workspace's welcome and follow-up emails to the wording that carries the project's facts, but only
 * where the email is still exactly as the product first wrote it. A workspace that edited its email made a decision;
 * that one is never touched. Safe to run twice.
 */
class EmailDefaultsUpgrade
{
    /** @return int how many emails were upgraded */
    public function run(): int
    {
        $upgraded = 0;

        foreach (MessageTemplate::EMAIL_DEFAULTS as $new) {
            $before = MessageTemplate::EMAIL_DEFAULTS_BEFORE[$new['purpose']] ?? null;

            if ($before === null) {
                continue;
            }

            $upgraded += DB::table('message_templates')
                ->where('channel', 'email')
                ->where('purpose', $new['purpose'])
                ->where('body', $before)
                ->update(['body' => $new['body'], 'updated_at' => now()]);
        }

        return $upgraded;
    }
}
