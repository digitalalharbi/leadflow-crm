<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The advanced form builder, on the foundation that is already there. Additive only.
 *
 *  - `landing_forms` gains the layout (sections → rows → columns), the design (safe tokens) and the settings; the row stays the
 *    DRAFT the manager is editing.
 *  - `landing_form_versions`: every publish is a frozen snapshot. The public page serves the published version, so editing a draft
 *    never reaches a campaign already running, and a submission remembers which version its visitor saw. `variant` is there so an
 *    A/B test can later publish two live versions of one form without changing the schema.
 *  - the trail and the source events point at the version a submission was made on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_form_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landing_form_id')->constrained('landing_forms')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('variant', 8)->default('A');
            $table->json('snapshot');
            $table->string('checksum', 40);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['landing_form_id', 'version']);
            $table->index(['tenant_id', 'landing_form_id']);
        });

        Schema::table('landing_forms', function (Blueprint $table) {
            $table->json('layout')->nullable()->after('fields');
            $table->json('design')->nullable()->after('layout');
            $table->json('settings')->nullable()->after('design');
            $table->foreignId('published_version_id')->nullable()->after('settings')->constrained('landing_form_versions')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('published_version_id');
        });

        Schema::table('tracking_events', function (Blueprint $table) {
            $table->foreignId('form_version_id')->nullable()->after('campaign_id')->constrained('landing_form_versions')->nullOnDelete();
            $table->index(['tenant_id', 'form_version_id']);
        });

        Schema::table('lead_source_events', function (Blueprint $table) {
            $table->foreignId('form_version_id')->nullable()->after('form_name')->constrained('landing_form_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lead_source_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('form_version_id');
        });

        Schema::table('tracking_events', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'form_version_id']);
            $table->dropConstrainedForeignId('form_version_id');
        });

        Schema::table('landing_forms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_version_id');
            $table->dropColumn(['layout', 'design', 'settings', 'published_at']);
        });

        Schema::dropIfExists('landing_form_versions');
    }
};
