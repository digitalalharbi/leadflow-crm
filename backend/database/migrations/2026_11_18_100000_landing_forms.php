<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The landing page a tracking link can open, and the form on it.
 *
 * A form belongs to one workspace (and usually one project); its fields are an ordered list that says what
 * may be asked, how it is checked and where each answer lands on the lead. A tracking link points at a form
 * by `landing_form_id` and says it opens it with `mode = landing`. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_forms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('status', 12)->default('draft');            // draft · published · archived
            $table->string('headline', 200)->nullable();
            $table->text('description')->nullable();
            $table->json('benefits')->nullable();                      // a few short lines
            $table->string('cta_label', 60)->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('thank_you_title', 160)->nullable();
            $table->text('thank_you_message')->nullable();
            $table->boolean('consent_required')->default(true);
            $table->text('consent_text')->nullable();
            $table->string('privacy_url', 500)->nullable();
            $table->json('fields');                                    // the ordered list: see Domain\Tracking\Forms\FormSchema
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::table('tracking_links', function (Blueprint $table) {
            $table->foreignId('landing_form_id')->nullable()->after('mode')->constrained('landing_forms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('landing_form_id');
        });

        Schema::dropIfExists('landing_forms');
    }
};
