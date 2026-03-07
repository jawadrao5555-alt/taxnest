<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('pricing_plans', 'product_type')) {
                $table->string('product_type', 20)->default('di')->after('price');
            }
        });

        DB::table('pricing_plans')->whereNull('product_type')->orWhere('product_type', '')->update(['product_type' => 'di']);

        $posExists = DB::table('pricing_plans')->where('product_type', 'pos')->exists();
        if (!$posExists) {
            DB::table('pricing_plans')->insert([
                ['name' => 'Starter', 'price' => 9999, 'invoice_limit' => 500, 'user_limit' => 1, 'branch_limit' => 1, 'is_trial' => false, 'max_terminals' => 1, 'max_users' => 2, 'max_products' => 100, 'inventory_enabled' => false, 'reports_enabled' => true, 'product_type' => 'pos', 'features' => json_encode(['POS Billing', 'Thermal Receipt', 'Cash / Card / QR Payments', 'Basic Reports']), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Business', 'price' => 14999, 'invoice_limit' => 2000, 'user_limit' => 5, 'branch_limit' => 3, 'is_trial' => false, 'max_terminals' => 3, 'max_users' => 5, 'max_products' => 500, 'inventory_enabled' => false, 'reports_enabled' => true, 'product_type' => 'pos', 'features' => json_encode(['POS Billing', 'Offline Billing', 'PRA Integration', 'Advanced Reports', 'Multi-terminal Support']), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Pro', 'price' => 24999, 'invoice_limit' => -1, 'user_limit' => -1, 'branch_limit' => -1, 'is_trial' => false, 'max_terminals' => -1, 'max_users' => -1, 'max_products' => -1, 'inventory_enabled' => true, 'reports_enabled' => true, 'product_type' => 'pos', 'features' => json_encode(['PRA Fiscal Reporting', 'Inventory Module', 'Advanced Analytics', 'Priority Support']), 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        DB::table('pricing_plans')->where('product_type', 'pos')->delete();

        Schema::table('pricing_plans', function (Blueprint $table) {
            $table->dropColumn('product_type');
        });
    }
};
