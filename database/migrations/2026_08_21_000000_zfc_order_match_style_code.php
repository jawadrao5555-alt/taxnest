<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 651 — ZFC Pizza Point: Order Matching token → code (owner decision, 13 Aug 2026).
 *
 * Why: token is a shared daily series across local + PRA bills, so a token gap
 * on a PRA receipt betrays the existence of local bills ("Token 4 kahan gaya?").
 * Code style prints the full bold ORD- number on the KOT and its random suffix
 * on the receipt — nothing sequential, nothing countable (same style Pizza
 * Master already runs).
 *
 * The flip was applied directly on LIVE (13 Aug 2026) via the same validated
 * value the Receipt Settings save path writes; this migration is the auditable
 * record and makes any rebuilt environment converge.
 *
 * Idempotent + surgical:
 *  - hasColumn guard (PROD drift self-heal convention).
 *  - Matches id 28 AND the ZFC name AND current style 'token' — it can never
 *    touch a different company on another instance, and it never overrides a
 *    LATER choice the shop makes itself in Receipt Settings (already-'code'
 *    rows, including live today, are a clean no-op).
 *  - Token allocation logic untouched; no other ZFC settings touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies') || !Schema::hasColumn('companies', 'order_match_style')) {
            return;
        }

        DB::table('companies')
            ->where('id', 28)
            ->where('name', 'like', '%ZFC%')
            ->where('order_match_style', 'token')
            ->update(['order_match_style' => 'code']);
    }

    public function down(): void
    {
        // Owner decision — revert via a fresh migration (or Receipt Settings) if ever needed.
    }
};
