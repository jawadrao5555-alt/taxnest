<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Launch Offer — 50% OFF.
     *
     * Adds a nullable `compare_at_price` (the pre-discount "was" price used for the
     * struck-through display) and halves the live price of every paid plan.
     *
     * Idempotent + admin-safe: a row is only discounted when it still holds its
     * ORIGINAL price AND has not been discounted yet (compare_at_price IS NULL).
     * A plan an admin has manually re-priced (price no longer matches) is skipped.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('pricing_plans', 'compare_at_price')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->decimal('compare_at_price', 10, 2)->nullable()->after('price');
            });
        }

        // [product_type, name, expected current price, new 50%-off price]
        $map = [
            ['di', 'Retail', 999, 499],
            ['di', 'Business', 2999, 1499],
            ['di', 'Industrial', 6999, 3499],
            ['di', 'Enterprise', 15000, 7500],
            ['fbrpos', 'Starter', 999, 499],
            ['fbrpos', 'Business', 1999, 999],
            ['fbrpos', 'Pro', 3499, 1749],
            ['pos', 'Starter', 9999, 4999],
            ['pos', 'Business', 14999, 7499],
            ['pos', 'Pro', 24999, 12499],
        ];

        foreach ($map as [$type, $name, $old, $new]) {
            $plan = DB::table('pricing_plans')
                ->where('product_type', $type)
                ->where('name', $name)
                ->whereNull('compare_at_price')
                ->where('price', $old)
                ->first();

            if (!$plan) {
                continue;
            }

            DB::table('pricing_plans')->where('id', $plan->id)->update([
                'compare_at_price' => $old,
                'price' => $new,
                // keep price_monthly in lockstep for di/fbrpos (pos leaves it NULL)
                'price_monthly' => $plan->price_monthly !== null ? $new : null,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pricing_plans', 'compare_at_price')) {
            foreach (DB::table('pricing_plans')->whereNotNull('compare_at_price')->get() as $row) {
                DB::table('pricing_plans')->where('id', $row->id)->update([
                    'price' => $row->compare_at_price,
                    'price_monthly' => $row->price_monthly !== null ? $row->compare_at_price : null,
                    'compare_at_price' => null,
                    'updated_at' => now(),
                ]);
            }

            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->dropColumn('compare_at_price');
            });
        }
    }
};
