<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may do what, decided by the workspace instead of by the release.
 *
 * `access_roles` are the roles a workspace builds for itself (a "Sales Rep without money", a
 * "Finance with the revenue only"). `permission_rules` are the changes a workspace makes on top
 * of the roles it was given, in four layers: workspace default → role → project → user. A rule says
 * allow or deny for one permission (`campaigns.edit`) or one element of the interface
 * (`ui:dashboard.kpi.revenue`); the later layer wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_roles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 48);                                  // slug; never one of the built-in role names
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->string('base_role', 32);                            // the built-in role whose scoping it borrows
            $table->boolean('project_scoped')->default(false);
            $table->boolean('external')->default(false);
            $table->json('grants');                                     // {"leads": ["view","edit"], ...}
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('permission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('layer', 12);                                // workspace · role · project · user
            $table->string('role_key', 48)->nullable();                 // role layer: whose; project layer: narrows to one role
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('permission', 96);                           // "leads.edit" or "ui:dashboard.kpi.revenue"
            $table->string('effect', 5);                                // allow · deny
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'layer']);
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('access_role_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('access_role_id'));
        Schema::dropIfExists('permission_rules');
        Schema::dropIfExists('access_roles');
    }
};
