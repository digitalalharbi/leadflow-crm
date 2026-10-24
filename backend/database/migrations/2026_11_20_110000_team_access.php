<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How people get into a company without anyone handling their password, and what they see once in. Additive only.
 *
 *  - `invitations`: a company admin invites by email; the link (a hashed, expiring token) lets the person choose their
 *    own password — or, if they already have a Mohtam account with that email, confirm it.
 *  - `password_links`: one-use, expiring links to set a first password (a new company's owner) or reset a forgotten one.
 *  - `users.all_projects`: a manager-type role sees every project, or only the ones given. Existing people keep seeing
 *    everything, as they did.
 *  - `users.email_verified_at` is backfilled for existing people: a workspace switch is offered only between accounts
 *    whose email has been proven, and the accounts that exist today were created by their own admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('role', 40);
            $table->foreignId('access_role_id')->nullable()->constrained('access_roles')->nullOnDelete();
            $table->json('project_ids')->nullable();
            $table->boolean('all_projects')->default(true);
            $table->string('phone', 32)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('send_count')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'email']);
        });

        Schema::create('password_links', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('purpose', 10); // setup | reset
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('email');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('all_projects')->default(true)->after('access_role_id');
        });

        DB::table('users')->whereNull('email_verified_at')->whereNull('deleted_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('all_projects');
        });

        Schema::dropIfExists('password_links');
        Schema::dropIfExists('invitations');
    }
};
