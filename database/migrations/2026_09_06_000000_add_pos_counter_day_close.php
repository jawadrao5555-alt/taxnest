<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PER-COUNTER CASH DRAWER + PER-COUNTER DAY CLOSE (Task 1375).
 *
 * Counters (pos_terminals) already carry every bill's attribution (Task 1349)
 * and the day-close surfaces already print a counter-wise SALES breakdown. What
 * a two/three-counter shop still could not do is reconcile CASH per counter:
 * there was one shop-level opening float, one counted amount and one difference,
 * so "kis counter par kami hui" had no answer.
 *
 * Three additive pieces, all idempotent (prod runs migrate --force and carries
 * known schema drift):
 *
 *  1. pos_day_openings.terminal_id — the morning float is now per DRAWER.
 *     0 = the shop drawer / "no counter", which is the state every existing row
 *     and every counter-less shop is in, so nothing changes for them. NOT NULL
 *     DEFAULT 0 for the same reason branch_id is (Task 1360): MySQL and SQLite
 *     both treat NULLs as distinct, which would make the unique index useless
 *     for exactly the rows that need it most.
 *
 *  2. pos_counter_closes — one row per counter per business date: that drawer's
 *     opening, its cash sales, expected, counted and variance. Closing a counter
 *     writes ONE row and touches no bill, so the other counters keep billing;
 *     the shop's day closes (Z-report) once every used drawer has a row.
 *
 *  3. pos_day_close_reports.counter_summary — the counter-wise reconciliation
 *     FROZEN on the Z-report, same reason stream_summary is frozen: the wash can
 *     delete reporting-OFF finals, so a later recompute would undercount. The
 *     PDF/thermal prefer this snapshot and fall back to a live rebuild for
 *     reports closed before this task.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Opening cash per drawer ──────────────────────────────────────
        if (Schema::hasTable('pos_day_openings') && !Schema::hasColumn('pos_day_openings', 'terminal_id')) {
            Schema::table('pos_day_openings', function (Blueprint $t) {
                $t->unsignedBigInteger('terminal_id')->default(0)
                    ->after(Schema::hasColumn('pos_day_openings', 'branch_id') ? 'branch_id' : 'company_id');
            });
            // Old guarantee: one opening per company (+branch) per date.
            // New: per DRAWER — the old index would refuse the second counter's
            // float on the same day.
            $this->swapUnique(
                'pos_day_openings',
                'pos_day_openings_company_branch_date_unique',
                Schema::hasColumn('pos_day_openings', 'branch_id')
                    ? ['company_id', 'branch_id', 'terminal_id', 'business_date']
                    : ['company_id', 'terminal_id', 'business_date'],
                'pos_day_openings_scope_terminal_date_unique'
            );
            // Boxes that never got the Task 1360 index still carry the original.
            $this->dropIndexIfExists('pos_day_openings', 'pos_day_openings_company_date_unique');
        }

        // ── 2. Per-counter close rows ───────────────────────────────────────
        if (!Schema::hasTable('pos_counter_closes')) {
            Schema::create('pos_counter_closes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                // Close scope (Task 1360 semantics): 0 = no branch / company-wide.
                $t->unsignedBigInteger('branch_id')->default(0);
                // 0 = the shop drawer, i.e. bills that carry no counter.
                $t->unsignedBigInteger('terminal_id')->default(0);
                $t->date('business_date');
                $t->decimal('opening_float', 14, 2)->nullable();
                $t->decimal('cash_sales', 14, 2)->default(0);
                $t->decimal('expected_cash', 14, 2)->default(0);
                // NULL = "not counted", which is NOT the same as counted zero
                // (same convention as pos_day_close_reports.counted_cash).
                $t->decimal('counted_cash', 14, 2)->nullable();
                $t->decimal('cash_variance', 14, 2)->nullable();
                $t->integer('bills_count')->default(0);
                $t->decimal('total_sales', 14, 2)->default(0);
                $t->unsignedBigInteger('closed_by')->nullable();
                $t->text('notes')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->timestamps();

                $t->unique(['company_id', 'branch_id', 'terminal_id', 'business_date'], 'pos_counter_closes_scope_unique');
                $t->index(['company_id', 'business_date'], 'pos_counter_closes_company_date_idx');
            });
        }

        // ── 3. Frozen counter reconciliation on the Z-report ────────────────
        if (Schema::hasTable('pos_day_close_reports') && !Schema::hasColumn('pos_day_close_reports', 'counter_summary')) {
            Schema::table('pos_day_close_reports', function (Blueprint $t) {
                $t->text('counter_summary')->nullable();
            });
        }
    }

    /**
     * Replace a unique index with a wider one. The new index is a superset of
     * the old, so creating it can never fail on existing data; both statements
     * are wrapped because prod drift may have lost (or already added) either.
     */
    private function swapUnique(string $table, string $oldIndex, array $columns, string $newIndex): void
    {
        $this->dropIndexIfExists($table, $oldIndex);
        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $newIndex) {
                $t->unique($columns, $newIndex);
            });
        } catch (\Throwable $e) {
            // already present from a previous partial run
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($index) {
                $t->dropUnique($index);
            });
        } catch (\Throwable $e) {
            // index already gone (or never existed on this box)
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_day_close_reports') && Schema::hasColumn('pos_day_close_reports', 'counter_summary')) {
            Schema::table('pos_day_close_reports', function (Blueprint $t) {
                $t->dropColumn('counter_summary');
            });
        }

        Schema::dropIfExists('pos_counter_closes');

        if (Schema::hasTable('pos_day_openings') && Schema::hasColumn('pos_day_openings', 'terminal_id')) {
            $this->swapUnique(
                'pos_day_openings',
                'pos_day_openings_scope_terminal_date_unique',
                Schema::hasColumn('pos_day_openings', 'branch_id')
                    ? ['company_id', 'branch_id', 'business_date']
                    : ['company_id', 'business_date'],
                'pos_day_openings_company_branch_date_unique'
            );
            Schema::table('pos_day_openings', function (Blueprint $t) {
                $t->dropColumn('terminal_id');
            });
        }
    }
};
