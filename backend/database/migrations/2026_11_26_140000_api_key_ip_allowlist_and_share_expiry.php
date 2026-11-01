<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An API key may be limited to the addresses its integration calls from; a file link a lead received stops working
 * after a while, or at once when someone revokes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->jsonb('allowed_ips')->nullable();
        });

        Schema::table('file_shares', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });

        // Links already sent keep working for the same window a new one gets, counted from today.
        DB::table('file_shares')->whereNull('expires_at')->update(['expires_at' => now()->addDays(90)]);
    }

    public function down(): void
    {
        Schema::table('api_keys', fn (Blueprint $table) => $table->dropColumn('allowed_ips'));
        Schema::table('file_shares', fn (Blueprint $table) => $table->dropColumn(['expires_at', 'revoked_at']));
    }
};
