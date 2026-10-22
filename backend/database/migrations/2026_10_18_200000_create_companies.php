<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holding → Company → Project.
 *
 * The workspace is the holding company: it is already the boundary of every
 * query, and a second top-level entity above it would be a second tenancy
 * model to keep honest. Companies are the businesses inside it, and a project
 * belongs to at most one.
 *
 * Optional all the way down. A workspace with no companies behaves exactly as
 * it did, and a project with no company is simply the holding's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            // A company may carry its own colour so the switcher and cards can
            // be told apart at a glance. Optional: the workspace's own applies.
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::table('projects', function (Blueprint $table) {
            // Null on delete: removing a company must never delete a project's
            // leads with it. (The controller refuses to remove one that still
            // has projects; this is the backstop.)
            $table->foreignId('company_id')->nullable()->after('tenant_id')
                ->constrained('companies')->nullOnDelete();
            $table->index(['tenant_id', 'company_id']);
        });

        // Who belongs to which company. A user in none sees everything their
        // role allows — the way every existing workspace already works — and a
        // user in some sees only those.
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
        Schema::dropIfExists('companies');
    }
};
