<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports as a library, not a link.
 *
 *  - `report_shares` (the report) gains what a client report is configured with: its state in the library
 *    (draft · live · archived), the client, one or several projects, the period and what it is compared with, the
 *    attribution model, the currency, the filters (platforms, campaigns, sources, salespeople), its branding, and the
 *    revision it is on. Existing reports are live, on their one project, last-touch, as they were.
 *  - `report_share_projects`: the projects a report covers (a portfolio report has several).
 *  - `report_notes`: the executive, marketing, sales and period notes, each revision kept.
 *  - `report_revisions`: the configuration at each significant change — never the analytics.
 *  - `report_activities`: what happened to a report and who did it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table) {
            $table->string('state', 10)->default('live')->after('name');                    // draft · live · archived
            $table->string('client_name', 160)->nullable()->after('state');
            $table->string('period', 16)->nullable()->after('days');                         // today … custom; null = last `days` days
            $table->date('period_from')->nullable()->after('period');
            $table->date('period_to')->nullable()->after('period_from');
            $table->string('compare_mode', 20)->default('previous_period')->after('compare'); // previous_period · previous_month · custom
            $table->date('compare_from')->nullable()->after('compare_mode');
            $table->date('compare_to')->nullable()->after('compare_from');
            $table->string('attribution_model', 12)->default('last')->after('compare_to');   // first · last · linear · position
            $table->string('currency', 3)->nullable()->after('attribution_model');
            $table->json('filters')->nullable()->after('currency');                          // {platforms, campaigns, sources, sales_users}
            $table->json('branding')->nullable()->after('filters');
            $table->json('options')->nullable()->after('branding');                           // {expose_team, show_unit_prices, …}
            $table->unsignedInteger('revision')->default(1)->after('branding');
            $table->timestamp('published_at')->nullable()->after('revision');
            $table->timestamp('archived_at')->nullable()->after('published_at');
            $table->index(['tenant_id', 'state']);
        });

        Schema::create('report_share_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_share_id')->constrained('report_shares')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unique(['report_share_id', 'project_id']);
        });

        Schema::create('report_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_share_id')->constrained('report_shares')->cascadeOnDelete();
            $table->string('kind', 12);                                                       // executive · marketing · sales · period
            $table->text('body');
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_current')->default(true);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['report_share_id', 'kind', 'is_current']);
        });

        Schema::create('report_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_share_id')->constrained('report_shares')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('configuration');
            $table->json('changed')->nullable();                                              // which settings changed
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['report_share_id', 'revision']);
        });

        Schema::create('report_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_share_id')->constrained('report_shares')->cascadeOnDelete();
            $table->string('action', 32);        // created · edited · published · shared · viewed · exported · password_changed · token_regenerated · revoked · archived · …
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['report_share_id', 'created_at']);
        });

        // A report's one project becomes the first row of its project list.
        DB::statement('insert into report_share_projects (tenant_id, report_share_id, project_id) select tenant_id, id, project_id from report_shares where project_id is not null on conflict do nothing');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_activities');
        Schema::dropIfExists('report_revisions');
        Schema::dropIfExists('report_notes');
        Schema::dropIfExists('report_share_projects');

        Schema::table('report_shares', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'state']);
            $table->dropColumn(['state', 'client_name', 'period', 'period_from', 'period_to', 'compare_mode', 'compare_from', 'compare_to', 'attribution_model', 'currency', 'filters', 'branding', 'options', 'revision', 'published_at', 'archived_at']);
        });
    }
};
