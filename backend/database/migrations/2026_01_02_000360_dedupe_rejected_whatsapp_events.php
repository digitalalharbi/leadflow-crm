<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_events', function ($table) {
            // How many deliveries this row stands for. A rejection row is now a
            // tally rather than one row per request.
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('last_seen_at')->nullable();
        });

        /*
         * The existing unique index cannot suppress a rejection row: tenant_id
         * is NULL for an unknown number, and in Postgres a NULL in a unique
         * index never collides with anything. So an unauthenticated caller got
         * a new row per request however the key was chosen.
         *
         * This partial index covers exactly the rejection rows and keys on
         * COALESCE, which gives the NULL-tenant rows a real value to collide on.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX whatsapp_events_rejected_unique
            ON whatsapp_events (coalesce(tenant_id, 0), coalesce(phone_number_id, ''), coalesce(outcome, ''))
            WHERE event_type = 'rejected'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS whatsapp_events_rejected_unique');

        Schema::table('whatsapp_events', function ($table) {
            $table->dropColumn(['occurrences', 'last_seen_at']);
        });
    }
};
