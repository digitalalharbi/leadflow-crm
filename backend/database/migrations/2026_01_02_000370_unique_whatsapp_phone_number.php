<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Meta identifies a delivery by phone number id alone, so two workspaces
         * holding the same one would route each other's conversations. The
         * controller checked for that, but a bare `exists()` with nothing behind
         * it in the schema is a time-of-check/time-of-use gap: two admins saving
         * at once both pass, and afterwards an unordered `first()` decides whose
         * customers land where.
         *
         * Partial, because only WhatsApp integrations carry the key, and because
         * a disconnected workspace blanks its config and must not block the
         * number being connected somewhere else.
         *
         * It is also the index the inbound lookup needs: that query runs on
         * every unauthenticated callback and was a sequential scan.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX integrations_whatsapp_phone_number_unique
            ON integrations ((config->>'phone_number_id'))
            WHERE provider = 'whatsapp' AND config->>'phone_number_id' IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS integrations_whatsapp_phone_number_unique');
    }
};
