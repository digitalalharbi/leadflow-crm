<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What the platform owner has switched off for this company, whatever its plan includes. */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('disabled_modules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('disabled_modules');
        });
    }
};
