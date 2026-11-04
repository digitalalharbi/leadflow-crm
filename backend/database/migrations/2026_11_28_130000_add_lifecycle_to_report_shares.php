<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            // What a snapshot is: the report as it stood, kept encrypted, and exactly how it was made.
            $table->longText('frozen_payload')->nullable();
            $table->json('frozen_meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'archived_at', 'frozen_payload', 'frozen_meta']);
        });
    }
};
