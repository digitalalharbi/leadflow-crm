<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report sent on its own: every day, every week on a weekday, or every month on a day — to a list
 * of people, as a link, at an hour of the workspace's own clock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_share_id')->constrained('report_shares')->cascadeOnDelete();
            // daily · weekly · monthly
            $table->string('frequency', 8);
            $table->unsignedTinyInteger('weekday')->nullable();    // 1 (Monday) … 7 (Sunday), for weekly
            $table->unsignedTinyInteger('month_day')->nullable();  // 1 … 28, for monthly
            $table->unsignedTinyInteger('send_hour')->default(8);
            $table->json('recipients');
            $table->text('message')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            // sent · stopped (the report was closed)
            $table->string('last_status', 12)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'enabled', 'next_run_at']);
            $table->index(['report_share_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
