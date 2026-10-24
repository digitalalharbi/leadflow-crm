<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The report capabilities a plan can sell on their own, added to the catalogue's features. Existing plans get the ones
 * that fit them, and no price changes: Growth gets exports and templates, Business adds attribution, white-label and
 * client OTP, Enterprise has everything. A company keeps its add-ons.
 */
return new class extends Migration
{
    private const BY_PLAN = [
        'growth' => ['report_pdf', 'report_excel', 'advanced_templates'],
        'business' => ['report_pdf', 'report_excel', 'advanced_templates', 'advanced_attribution', 'white_label_reports', 'client_otp'],
        'enterprise' => ['report_pdf', 'report_excel', 'advanced_templates', 'advanced_attribution', 'white_label_reports', 'client_otp'],
    ];

    public function up(): void
    {
        foreach (self::BY_PLAN as $key => $add) {
            $plan = DB::table('plans')->where('key', $key)->first();

            if ($plan) {
                $features = array_values(array_unique(array_merge((array) json_decode($plan->features, true), $add)));
                DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::BY_PLAN as $key => $remove) {
            $plan = DB::table('plans')->where('key', $key)->first();

            if ($plan) {
                $features = array_values(array_diff((array) json_decode($plan->features, true), $remove));
                DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
            }
        }
    }
};
