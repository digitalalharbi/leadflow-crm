<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person sees where they are signed in (address, browser, last use) and can end one session; a workspace can require
 * every member to use an authenticator app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->boolean('require_mfa')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropColumn(['ip', 'user_agent']));
        Schema::table('tenant_settings', fn (Blueprint $table) => $table->dropColumn('require_mfa'));
    }
};
