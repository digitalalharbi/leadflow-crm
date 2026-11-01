<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's own calendar feed: their visits, in the calendar on their phone, through a private address they can
 * change at any time. Only the hash of the address's secret is kept, like an API key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token_hash', 64)->nullable()->unique();
            $table->timestamp('calendar_feed_fetched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['calendar_token_hash', 'calendar_feed_fetched_at']);
        });
    }
};
