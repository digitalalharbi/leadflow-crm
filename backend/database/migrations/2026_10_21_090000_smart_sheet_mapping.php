<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sheet's mapping is now worked out from its own header row, and a person's
 * choices are kept apart from the guess so the next guess cannot undo them.
 *
 * Mappings written by hand before this change are exactly such choices, so
 * they are carried over as overrides rather than re-guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_sources', function (Blueprint $table) {
            // Worked out on the first sync now, so a source can exist before it.
            $table->json('mapping')->nullable()->change();
            $table->json('mapping_overrides')->nullable()->after('mapping');
            $table->json('mapping_meta')->nullable()->after('mapping_overrides');
            $table->string('push_token', 64)->nullable()->after('mapping_meta');
            $table->timestamp('last_push_at')->nullable()->after('last_synced_at');
        });

        DB::table('sheet_sources')->whereNotNull('mapping')->update(['mapping_overrides' => DB::raw('mapping')]);
    }

    public function down(): void
    {
        Schema::table('sheet_sources', function (Blueprint $table) {
            $table->dropColumn(['mapping_overrides', 'mapping_meta', 'push_token', 'last_push_at']);
        });
    }
};
