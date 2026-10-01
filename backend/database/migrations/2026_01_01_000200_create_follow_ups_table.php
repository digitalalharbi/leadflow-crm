<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at');
            $table->text('note')->nullable();
            $table->string('status')->default('pending'); // pending | completed | cancelled
            $table->timestamp('completed_at')->nullable();
            $table->text('outcome')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'assigned_to', 'status', 'due_at'], 'follow_ups_tenant_user_queue_index');
            $table->index(['tenant_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
