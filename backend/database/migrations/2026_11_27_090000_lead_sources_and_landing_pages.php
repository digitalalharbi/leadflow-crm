<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's lead sources, ending in one pipeline. A landing page is the form that already exists with what makes it a
 * campaign page (a public address, a template, its calls to action); a platform's lead form gets a project and a field map;
 * a lead remembers which page brought it; a destination may say which of its events a Mohtam event becomes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_forms', function (Blueprint $table) {
            // Across every workspace: one public address space, mohtam.pro/l/<slug>.
            $table->string('slug', 64)->nullable()->unique();
            $table->jsonb('page')->nullable();
            $table->foreignId('default_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('landing_form_id')->nullable()->constrained('landing_forms')->nullOnDelete();
            $table->index(['tenant_id', 'project_id', 'landing_form_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('logo_url', 500)->nullable();
        });

        Schema::table('tracking_destinations', function (Blueprint $table) {
            $table->jsonb('event_map')->nullable();
        });

        Schema::create('lead_form_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('account_ref', 191)->nullable();
            $table->string('form_ref', 191);
            $table->string('form_name', 191)->nullable();
            $table->jsonb('field_mapping')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'form_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_form_mappings');
        Schema::table('tracking_destinations', fn (Blueprint $table) => $table->dropColumn('event_map'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('logo_url'));
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'project_id', 'landing_form_id']);
            $table->dropConstrainedForeignId('landing_form_id');
        });
        Schema::table('landing_forms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_link_id');
            $table->dropColumn(['slug', 'page']);
        });
    }
};
