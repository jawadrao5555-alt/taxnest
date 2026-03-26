<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $fbrposExists = DB::table('pricing_plans')->where('product_type', 'fbrpos')->exists();
        if (!$fbrposExists) {
            DB::table('pricing_plans')->insert([
                ['name' => 'Starter', 'price' => 4999, 'invoice_limit' => 500, 'user_limit' => 1, 'branch_limit' => 1, 'is_trial' => false, 'max_terminals' => 1, 'max_users' => 2, 'max_products' => 100, 'inventory_enabled' => false, 'reports_enabled' => true, 'product_type' => 'fbrpos', 'features' => json_encode(['500 transactions/mo', 'FBR Real-time Submission', 'QR Code Generation', 'Cash & Card Payments', 'Basic Reports', 'Sandbox Testing']), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Business', 'price' => 7999, 'invoice_limit' => 2000, 'user_limit' => 3, 'branch_limit' => 2, 'is_trial' => false, 'max_terminals' => 3, 'max_users' => 5, 'max_products' => 500, 'inventory_enabled' => false, 'reports_enabled' => true, 'product_type' => 'fbrpos', 'features' => json_encode(['2,000 transactions/mo', 'FBR Real-time Submission', 'QR Code Generation', 'All Payment Methods', 'Advanced Reports', 'Multi-terminal Support', 'FBR Retry System']), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Pro', 'price' => 14999, 'invoice_limit' => -1, 'user_limit' => -1, 'branch_limit' => -1, 'is_trial' => false, 'max_terminals' => -1, 'max_users' => -1, 'max_products' => -1, 'inventory_enabled' => true, 'reports_enabled' => true, 'product_type' => 'fbrpos', 'features' => json_encode(['Unlimited transactions', 'FBR Real-time Submission', 'QR Code Generation', 'All Payment Methods', 'Advanced Analytics', 'Unlimited Terminals', 'Priority Support', 'FBR Retry System']), 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        DB::table('pricing_plans')->where('product_type', 'fbrpos')->delete();
    }
};
