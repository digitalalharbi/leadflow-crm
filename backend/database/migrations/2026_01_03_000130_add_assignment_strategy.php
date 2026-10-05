<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a workspace distributes leads.
 *
 * `assignment_mode` was manual-or-round-robin, which is the choice between no
 * automation and the wrong automation. The strategy column replaces it without
 * removing it: existing rows keep working, and the mode still decides *whether*
 * to assign automatically at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->string('assignment_strategy', 30)->default('round_robin')
                ->after('assignment_mode'); // round_robin | workload
        });

        // Workload is the better default for anyone who had already opted into
        // automatic distribution: they asked not to think about it, and taking
        // turns is the version of that which still lets a backlog build.
        \Illuminate\Support\Facades\DB::table('tenant_settings')
            ->where('assignment_mode', 'round_robin')
            ->update(['assignment_strategy' => 'workload']);
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('assignment_strategy');
        });
    }
};
