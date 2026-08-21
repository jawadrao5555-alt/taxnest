<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-bill delete quota ledger (Task 1372).
 *
 * PlanLimitService::canCreatePosBill counts the FINAL bills still sitting in
 * pos_transactions, so any hard delete silently returns a slot to the shop.
 * The two BATCH deletes already close that hole with their own ledgers
 * (pos_day_close_reports.deleted_final_count, pos_local_series_resets
 * .deleted_final_count); the one-by-one admin delete on a bill page had no
 * counter at all — delete a reporting-OFF final, bill again for free.
 *
 * One row per deleted bill THAT HAD CONSUMED QUOTA (completed, non-local,
 * non-return). Provisionals, drafts and returns never consumed a slot, so they
 * are never recorded. sold_at is the deleted bill's created_at — the exact
 * basis PlanLimitService's live count uses — so the add-back lands in the
 * bill's OWN calendar month and a previous month's delete can never inflate
 * (or deflate) the current one.
 *
 * Never updated after insert.
 *
 * Idempotent (hasTable guard) — safe to re-run on PROD.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_bill_deletions')) {
            return;
        }

        Schema::create('pos_bill_deletions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            // The deleted bill (audit only — the row itself is gone).
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('invoice_number')->nullable();
            // QUOTA ANCHOR: the deleted bill's created_at.
            $table->timestamp('sold_at')->nullable()->index();
            // Trading day the bill belonged to (audit / Z-report alignment).
            $table->date('business_date')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();

            // A replayed / raced DELETE must never bank the same bill twice.
            $table->unique(['company_id', 'transaction_id'], 'pos_bill_deletions_company_txn_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_bill_deletions');
    }
};
