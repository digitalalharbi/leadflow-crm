<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A merged-away lead remembers where it went, so the history stays traceable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn('merged_at');
        });
    }
};
