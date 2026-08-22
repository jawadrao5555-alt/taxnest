<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS free trial: unlimited bills, only the day limit (owner, Aug 2026).
 *
 * A 10-bill trial ends before the shop has felt the system, so nobody
 * converts. The trial keeps its DAY limit — that is what makes it a trial —
 * but the bill counter comes off: -1 means "no cap" everywhere in the
 * codebase (SubscriptionAccessService skips the cap when the limit is <= 0).
 *
 * PRA POS only. DI and FBR POS trials are billed against FBR/PRA invoice
 * quotas and keep their own caps.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans') || !Schema::hasColumn('pricing_plans', 'invoice_limit')) {
            return;
        }

        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where('is_trial', true)
            ->update(['invoice_limit' => -1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // No going back: the old 10-bill cap was the thing being removed.
    }
};
