<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safe self-approve for campaign changes: the workspace's setting, a project's own laid over it, and
 * on each proposal whether a person approved it or the mode let it through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->json('self_approval')->nullable();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->json('self_approval')->nullable();
        });

        Schema::table('action_proposals', function (Blueprint $table) {
            // manual · self
            $table->string('approval', 8)->default('manual');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', fn (Blueprint $table) => $table->dropColumn('self_approval'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('self_approval'));
        Schema::table('action_proposals', fn (Blueprint $table) => $table->dropColumn('approval'));
    }
};
