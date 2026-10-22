<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a lead created to prove the pipeline works.
 *
 * A test lead has to travel the real path — normalisation, dedup, scoring,
 * assignment, automation — or it proves nothing. Which means it is a real row,
 * and a real row shows up in the conversion rate.
 *
 * So it is excluded everywhere by a global scope rather than by a filter in
 * each query. There is no version of this where some screens count it and
 * others do not: a workspace whose dashboard and whose lead list disagree by
 * one has no way to know which is right, and "it's just the test lead" is not
 * something anybody can tell by looking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('status');
            $table->index(['tenant_id', 'is_test']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_test']);
            $table->dropColumn('is_test');
        });
    }
};
