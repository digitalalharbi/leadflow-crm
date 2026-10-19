<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New copy, hooks and variations for an ad, waiting for a person.
 *
 * A draft is never published. Using one is a separate act — a proposal to
 * create or update an ad — which goes through the gateway like any change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained()->nullOnDelete();
            // copy | hook | variation
            $table->string('kind', 12);
            $table->jsonb('items');
            $table->text('rationale')->nullable();
            // agent | user
            $table->string('source', 8);
            $table->string('agent', 24)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // draft | used | dismissed
            $table->string('status', 12)->default('draft');
            // Forbidden words the client listed that appear in it.
            $table->jsonb('flags')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'ad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_drafts');
    }
};
