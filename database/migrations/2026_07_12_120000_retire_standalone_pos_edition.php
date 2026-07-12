<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the Standalone POS edition entirely (owner decision, Jul 2026).
 *
 *  1. Any company still on pos_integration_mode='standalone' is flipped to 'pra'
 *     (the flip was always one-way standalone→pra anyway).
 *  2. Active/inactive subscriptions pointing at a standalone pricing plan are
 *     re-pointed to the same-named PRA POS plan (Trial→Trial, Starter→Starter, …).
 *  3. Standalone pricing plans are deleted.
 *  4. PRA POS plan limits are pinned to the new package structure
 *     (Starter 1 user / 500 bills-month, Business 5 / 2000, Pro unlimited)
 *     so production gets them even if the rows drifted.
 *
 * Idempotent: every step is guarded and re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Flip standalone companies to PRA mode.
        if (Schema::hasColumn('companies', 'pos_integration_mode')) {
            DB::table('companies')
                ->where('pos_integration_mode', 'standalone')
                ->update(['pos_integration_mode' => 'pra']);
        }

        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        // 2. Re-point subscriptions from standalone plans to same-named pos plans.
        $standalonePlans = DB::table('pricing_plans')->where('product_type', 'standalone')->get();

        foreach ($standalonePlans as $sPlan) {
            $posPlan = DB::table('pricing_plans')
                ->where('product_type', 'pos')
                ->where('name', $sPlan->name)
                ->first();

            if (!$posPlan) {
                // Fallback: cheapest non-trial pos plan (or trial for trial rows)
                $posPlan = DB::table('pricing_plans')
                    ->where('product_type', 'pos')
                    ->where('is_trial', (bool) $sPlan->is_trial)
                    ->orderBy('price')
                    ->first();
            }

            if ($posPlan && Schema::hasTable('subscriptions')) {
                DB::table('subscriptions')
                    ->where('pricing_plan_id', $sPlan->id)
                    ->update(['pricing_plan_id' => $posPlan->id]);
            }
        }

        // 3. Delete standalone plans (subs already moved; guard anyway).
        $remaining = Schema::hasTable('subscriptions')
            ? DB::table('subscriptions')
                ->whereIn('pricing_plan_id', $standalonePlans->pluck('id'))
                ->count()
            : 0;

        if ($remaining === 0) {
            DB::table('pricing_plans')->where('product_type', 'standalone')->delete();
        }

        // 4. Pin the new PRA POS package limits + marketing bullets
        //    (annual prices unchanged).
        $limits = [
            'Starter' => [
                'invoice_limit' => 500,
                'user_limit'    => 1,
                'features'      => json_encode([
                    '1 team account',
                    '500 bills per month',
                    'PRA fiscal receipts',
                    'Thermal receipt printing',
                    'Basic reports',
                ]),
            ],
            'Business' => [
                'invoice_limit' => 2000,
                'user_limit'    => 5,
                'features'      => json_encode([
                    '5 team accounts',
                    '2,000 bills per month',
                    'PRA fiscal receipts',
                    'Offline billing + auto-sync',
                    'Advanced reports',
                    'Multi-terminal support',
                ]),
            ],
            'Pro' => [
                'invoice_limit' => -1,
                'user_limit'    => -1,
                'features'      => json_encode([
                    'Unlimited team accounts',
                    'Unlimited monthly billing',
                    'All features unlocked',
                    'Inventory module',
                    'Advanced analytics',
                    'Priority support',
                ]),
            ],
        ];

        foreach ($limits as $name => $vals) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')
                ->where('is_trial', false)
                ->where('name', $name)
                ->update($vals);
        }
    }

    public function down(): void
    {
        // Irreversible by design — standalone edition is retired.
    }
};
