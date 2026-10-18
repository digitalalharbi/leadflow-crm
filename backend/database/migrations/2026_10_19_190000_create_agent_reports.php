<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The reports the Reports Agent writes, kept as written: a report is a record of what was known then. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 24)->default('weekly');
            $table->date('period_from');
            $table->date('period_to');
            $table->jsonb('data');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_reports');
    }
};
