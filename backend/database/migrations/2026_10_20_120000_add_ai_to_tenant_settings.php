<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A workspace's own AI key, encrypted. Without one the agents work from their
 * rules and say so; nothing else depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->text('ai_api_key')->nullable();
            $table->timestamp('ai_verified_at')->nullable();
            $table->text('ai_last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', fn (Blueprint $t) => $t->dropColumn(['ai_api_key', 'ai_verified_at', 'ai_last_error']));
    }
};
