<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 135 (Aug 2026): new DI "Premium" top tier — Rs 12,999/mo (monthly
 * price, DI convention), unlimited invoices/users/branches. Bundles the
 * premium feature set gated by DiFeatureService (white_label, public_api,
 * ai_reader, recurring_invoices).
 *
 * Idempotent ensure-row (prod runs `migrate --force`, never db:seed):
 * inserts only when no DI plan named Premium exists, so a re-run or an
 * admin-adjusted price is never clobbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        $exists = DB::table('pricing_plans')
            ->where('product_type', 'di')
            ->where('name', 'Premium')
            ->exists();

        if ($exists) {
            return;
        }

        $row = [
            'name' => 'Premium',
            'product_type' => 'di',
            'is_trial' => false,
            'invoice_limit' => -1,
            'price' => 12999,
            'features' => json_encode([
                'Everything in Enterprise — unlimited invoices, users & branches',
                'White-label branding on invoice PDFs & share pages',
                'Public REST API & webhooks for ERP integration',
                'AI Invoice Reader — draft invoices from PDF, Excel or photo',
                'Recurring invoices, payment reminders & customer statements',
                'Priority support & dedicated account manager',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Optional columns — hasColumn guards for prod schema drift.
        if (Schema::hasColumn('pricing_plans', 'user_limit')) $row['user_limit'] = -1;
        if (Schema::hasColumn('pricing_plans', 'branch_limit')) $row['branch_limit'] = -1;
        if (Schema::hasColumn('pricing_plans', 'price_monthly')) $row['price_monthly'] = 12999;
        if (Schema::hasColumn('pricing_plans', 'compare_at_price')) $row['compare_at_price'] = null;
        if (Schema::hasColumn('pricing_plans', 'reports_enabled')) $row['reports_enabled'] = true;
        if (Schema::hasColumn('pricing_plans', 'inventory_enabled')) $row['inventory_enabled'] = true;

        DB::table('pricing_plans')->insert($row);
    }

    public function down(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        $plan = DB::table('pricing_plans')
            ->where('product_type', 'di')
            ->where('name', 'Premium')
            ->where('is_trial', false)
            ->first();

        if (!$plan) {
            return;
        }

        // Never orphan subscriptions: keep the row if anyone ever subscribed.
        if (Schema::hasTable('subscriptions')) {
            $referenced = DB::table('subscriptions')->where('pricing_plan_id', $plan->id)->exists();
            if ($referenced) {
                return;
            }
        }

        DB::table('pricing_plans')->where('id', $plan->id)->delete();
    }
};
