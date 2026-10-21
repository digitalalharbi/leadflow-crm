<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A live report somebody outside the workspace can open with a link.
 *
 * Not a PDF: the link shows the numbers as they are now. The token is a bearer secret, so only
 * its hash is looked up (`token_hash`) and the token itself is kept encrypted, which is what lets
 * the owner copy the link again without the database ever holding a usable one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_shares', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 120);
            $table->string('template', 20)->default('executive');       // executive · marketing · sales · client · management
            $table->json('sections');                                      // { section: bool }
            $table->unsignedSmallInteger('days')->default(30);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('token_hash', 64)->unique();
            $table->text('token_enc');
            $table->string('password_hash')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_shares');
    }
};
