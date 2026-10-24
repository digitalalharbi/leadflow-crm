<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform owner signs in apart from every company, with a second factor available; a company has one named owner;
 * and a support session inside a company is a recorded thing with a start and an end. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted at rest (cast); never serialised.
            $table->text('mfa_secret')->nullable()->after('password');
            $table->timestamp('mfa_enabled_at')->nullable()->after('mfa_secret');
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_enabled_at');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
        });

        Schema::create('impersonations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('platform_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('reason', 500);
            $table->string('ip', 45)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_by', 20)->nullable(); // platform | expired | revoked
            $table->index(['tenant_id', 'started_at']);
        });

        // Sessions a platform account opened through a company's login predate the platform's own door; they end here.
        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')
            ->whereIn('tokenable_id', DB::table('users')->where('role', 'super_admin')->pluck('id'))->delete();

        // Each existing company's owner is its first admin — the account it was created with.
        foreach (DB::table('tenants')->whereNull('owner_user_id')->pluck('id') as $tenantId) {
            $owner = DB::table('users')->where('tenant_id', $tenantId)->where('role', 'admin')->whereNull('deleted_at')->orderBy('id')->value('id');

            if ($owner) {
                DB::table('tenants')->where('id', $tenantId)->update(['owner_user_id' => $owner]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonations');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_enabled_at', 'mfa_recovery_codes']);
        });
    }
};
