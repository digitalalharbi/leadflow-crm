<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A workspace has no name for the product until its owner gives it one.
 *
 * `system_name` was required, so provisioning had to put *something* there, and
 * it put the client's company name. The result was a product that called itself
 * "Rawasi Development" on every screen of a workspace whose owner had never been
 * asked — the opposite of the rule: مهتم is the product, and it is renamed only
 * by a customer who chose to.
 *
 * Null means "not customised", and everything that shows the name falls back to
 * the platform's own. Existing rows are left exactly as they are: a value that
 * equals a company name might be a default or might be a decision, and nothing
 * here can tell, so nothing here guesses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_brandings', function (Blueprint $table) {
            $table->string('system_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restore the requirement the way provisioning used to satisfy it.
        DB::table('tenant_brandings')->whereNull('system_name')->update(['system_name' => DB::raw('company_name')]);

        Schema::table('tenant_brandings', function (Blueprint $table) {
            $table->string('system_name')->nullable(false)->change();
        });
    }
};
