<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times we have tried to send this message.
 *
 * A send was one attempt and nothing more: if the HTTP call threw — a dropped
 * connection, Meta returning a 503 — the message was marked failed for good and
 * the rep had to notice and send it again. The conversion exporter in this
 * codebase already retries with a backoff; the channel a rep actually talks to
 * customers on did not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
