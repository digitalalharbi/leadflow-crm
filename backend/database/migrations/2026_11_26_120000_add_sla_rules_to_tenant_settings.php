<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-contact targets that depend on the lead: a Meta lead in fifteen minutes, a website enquiry in an hour, a
 * priority lead in ten. Null keeps one target for everyone, as it always was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->jsonb('sla_rules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('sla_rules');
        });
    }
};
