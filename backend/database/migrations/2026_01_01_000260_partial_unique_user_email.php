<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `unique(tenant_id, email)` still sees soft-deleted rows, so an admin who
 * removed a colleague could never add them back — validation passed and the
 * insert then failed with a 23505. A partial index matches the intent: the
 * address is unique among *active* users only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function ($table) {
            $table->dropUnique('users_tenant_id_email_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX users_tenant_email_active_unique
             ON users (tenant_id, email) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_tenant_email_active_unique');

        Schema::table('users', function ($table) {
            $table->unique(['tenant_id', 'email']);
        });
    }
};
