<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 662 — Backfill: lock the known manually-set Order Matching choices.
 *
 * Frost and Brew (id 26) explicitly asked for 'token' after the Aug-23
 * rollout flipped everyone to 'code' (see the frost_and_brew restore
 * migration). Restore 'token' AND set the lock atomically here: on a fresh /
 * rebuilt database the migration ORDER is 08_13 restore → 08_23 rollout
 * (which flips 26 back to 'code') → this one — so this migration must not
 * depend on the row still being 'token'; it re-asserts the owner's choice
 * after the rollout and locks it so any FUTURE bulk rollout (which must
 * WHERE order_match_style_locked = false) can never override it again.
 *
 * Surgical: id + name must both match — can never touch a different company
 * on another instance. Idempotent + hasColumn guards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')
            || !Schema::hasColumn('companies', 'order_match_style')
            || !Schema::hasColumn('companies', 'order_match_style_locked')) {
            return;
        }

        DB::table('companies')
            ->where('id', 26)
            ->where('name', 'like', '%Frost%')
            ->update([
                'order_match_style'        => 'token',
                'order_match_style_locked' => true,
            ]);
    }

    public function down(): void
    {
        // Unlock via Receipt Settings semantics only — no destructive revert.
    }
};
