<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure a free-trial pricing plan exists for every self-registration product
     * (di / pos / fbrpos) with a 20-invoice cap, and seed default support / payment
     * SystemSetting keys so the admin settings page never errors on a missing row.
     *
     * Idempotent + PROD self-heal safe: only creates rows that are missing.
     */
    public function up(): void
    {
        if (Schema::hasTable('pricing_plans')) {
            foreach (['di', 'pos', 'fbrpos'] as $type) {
                $exists = DB::table('pricing_plans')
                    ->where('product_type', $type)
                    ->where('is_trial', true)
                    ->exists();

                if (!$exists) {
                    $row = [
                        'name' => 'Trial',
                        'product_type' => $type,
                        'is_trial' => true,
                        'invoice_limit' => 20,
                        'price' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (Schema::hasColumn('pricing_plans', 'price_monthly')) $row['price_monthly'] = 0;
                    if (Schema::hasColumn('pricing_plans', 'user_limit')) $row['user_limit'] = 2;
                    if (Schema::hasColumn('pricing_plans', 'branch_limit')) $row['branch_limit'] = 1;
                    if (Schema::hasColumn('pricing_plans', 'max_terminals')) $row['max_terminals'] = 1;
                    if (Schema::hasColumn('pricing_plans', 'max_users')) $row['max_users'] = 2;
                    if (Schema::hasColumn('pricing_plans', 'max_products')) $row['max_products'] = 50;
                    if (Schema::hasColumn('pricing_plans', 'inventory_enabled')) $row['inventory_enabled'] = true;
                    if (Schema::hasColumn('pricing_plans', 'reports_enabled')) $row['reports_enabled'] = true;

                    DB::table('pricing_plans')->insert($row);
                } else {
                    // Normalize any pre-existing trial plan(s) to the required 20-invoice cap
                    // so environments seeded before this policy still enforce the limit.
                    DB::table('pricing_plans')
                        ->where('product_type', $type)
                        ->where('is_trial', true)
                        ->update([
                            'invoice_limit' => 20,
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        if (Schema::hasTable('system_settings')) {
            $defaults = [
                'support_whatsapp_number' => '',
                'payment_bank_name' => '',
                'payment_account_title' => '',
                'payment_account_number' => '',
                'payment_iban' => '',
                'payment_instructions' => '',
            ];
            foreach ($defaults as $key => $value) {
                $present = DB::table('system_settings')->where('key', $key)->exists();
                if (!$present) {
                    DB::table('system_settings')->insert([
                        'key' => $key,
                        'value' => $value,
                        'description' => 'Trial / payment / support configuration',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: keep trial plans and settings in place.
    }
};
