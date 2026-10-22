<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partners: the brokers, influencers, affiliates and outside agents who bring customers, each with
 * links of their own — and, for a broker who is given a login, a window onto exactly their own leads.
 *
 * A lead is credited to a partner by the link that first touched it. `show_revenue` is the partner's
 * own switch: whether anything shared with them may carry what a sale was worth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('type', 16)->default('broker');                  // broker · influencer · affiliate · external_agent
            $table->string('company', 160)->nullable();
            $table->string('contact_name', 160)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 32)->nullable();
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->boolean('show_revenue')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('partner_id')->nullable()->after('last_touch_link_id');
            $table->index(['tenant_id', 'partner_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('partner_id')->nullable();
        });

        Schema::table('tracking_links', function (Blueprint $table) {
            // What a QR is printed on: project · property · event · billboard · rep · broker · other
            $table->string('placement', 16)->nullable();
        });

        Schema::table('report_shares', function (Blueprint $table) {
            $table->unsignedBigInteger('partner_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_shares', fn (Blueprint $t) => $t->dropColumn('partner_id'));
        Schema::table('tracking_links', fn (Blueprint $t) => $t->dropColumn('placement'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('partner_id'));
        Schema::table('leads', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'partner_id']);
            $t->dropColumn('partner_id');
        });
        Schema::dropIfExists('partners');
    }
};
