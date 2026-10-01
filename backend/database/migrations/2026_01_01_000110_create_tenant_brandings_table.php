<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_brandings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name');
            $table->string('system_name');          // white-label product name shown in UI
            $table->string('logo_url')->nullable();
            $table->string('login_logo_url')->nullable();
            $table->string('login_background_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('primary_color', 9)->default('#2563eb');
            $table->string('secondary_color', 9)->default('#0f172a');
            $table->string('support_email')->nullable();
            $table->string('support_phone')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_brandings');
    }
};
