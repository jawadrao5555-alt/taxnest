<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS reprice + monthly billing (owner-set, Aug 2026).
 *
 * The ANNUAL figure is the owner's quoted price. The two shorter cycles are
 * deliberately dearer per month so paying up-front always stays the best deal:
 * quarterly ~ +5% and monthly ~ +10% over the annual pro-rata, rounded to the
 * x49/x99 endings the ladder already uses.
 *
 * Only the four sellable packages are touched. Trial stays free, and the
 * retired Pro Max row keeps its historical price so old proofs and invoices
 * still explain themselves.
 */
return new class extends Migration
{
    /** package name => [annual, quarterly (+5%), monthly (+10%)] */
    private const PRICES = [
        'Starter'   => [17999, 4699, 1649],
        'Business'  => [24999, 6549, 2299],
        'Pro'       => [29999, 7849, 2749],
        'Unlimited' => [34999, 9199, 3199],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        // Schema-drift guards: a deploy that lands before an older column
        // migration must reprice what it can instead of 500ing the deploy.
        $hasQuarterly = Schema::hasColumn('pricing_plans', 'price_quarterly');
        $hasMonthly   = Schema::hasColumn('pricing_plans', 'price_monthly');
        $hasUpdatedAt = Schema::hasColumn('pricing_plans', 'updated_at');

        foreach (self::PRICES as $name => [$annual, $quarterly, $monthly]) {
            $update = ['price' => $annual];

            if ($hasQuarterly) {
                $update['price_quarterly'] = $quarterly;
            }
            if ($hasMonthly) {
                $update['price_monthly'] = $monthly;
            }
            if ($hasUpdatedAt) {
                $update['updated_at'] = now();
            }

            DB::table('pricing_plans')
                ->where('product_type', 'pos')
                ->where('is_trial', false)
                ->where('name', $name)
                ->update($update);
        }
    }

    public function down(): void
    {
        // Prices are owner-managed and change on their own schedule; a rollback
        // must never resurrect a stale ladder over a newer decision.
    }
};
