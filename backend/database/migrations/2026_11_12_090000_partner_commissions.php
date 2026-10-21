<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a partner is owed, and what has been paid: terms on the partner (a percentage of what a
 * sale brought in, or a fixed amount per sale) and a ledger of payments — so the balance is always
 * "due less paid", never a number somebody typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('commission_type', 12)->default('percentage');   // percentage · fixed
            $table->unsignedBigInteger('commission_fixed_minor')->nullable(); // per won deal
        });

        Schema::create('partner_commission_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->date('paid_on');
            $table->string('reference', 120)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'partner_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_commission_payments');
        Schema::table('partners', fn (Blueprint $t) => $t->dropColumn(['commission_type', 'commission_fixed_minor']));
    }
};
