<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tracking engine: a link that knows what it is for, the events it produces, and the lead
 * those events end up on.
 *
 *   /t/{token}  → tracking_links (what: project, property, campaign, ad, creative, rep, partner,
 *                 channel, landing page, QR, branch)
 *               → tracking_events (visit, click, form_start, form_submit, whatsapp_click, …, won)
 *               → leads (first touch, last touch, visitor id)
 *
 * Events are keyed by an anonymous visitor id the link hands to the landing page; when that
 * visitor becomes a lead, their earlier events are stitched onto the lead, and from then on
 * every sales outcome is an event on the same trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('token', 24)->unique();               // public: /t/{token}

            $table->string('name', 160);
            $table->string('kind', 12)->default('link');         // link · qr
            // meta · tiktok · snapchat · google · x · linkedin · whatsapp · landing_page · portal ·
            // email · sms · influencer · broker · qr · other — free enough to be honest, short enough to group on.
            $table->string('channel', 24)->default('other');

            // What it attributes to. Every one optional: a billboard QR knows its project and
            // nothing else; a broker's link knows the partner and the project.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained('ads')->nullOnDelete();
            $table->foreignId('creative_id')->nullable()->constrained('creatives')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();   // the sales rep
            $table->unsignedBigInteger('partner_id')->nullable()->index();                    // broker · influencer · affiliate (its own table later)
            $table->string('landing_page', 160)->nullable();
            $table->string('branch', 120)->nullable();

            $table->text('destination_url')->nullable();         // where /t/{token} sends the visitor
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('utm_content', 255)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'channel']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'campaign_id']);
        });

        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tracking_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 40)->nullable();

            // visit · click · form_start · form_submit · whatsapp_click · call_click · brochure_open ·
            // project_view · property_view · appointment_request · site_visit · reservation · won
            $table->string('event_type', 24);
            $table->timestamp('occurred_at');

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->text('url')->nullable();
            $table->text('referrer')->nullable();
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('utm_content', 255)->nullable();
            $table->string('click_id', 512)->nullable();
            $table->string('device', 16)->nullable();            // mobile · tablet · desktop
            $table->string('browser', 32)->nullable();
            $table->string('os', 32)->nullable();
            // Never the address itself: a salted hash is enough to count unique visitors and is not personal data.
            $table->string('ip_hash', 64)->nullable();
            $table->json('meta')->nullable();
            // Idempotency: a retried beacon, or the same outcome reported twice, is one event.
            $table->string('dedupe_key', 120)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'dedupe_key']);
            $table->index(['tenant_id', 'event_type', 'occurred_at']);
            $table->index(['tenant_id', 'visitor_id']);
            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tracking_link_id', 'event_type']);
        });

        Schema::table('leads', function (Blueprint $table) {
            // The identity a landing page handed the visitor, and the trail it left.
            $table->string('visitor_id', 40)->nullable()->after('click_id');
            $table->foreignId('first_touch_link_id')->nullable()->after('visitor_id')->constrained('tracking_links')->nullOnDelete();
            $table->foreignId('last_touch_link_id')->nullable()->after('first_touch_link_id')->constrained('tracking_links')->nullOnDelete();
            $table->timestamp('first_touch_at')->nullable()->after('last_touch_link_id');
            $table->timestamp('last_touch_at')->nullable()->after('first_touch_at');
            $table->text('referrer')->nullable()->after('last_touch_at');
            $table->text('landing_url')->nullable()->after('referrer');
            $table->string('device', 16)->nullable()->after('landing_url');
            $table->string('browser', 32)->nullable()->after('device');

            $table->index(['tenant_id', 'visitor_id']);
            $table->index(['tenant_id', 'first_touch_link_id']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'visitor_id']);
            $table->dropIndex(['tenant_id', 'first_touch_link_id']);
            $table->dropConstrainedForeignId('first_touch_link_id');
            $table->dropConstrainedForeignId('last_touch_link_id');
            $table->dropColumn(['visitor_id', 'first_touch_at', 'last_touch_at', 'referrer', 'landing_url', 'device', 'browser']);
        });

        Schema::dropIfExists('tracking_events');
        Schema::dropIfExists('tracking_links');
    }
};
