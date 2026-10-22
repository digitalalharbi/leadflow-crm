<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the deal actually closed for.
 *
 * The product already stores `budget_min` and `budget_max` — what the buyer
 * said they could spend before anybody showed them anything. That is a
 * qualification signal, not revenue, and using it as revenue would produce a
 * return-on-spend figure built on a number the customer made up in a form.
 *
 * Nullable on purpose. A sale recorded without its value is still a sale, and
 * refusing to close one until somebody knows the final figure is how a CRM
 * ends up with stages people route around.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Minor units, like campaign spend: a property price is the last
            // number in this product that should ever be a float.
            $table->unsignedBigInteger('deal_value_minor')->nullable()->after('won_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('deal_value_minor');
        });
    }
};
