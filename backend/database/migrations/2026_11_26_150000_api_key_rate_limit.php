<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A key's own calls-per-minute. Null keeps the platform's default. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->unsignedSmallInteger('rate_limit_per_minute')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', fn (Blueprint $table) => $table->dropColumn('rate_limit_per_minute'));
    }
};
