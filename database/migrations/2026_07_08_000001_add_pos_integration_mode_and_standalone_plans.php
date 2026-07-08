<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone POS offering (no PRA/FBR integration):
 *  1. companies.pos_integration_mode — 'pra' (default, existing behaviour) or
 *     'standalone' (company runs the full POS with zero government integration).
 *  2. Seeds the Standalone pricing-plan set (product_type='standalone', annual
 *     pricing like PRA POS but cheaper — no per-invoice fiscal overhead).
 *
 * Idempotent by design (per-column hasColumn guards + insert-if-missing) so it
 * self-heals on the owner's cPanel PROD where past migrations sometimes get
 * marked "Ran" without applying.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_integration_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('pos_integration_mode', 20)->default('pra')->after('pra_reporting_enabled');
            });
        }

        // Seed Standalone plans only if absent (name + product_type match).
        $plans = [
            ['name' => 'Trial',    'price' => 0,    'invoice_limit' => 10,  'max_terminals' => 1,  'max_users' => 2,  'max_products' => 50,   'inventory_enabled' => 1, 'reports_enabled' => 1, 'is_trial' => 1],
            ['name' => 'Starter',  'price' => 2999, 'invoice_limit' => 500, 'max_terminals' => 1,  'max_users' => 2,  'max_products' => 1000, 'inventory_enabled' => 1, 'reports_enabled' => 0, 'is_trial' => 0],
            ['name' => 'Business', 'price' => 4999, 'invoice_limit' => 2000,'max_terminals' => 3,  'max_users' => 5,  'max_products' => 5000, 'inventory_enabled' => 1, 'reports_enabled' => 1, 'is_trial' => 0],
            ['name' => 'Pro',      'price' => 8999, 'invoice_limit' => -1,  'max_terminals' => -1, 'max_users' => -1, 'max_products' => -1,   'inventory_enabled' => 1, 'reports_enabled' => 1, 'is_trial' => 0],
        ];

        foreach ($plans as $p) {
            $exists = DB::table('pricing_plans')
                ->where('product_type', 'standalone')
                ->where('name', $p['name'])
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('pricing_plans')->insert([
                'name' => $p['name'],
                'product_type' => 'standalone',
                'price' => $p['price'],
                'price_monthly' => null,
                'invoice_limit' => $p['invoice_limit'],
                'max_terminals' => $p['max_terminals'],
                'max_users' => $p['max_users'],
                'max_products' => $p['max_products'],
                'inventory_enabled' => $p['inventory_enabled'],
                'reports_enabled' => $p['reports_enabled'],
                'is_trial' => $p['is_trial'],
                'features' => json_encode($p['is_trial'] ? [
                    '3-day free trial',
                    'Full POS billing',
                    'Thermal receipts',
                ] : [
                    'Full POS billing',
                    'Thermal receipts',
                    'No government integration required',
                    'Works fully offline',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_integration_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_integration_mode');
            });
        }
        DB::table('pricing_plans')->where('product_type', 'standalone')->delete();
    }
};
