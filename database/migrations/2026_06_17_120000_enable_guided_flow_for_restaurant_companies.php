<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turn ON the opt-in Guided Keyboard Billing flow for restaurant-mode companies.
 *
 * The restaurant POS register now ships the same guided Enter-driven keyboard chain
 * as the universal register (coach strip + customer -> items -> cart -> bill). The
 * flow is gated on companies.pos_guided_flow_enabled and is byte-identical to the
 * previous behaviour when OFF. Restaurant cashiers benefit from it immediately, so
 * this migration enables the flag for every restaurant_mode company on deploy.
 *
 * Idempotent: re-running simply re-sets the same value. Cashiers retain full control
 * afterwards via POS -> Features -> "Guided Keyboard Billing" (the toggle writes the
 * same column), so this is a default, not a lock.
 */
return new class extends Migration {
    public function up(): void
    {
        if (
            Schema::hasColumn('companies', 'pos_guided_flow_enabled') &&
            Schema::hasColumn('companies', 'restaurant_mode')
        ) {
            DB::table('companies')
                ->where('restaurant_mode', true)
                ->update(['pos_guided_flow_enabled' => true]);
        }
    }

    public function down(): void
    {
        // No-op: the guided flag is owner-controlled via POS -> Features after this
        // migration. We intentionally do not force it back off on rollback.
    }
};
