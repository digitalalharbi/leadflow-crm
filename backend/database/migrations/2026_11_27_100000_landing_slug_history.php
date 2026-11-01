<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses a page used to have. An ad or a printed QR may still point there, so an old address keeps opening its page and nobody
 * else can ever claim it. And a reference lookup, which runs for any WhatsApp message carrying one, has its own index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_slug_history', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landing_form_id')->constrained('landing_forms')->cascadeOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX tracking_events_whatsapp_ref ON tracking_events (tenant_id, (meta->>'ref')) WHERE event_type = 'whatsapp_click'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS tracking_events_whatsapp_ref');
        }

        Schema::dropIfExists('landing_slug_history');
    }
};
