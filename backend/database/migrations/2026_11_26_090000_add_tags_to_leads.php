<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tags a team puts on a lead — "VIP", "investor", "follow up after Ramadan". A short list on the row rather than a
 * table: they are read with the lead every time and filtered on often, and a JSON array with a GIN index answers
 * "every lead tagged VIP" without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->jsonb('tags')->default('[]');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX leads_tags_gin ON leads USING GIN (tags jsonb_path_ops)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS leads_tags_gin');
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
