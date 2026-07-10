<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner reprice (Jul 2026):
 *  - PRA POS (product_type='pos'): end the 50% launch offer and charge the full
 *    original price — Starter 9,999 / Business 14,999 / Pro 24,999 (annual).
 *    The launch "was" price (compare_at_price) is cleared so no phantom
 *    strike-through remains.
 *  - Standalone POS (product_type='standalone'): ~2x — Starter 5,999 /
 *    Business 9,999 / Pro 17,999 (annual). No compare_at was ever set.
 *
 * pos/standalone store the ANNUAL price directly in `price` (price_monthly stays
 * NULL). Trial (0) is untouched.
 *
 * Admin-safe + idempotent: a plan is only repriced while it still holds its
 * expected CURRENT price, so a re-run (price already new) or a plan an admin has
 * manually re-priced is skipped — never clobbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        // [product_type, name, expected_current_price, new_price, clear_compare_at]
        $map = [
            ['pos',        'Starter',   4999,  9999,  true],
            ['pos',        'Business',  7499,  14999, true],
            ['pos',        'Pro',       12499, 24999, true],
            ['standalone', 'Starter',   2999,  5999,  false],
            ['standalone', 'Business',  4999,  9999,  false],
            ['standalone', 'Pro',       8999,  17999, false],
        ];

        foreach ($map as [$type, $name, $old, $new, $clearCompare]) {
            $plan = DB::table('pricing_plans')
                ->where('product_type', $type)
                ->where('name', $name)
                ->where('price', $old)
                ->first();

            if (!$plan) {
                continue;
            }

            $update = ['price' => $new, 'updated_at' => now()];
            if ($clearCompare) {
                $update['compare_at_price'] = null;
            }

            DB::table('pricing_plans')->where('id', $plan->id)->update($update);
        }
    }

    public function down(): void
    {
        // [product_type, name, current_price, restore_price, restore_compare_at]
        $reverse = [
            ['pos',        'Starter',   9999,  4999,  9999],
            ['pos',        'Business',  14999, 7499,  14999],
            ['pos',        'Pro',       24999, 12499, 24999],
            ['standalone', 'Starter',   5999,  2999,  null],
            ['standalone', 'Business',  9999,  4999,  null],
            ['standalone', 'Pro',       17999, 8999,  null],
        ];

        foreach ($reverse as [$type, $name, $cur, $restore, $compare]) {
            $plan = DB::table('pricing_plans')
                ->where('product_type', $type)
                ->where('name', $name)
                ->where('price', $cur)
                ->first();

            if (!$plan) {
                continue;
            }

            DB::table('pricing_plans')->where('id', $plan->id)->update([
                'price' => $restore,
                'compare_at_price' => $compare,
                'updated_at' => now(),
            ]);
        }
    }
};
