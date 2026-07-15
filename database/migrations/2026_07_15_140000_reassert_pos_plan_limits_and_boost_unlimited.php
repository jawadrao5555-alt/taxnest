<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Owner report (Jul 2026): live Business card showed a literal "-1 bill"
     * — prod plan rows drifted from the canonical PRA POS package limits.
     * Re-assert the canonical limits for the four named POS plans so display
     * and enforcement match dev exactly. Also punch up the Unlimited feature
     * list: the owner wants the Pro→Unlimited difference obvious enough to
     * sell the upgrade (cards render cumulatively via "Everything in <prev>,
     * plus:", so these lines only need the DELTA over Pro).
     * Idempotent: plain UPDATEs matched on product_type + name.
     */
    public function up(): void
    {
        $limits = [
            'Starter'   => ['invoice_limit' => 500,  'user_limit' => 1,  'branch_limit' => 1,  'restaurant_enabled' => 0],
            'Business'  => ['invoice_limit' => 2000, 'user_limit' => 5,  'branch_limit' => 1,  'restaurant_enabled' => 0],
            'Pro'       => ['invoice_limit' => 3000, 'user_limit' => 10, 'branch_limit' => 2,  'restaurant_enabled' => 1],
            'Unlimited' => ['invoice_limit' => -1,   'user_limit' => -1, 'branch_limit' => -1, 'restaurant_enabled' => 1],
        ];

        foreach ($limits as $name => $cols) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')
                ->where('name', $name)
                ->update($cols + ['updated_at' => now()]);
        }

        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where('name', 'Unlimited')
            ->update([
                'features' => json_encode([
                    'UNLIMITED bills every month — no cap, ever (Pro: 3,000/month)',
                    'UNLIMITED team accounts — every cashier, manager, waiter & kitchen login you need (Pro stops at 10)',
                    'UNLIMITED branches — run all your locations on one account (Pro: 2)',
                    'Every current AND future feature unlocked forever — nothing is ever locked again',
                    'Top-priority support with free onboarding & staff training',
                    'Built for chains, franchises & multi-branch restaurants',
                ], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data re-assertion + marketing copy — nothing to restore.
    }
};
