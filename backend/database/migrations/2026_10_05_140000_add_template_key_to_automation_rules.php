<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which template a rule started from, if any.
 *
 * Kept so the gallery can show what a workspace already has rather than
 * offering the same six cards forever, and so a rule installed from a template
 * stays identifiable after it has been renamed and edited — which is what
 * workspaces do to a starting point, and should.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->string('template_key', 64)->nullable()->after('trigger');
        });

        // One install per workspace: the gallery offers a starting point, not a
        // way to accumulate six identical rules by double-clicking.
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->unique(['tenant_id', 'template_key'], 'automation_rules_tenant_template_unique');
        });
    }

    public function down(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->dropUnique('automation_rules_tenant_template_unique');
            $table->dropColumn('template_key');
        });
    }
};
