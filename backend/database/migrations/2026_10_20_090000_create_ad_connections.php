<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One connection per ad platform, for managing campaigns.
 *
 * Kept apart from `integrations`, which are lead sources: reading campaigns and
 * changing budgets needs different credentials and different permissions from
 * pulling a lead form, and a workspace may have one without the other.
 *
 * The evidence columns are what the verification ladder is computed from: a
 * successful connection test, a successful sync, in sandbox or live. Nothing
 * here is ever set by a test double — those run against a separate database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 24);
            $table->text('credentials')->nullable(); // encrypted
            $table->jsonb('config')->nullable();
            // live | sandbox
            $table->string('mode', 8)->default('live');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_test_ok_at')->nullable();
            $table->timestamp('last_sync_ok_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform']);
        });

        Schema::table('ad_accounts', function (Blueprint $table) {
            $table->foreignId('ad_connection_id')->nullable()->after('integration_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ad_accounts', fn (Blueprint $t) => $t->dropConstrainedForeignId('ad_connection_id'));
        Schema::dropIfExists('ad_connections');
    }
};
