<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the Guided Keyboard Billing flow UNIVERSAL across every PRA POS register type.
 *
 * Owner request: the guided Enter-driven keyboard chain (customer -> items -> [type] ->
 * cart -> bill) should be ON by default for EVERY PRA POS company and BOTH register
 * types (universal/retail + restaurant), not just the companies that were toggled on
 * during testing. The flow is gated on companies.pos_guided_flow_enabled, read by both
 * sale screens, so flipping this column for all companies + changing the column default
 * makes it the standard everywhere — now and for newly created companies.
 *
 * It stays an opt-OUT, not a lock: cashiers can still turn it off per company via
 * POS -> Features -> "Guided Keyboard Billing" (that toggle writes the same column).
 *
 * Idempotent: re-running re-sets the same value and the default alter is wrapped so it
 * never aborts the migration if the engine rejects a repeat.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        // 0. Self-heal: some cPanel PROD copies have the migration history but are
        //    missing the column (partial schema drift). Add it (default ON) if absent
        //    instead of bailing, so this migration alone makes the feature universal.
        if (! Schema::hasColumn('companies', 'pos_guided_flow_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pos_guided_flow_enabled')->default(true);
            });
        }

        // 1. Default ON for NEW companies (MySQL-safe; ignored if it can't apply).
        try {
            DB::statement('ALTER TABLE companies ALTER pos_guided_flow_enabled SET DEFAULT 1');
        } catch (\Throwable $e) {
            // Non-fatal: data update below still makes existing companies universal.
        }

        // 2. Turn it ON for EVERY existing company (both retail & restaurant PRA POS).
        DB::table('companies')->update(['pos_guided_flow_enabled' => true]);
    }

    public function down(): void
    {
        // No-op: the guided flag is owner-controlled via POS -> Features afterwards.
        // We intentionally do not force it back off (and do not revert the default).
    }
};
