<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Hashes only: enough to tell "a device we have not seen" without keeping what it was. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('known_devices')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('known_devices');
        });
    }
};
