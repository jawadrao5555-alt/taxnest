<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize legacy payment_method='card' rows to 'debit_card'.
 *
 * Historical context: the POS sale screen normalised the UI's "Card" choice to
 * 'debit_card' before writing (implemented ~10 Aug 2026). Rows created before
 * that change still carry 'card'. 72 such rows existed across 6 companies on
 * the day this migration was written. The Transactions and Sales Reports filters
 * now do a bucket-aware whereIn so legacy rows are already surfaced correctly,
 * but normalising the stored value kills the entire class of future confusion.
 *
 * Safe to re-run: updates are idempotent (no 'card' rows remain after first run).
 * Does NOT touch 'online', 'split', 'qr_payment', or any other value.
 * Does NOT affect already-submitted PRA fiscal records (pra_status / pra_invoice_number
 * are untouched; only the local payment_method label is corrected).
 */
return new class extends Migration
{
    public function up(): void
    {
        // pos_transactions ─────────────────────────────────────────────────────
        if (\Schema::hasColumn('pos_transactions', 'payment_method')) {
            DB::table('pos_transactions')
                ->where('payment_method', 'card')
                ->update(['payment_method' => 'debit_card']);
        }

        // pos_payments (split-payment detail rows) ─────────────────────────────
        if (\Schema::hasColumn('pos_payments', 'payment_method')) {
            DB::table('pos_payments')
                ->where('payment_method', 'card')
                ->update(['payment_method' => 'debit_card']);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: reverting normalised data back to the legacy
        // alias would re-introduce the filtering bug and is not useful.
    }
};
