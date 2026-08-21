<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1350 — POS package matrix cleanup (idempotent, POS-only).
 *
 * 1. UNLIMITED PRODUCTS on every paid PRA POS package. Starter (300) and
 *    Business (1000) carried a silent cap nobody advertised; owner's call is
 *    that products are included in all packages. max_products is SHARED with
 *    FBR POS / DI plans, so this migration filters product_type = 'pos' —
 *    FBR POS keeps its own product ladder untouched (out of scope).
 *
 * 2. NULL -> -1 normalisation for the counter cap. Both already mean
 *    "unlimited" everywhere in the codebase; making it explicit keeps the
 *    pre-deploy plan audit exact.
 *
 * NOT touched: max_users. The POS team-account number lives in user_limit
 * (PlanLimitService::teamAccountLimit) and nothing on a POS surface reads
 * max_users — it belongs to the DI panel's plan.limit:users middleware, which
 * counts differently (every user incl. owner and inactive, no company
 * override). Copying a POS seat count into it would hand DI a stricter cap
 * than it has today, which is exactly the kind of silent new restriction this
 * task exists to remove.
 *
 * Trial rows are left alone on purpose — trial behaviour is out of scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        $columns = Schema::getColumnListing('pricing_plans');
        if (!in_array('product_type', $columns, true)) {
            return;
        }

        $rows = DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where(function ($q) use ($columns) {
                if (in_array('is_trial', $columns, true)) {
                    $q->where('is_trial', false)->orWhereNull('is_trial');
                }
            })
            ->get();

        foreach ($rows as $row) {
            $update = [];

            // 1 — unlimited products, explicit -1.
            if (in_array('max_products', $columns, true)
                && ($row->max_products === null || (int) $row->max_products !== -1)) {
                $update['max_products'] = -1;
            }

            // 2 — counters: NULL and -1 both mean unlimited; store -1.
            if (in_array('max_terminals', $columns, true) && $row->max_terminals === null) {
                $update['max_terminals'] = -1;
            }

            if ($update) {
                if (in_array('updated_at', $columns, true)) {
                    $update['updated_at'] = now();
                }
                DB::table('pricing_plans')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: restoring the silent 300/1000 product caps
        // would lock shops out of a catalogue they were told is unlimited.
    }
};
