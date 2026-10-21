<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How sensitive the alerts are, set by the workspace and, where a project needs different, by the
 * project: per alert, whether it is on and the line it fires at. Empty means the built-in line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', fn (Blueprint $t) => $t->json('anomaly_settings')->nullable());
        Schema::table('projects', fn (Blueprint $t) => $t->json('anomaly_settings')->nullable());
        Schema::table('anomalies', function (Blueprint $t) {
            // Which project's lines a finding was judged by (null: the workspace's).
            $t->unsignedBigInteger('project_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('anomalies', fn (Blueprint $t) => $t->dropColumn('project_id'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('anomaly_settings'));
        Schema::table('tenant_settings', fn (Blueprint $t) => $t->dropColumn('anomaly_settings'));
    }
};
