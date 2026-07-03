<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * DI per-company invoice numbering — sequence init + safety index.
 *
 * Background: InvoiceNumberingService previously returned
 * {identifier}DI{millisecond-timestamp}, which behaves like a GLOBAL
 * sequence across companies. It now uses companies.next_invoice_number
 * (per-company). This migration:
 *
 *  1. Ensures next_invoice_number exists (hasColumn-guarded — prod cPanel
 *     has schema-drift history).
 *  2. Initializes it to GREATEST(current, per-company invoice count + 1)
 *     so no company can ever re-issue a number.
 *  3. Adds a composite unique (company_id, invoice_number) on invoices —
 *     only when no duplicates exist (idempotent + safe on legacy data).
 *
 * Historical invoices are intentionally NOT renumbered: their numbers may
 * have been submitted to FBR (invoiceRefNo / idempotency hash).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies') || !Schema::hasTable('invoices')) {
            return;
        }

        // 1. Ensure the sequence column exists
        if (!Schema::hasColumn('companies', 'next_invoice_number')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->integer('next_invoice_number')->default(1);
            });
        }

        // 2. Initialize per-company sequence (idempotent — GREATEST keeps any
        //    counter that is already ahead).
        DB::statement("
            UPDATE companies c
            SET next_invoice_number = GREATEST(
                COALESCE(c.next_invoice_number, 1),
                (SELECT COUNT(*) FROM invoices i WHERE i.company_id = c.id) + 1
            )
        ");

        // 3. Composite unique (company_id, invoice_number) — only if absent
        //    and only if data allows it.
        $driver = DB::connection()->getDriverName();
        $hasCompositeUnique = false;

        if ($driver === 'mysql') {
            $idx = DB::select("SHOW INDEX FROM invoices WHERE Non_unique = 0");
            $groups = [];
            foreach ($idx as $row) {
                $groups[$row->Key_name][$row->Seq_in_index] = $row->Column_name;
            }
            foreach ($groups as $cols) {
                ksort($cols);
                $colList = array_values($cols);
                if ($colList === ['company_id', 'invoice_number'] ||
                    $colList === ['invoice_number', 'company_id']) {
                    $hasCompositeUnique = true;
                }
            }
        } elseif ($driver === 'pgsql') {
            $rows = DB::select(
                "SELECT indexname, indexdef FROM pg_indexes
                 WHERE tablename = 'invoices' AND indexdef LIKE '%UNIQUE%'"
            );
            foreach ($rows as $r) {
                if (preg_match('/\(company_id, invoice_number\)/', $r->indexdef)) {
                    $hasCompositeUnique = true;
                }
            }
        }

        if (!$hasCompositeUnique) {
            $dupes = DB::selectOne("
                SELECT COUNT(*) AS c FROM (
                    SELECT company_id, invoice_number
                    FROM invoices
                    WHERE invoice_number IS NOT NULL
                    GROUP BY company_id, invoice_number
                    HAVING COUNT(*) > 1
                ) d
            ");
            if ((int) ($dupes->c ?? 0) === 0) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->unique(['company_id', 'invoice_number'], 'invoices_company_invoice_unique');
                });
            }
            // If duplicates exist on legacy data we skip the index rather than
            // fail the deploy — the service-level existence check still
            // guarantees new numbers are unique.
        }
    }

    public function down(): void
    {
        // No-op: sequence values and the safety index are desired state.
    }
};
