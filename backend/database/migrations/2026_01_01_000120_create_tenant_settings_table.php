<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('locale', 5)->default('ar');
            $table->string('currency', 3)->default('SAR');
            $table->string('timezone')->default('Asia/Riyadh');
            $table->string('default_country_code', 5)->default('966');
            $table->string('assignment_mode')->default('manual'); // manual | round_robin
            $table->unsignedBigInteger('round_robin_cursor')->default(0);
            $table->unsignedSmallInteger('score_warm_threshold')->default(40);
            $table->unsignedSmallInteger('score_hot_threshold')->default(70);
            $table->text('whatsapp_template')->nullable();
            $table->jsonb('onboarding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
