<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Schema Drift Catch-Up (part 2)
 * ===============================
 * Detected via staging data sync on 2026-04-23.
 *
 * Problem:
 *   Original migration (create_nestpos_module) declared
 *     $table->string('invoice_number')->unique();
 *   which makes invoice_number globally unique.
 *
 *   Production Postgres was manually corrected to composite unique on
 *     (company_id, invoice_number)
 *   because POS invoice numbers reset per company — the same POS-2026-00001
 *   legitimately exists for multiple companies.
 *
 * This migration aligns all environments with the correct composite unique.
 *
 * Rules followed:
 *   - Idempotent (only acts when state is wrong)
 *   - Safe: drops single-col unique only if composite can replace it without violations
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_transactions')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        // Check whether a single-column unique on invoice_number exists
        $hasSingleUnique = false;
        $hasCompositeUnique = false;

        if ($driver === 'mysql') {
            $idx = DB::select("SHOW INDEX FROM pos_transactions WHERE Non_unique = 0");
            $groups = [];
            foreach ($idx as $row) {
                $groups[$row->Key_name][$row->Seq_in_index] = $row->Column_name;
            }
            foreach ($groups as $name => $cols) {
                ksort($cols);
                $colList = array_values($cols);
                if ($colList === ['invoice_number']) {
                    $hasSingleUnique = $name;
                }
                if ($colList === ['company_id', 'invoice_number'] ||
                    $colList === ['invoice_number', 'company_id']) {
                    $hasCompositeUnique = true;
                }
            }
        } elseif ($driver === 'pgsql') {
            $rows = DB::select(
                "SELECT indexname, indexdef FROM pg_indexes
                 WHERE tablename = 'pos_transactions' AND indexdef LIKE '%UNIQUE%'"
            );
            foreach ($rows as $r) {
                if (preg_match('/\(invoice_number\)/', $r->indexdef)) {
                    $hasSingleUnique = $r->indexname;
                }
                if (preg_match('/\(company_id, invoice_number\)/', $r->indexdef)) {
                    $hasCompositeUnique = true;
                }
            }
        }

        // Add composite unique first (if missing)
        if (!$hasCompositeUnique) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->unique(['company_id', 'invoice_number'], 'pos_transactions_company_invoice_unique');
            });
        }

        // Then drop the incorrect single-column unique
        if ($hasSingleUnique) {
            Schema::table('pos_transactions', function (Blueprint $table) use ($hasSingleUnique) {
                $table->dropUnique($hasSingleUnique);
            });
        }
    }

    public function down(): void
    {
        // No-op: reverting to a single-column unique would fail on prod data
        // (duplicate POS-2026-00001 across companies is valid business state)
    }
};
