<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider');   // generic_webhook | google_sheets | meta | tiktok | google_ads | linkedin | snapchat
            $table->string('name')->nullable();
            $table->string('status')->default('not_connected'); // connected | not_connected | configuration_required | error
            // Encrypted at rest by the model cast; never serialised back to the client.
            $table->text('credentials')->nullable();
            $table->jsonb('config')->nullable();        // provider-specific, non-secret
            $table->jsonb('field_mapping')->nullable(); // { "mobile": "phone", "name": "full_name" }
            $table->jsonb('defaults')->nullable();      // { project_id, platform, assigned_to }
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('leads_received')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'name']);
            $table->index(['tenant_id', 'provider']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 64)->unique();      // public path segment
            $table->string('secret_hash');            // hash of the shared secret; plaintext shown once
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_received_at')->nullable();
            $table->unsignedBigInteger('received_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('status');         // accepted | duplicate | rejected | failed
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('ip', 45)->nullable();
            $table->jsonb('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['tenant_id', 'received_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('integrations');
    }
};
