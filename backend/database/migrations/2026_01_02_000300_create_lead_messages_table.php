<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('direction', 16);              // outbound | inbound
            $table->string('channel', 16)->default('whatsapp');
            $table->string('provider', 32)->nullable();   // whatsapp_cloud | mock

            /*
             * The provider's own id for this message. Unique per tenant, which is
             * what makes a repeated webhook delivery a no-op: Meta retries a
             * callback until it gets a 200, and a network blip must not turn one
             * customer message into three.
             */
            $table->string('provider_message_id')->nullable();

            $table->string('message_type', 24)->default('text'); // text | template
            $table->string('template_name')->nullable();

            $table->text('body')->nullable();
            $table->string('status', 16)->default('pending');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->string('failure_code', 64)->nullable();
            $table->text('failure_reason')->nullable();

            // Raw provider envelope, kept for support. Never exposed by the API.
            $table->jsonb('provider_payload')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'provider_message_id'], 'lead_messages_provider_id_unique');
            $table->index(['tenant_id', 'lead_id', 'created_at']);
            $table->index(['tenant_id', 'direction', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_messages');
    }
};
