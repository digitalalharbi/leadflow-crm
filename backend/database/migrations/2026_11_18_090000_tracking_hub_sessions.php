<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first-party trail, grown from what was already there — never a second store.
 *
 *   visitor_sessions   one visit: the first and the last link that touched it, every platform click id it
 *                      arrived with, and the consent the visitor gave
 *   tracking_events    gains the id the browser and the server share (event_id), the session, the campaign and
 *                      platform it belongs to, and the consent in force when it happened
 *   tracking_links     says whether it redirects or opens a landing page of ours
 *   leads              carry the click ids they arrived with (first and last) and the consent given
 *
 * Additive only: nothing is changed or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 40);
            $table->string('session_id', 40);
            $table->foreignId('first_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
            $table->foreignId('last_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
            // {link_id, at, click_ids:{gclid,…}, utm:{…}, landing_url, referrer}: what the first and the last touch said.
            $table->json('first_touch')->nullable();
            $table->json('last_touch')->nullable();
            $table->string('device', 16)->nullable();
            $table->string('browser', 32)->nullable();
            $table->string('os', 32)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('consent', 10)->nullable();       // granted · denied · unknown
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('events_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'session_id']);
            $table->index(['tenant_id', 'visitor_id']);
            $table->index(['tenant_id', 'last_seen_at']);
            $table->index(['tenant_id', 'first_link_id']);
        });

        Schema::table('tracking_events', function (Blueprint $table) {
            $table->string('event_id', 40)->nullable()->after('dedupe_key');
            $table->string('session_id', 40)->nullable()->after('event_id');
            $table->foreignId('campaign_id')->nullable()->after('session_id')->constrained('campaigns')->nullOnDelete();
            $table->string('platform', 24)->nullable()->after('campaign_id');
            $table->string('consent', 10)->nullable()->after('platform');

            $table->index(['tenant_id', 'session_id']);
            $table->index(['tenant_id', 'event_id']);
        });

        Schema::table('tracking_links', function (Blueprint $table) {
            $table->string('mode', 10)->default('redirect')->after('kind');   // redirect · landing
            $table->json('custom_params')->nullable()->after('utm_content');
        });

        Schema::table('leads', function (Blueprint $table) {
            // {first:{gclid,…}, last:{…}}: the platform click ids the lead arrived with.
            $table->jsonb('click_ids')->nullable()->after('click_id');
            $table->string('tracking_consent', 10)->nullable()->after('click_ids');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['click_ids', 'tracking_consent']);
        });

        Schema::table('tracking_links', function (Blueprint $table) {
            $table->dropColumn(['mode', 'custom_params']);
        });

        Schema::table('tracking_events', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'session_id']);
            $table->dropIndex(['tenant_id', 'event_id']);
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropColumn(['event_id', 'session_id', 'platform', 'consent']);
        });

        Schema::dropIfExists('visitor_sessions');
    }
};
