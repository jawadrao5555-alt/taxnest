<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner rule Jul 2026: Business POS plan drops from 3 branches to 1
 * (Pro now has 2, Unlimited has -1 — the ladder must ascend).
 * Idempotent: safe to re-run; prod applies via migrate --force.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where('name', 'Business')
            ->where('branch_limit', '!=', 1)
            ->update(['branch_limit' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where('name', 'Business')
            ->update(['branch_limit' => 3, 'updated_at' => now()]);
    }
};
