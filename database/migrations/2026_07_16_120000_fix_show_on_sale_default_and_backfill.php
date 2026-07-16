<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROD drift fix: on the owner's cPanel MySQL the pos_products.show_on_sale
 * column ended up with DEFAULT 0 (the June migration defines DEFAULT 1 — dev
 * is correct). Result: every CSV-imported / default-relying product was
 * created hidden, so whole shops (e.g. Frost and Brew: 126 of 129 hidden)
 * saw an EMPTY sale-screen grid.
 *
 * 1) Re-assert DEFAULT 1 on the column (idempotent, MySQL + pgsql safe).
 * 2) One-time backfill: companies where >= 90% of their ACTIVE products are
 *    hidden (and they have > 5 products) are clearly drift victims, not
 *    deliberate per-item hides — flip all their products back to visible.
 *    Companies using the hide feature normally (a few hidden items) are
 *    left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_products', 'show_on_sale')) {
            Schema::table('pos_products', function ($table) {
                $table->boolean('show_on_sale')->default(true)->after('is_active');
            });
            return; // fresh column: default 1, nothing hidden yet
        }

        // 1) Enforce DEFAULT 1 regardless of what the live column currently has.
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE pos_products MODIFY show_on_sale TINYINT(1) NOT NULL DEFAULT 1');
            } elseif ($driver === 'pgsql') {
                DB::statement('ALTER TABLE pos_products ALTER COLUMN show_on_sale SET DEFAULT true');
            }
        } catch (\Throwable $e) {
            // Non-fatal: backfill below still rescues the data.
        }

        // 2) Backfill drift-affected companies (>=90% of active products hidden).
        $rows = DB::table('pos_products')
            ->select('company_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN show_on_sale = 0 OR show_on_sale IS NULL THEN 1 ELSE 0 END) as hidden')
            ->where('is_active', 1)
            ->groupBy('company_id')
            ->having('total', '>', 5)
            ->get();

        foreach ($rows as $row) {
            if ($row->total > 0 && ($row->hidden / $row->total) >= 0.9) {
                DB::table('pos_products')
                    ->where('company_id', $row->company_id)
                    ->update(['show_on_sale' => 1]);
            }
        }
    }

    public function down(): void
    {
        // One-way data rescue — nothing sensible to reverse.
    }
};
