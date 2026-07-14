<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS package tier update (owner rules Jul 2026):
 *  - Pro is NO LONGER unlimited: 10 team accounts + 2 branch accounts
 *    (bills stay unlimited).
 *  - NEW top tier "Unlimited" @ Rs 39,999/year: unlimited team accounts,
 *    unlimited branches, unlimited bills.
 *  - companies.requested_plan_id: the package the shop picked at POS
 *    registration — shown to the admin at approval time; approval assigns
 *    a 1-year subscription of exactly that plan.
 *
 * Idempotent / self-healing (prod runs `migrate --force`, never seeds):
 * safe to re-run; already-applied pieces are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'requested_plan_id')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->unsignedBigInteger('requested_plan_id')->nullable()->after('product_type');
            });
        }

        // ── Pro: 10 team accounts + 2 branches (was unlimited) ──
        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where('name', 'Pro')
            ->update([
                'user_limit' => 10,
                'branch_limit' => 2,
                'features' => json_encode([
                    '10 team accounts',
                    '2 branch accounts',
                    'Unlimited monthly billing',
                    'All features unlocked',
                    'Inventory module',
                    'Advanced analytics',
                    'Priority support',
                ]),
                'updated_at' => now(),
            ]);

        // ── New top tier: Unlimited @ 39,999/year ──
        $exists = DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where('name', 'Unlimited')
            ->exists();

        if (!$exists) {
            DB::table('pricing_plans')->insert([
                'name' => 'Unlimited',
                'product_type' => 'pos',
                // POS price semantics: `price` IS the annual total (6% baked in).
                'price' => 39999.00,
                'invoice_limit' => -1,
                'user_limit' => -1,
                'branch_limit' => -1,
                'is_trial' => 0,
                'inventory_enabled' => 1,
                'reports_enabled' => 1,
                'features' => json_encode([
                    'Unlimited team accounts',
                    'Unlimited branch accounts',
                    'Unlimited monthly billing',
                    'All features unlocked',
                    'Inventory module',
                    'Advanced analytics',
                    'Priority support',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Data migration — intentionally irreversible (matches repo convention).
    }
};
