<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            /*
             * `outcome` was free text typed on completion. It stays, renamed to
             * what it always was — a note — and `outcome` becomes the structured
             * answer to "what happened?", which is the thing a report can count.
             */
            $table->renameColumn('outcome', 'outcome_note');
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->string('outcome', 24)->nullable()->after('status');
            $table->index(['tenant_id', 'outcome'], 'follow_ups_tenant_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex('follow_ups_tenant_outcome_index');
            $table->dropColumn('outcome');
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->renameColumn('outcome_note', 'outcome');
        });
    }
};
