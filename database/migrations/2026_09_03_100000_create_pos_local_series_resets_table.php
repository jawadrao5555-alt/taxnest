<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local series reset ledger (Task 1358, owner Sep 2026).
 *
 * The L-series generator hands out the SMALLEST FREE number, so day-close
 * ARCHIVED local bills keep their numbers reserved forever — a shop that later
 * switches to the "delete" policy stays stuck at (say) L-150 instead of
 * restarting at L-001. Customize POS → Local Billing now offers an explicit,
 * owner-confirmed "clear archived local bills" action.
 *
 * This table is that action's audit + QUOTA ledger: hard-deleted reporting-OFF
 * FINALS would otherwise vanish from PlanLimitService's live monthly count
 * (= a free way to buy back bill quota), exactly like the day-close delete
 * policy — which solves it with pos_day_close_reports.deleted_final_count.
 * The clear action has no day-close report to hang those counts on, so it
 * writes one row here and PlanLimitService adds the current month's
 * deleted_final_count back in.
 *
 * Idempotent (hasTable guard) — safe to re-run on PROD.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_local_series_resets')) {
            return;
        }

        Schema::create('pos_local_series_resets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            // Calendar date the clear ran — quota add-back is month-bounded on this.
            $table->date('reset_date')->index();
            // Deleted bills whose business_date falls inside reset_date's month
            // (older months' quota is already closed — never inflate this month).
            $table->unsignedInteger('deleted_final_count')->default(0);
            $table->unsignedInteger('deleted_provisional_count')->default(0);
            // Everything the action removed, any month (audit figure only).
            $table->unsignedInteger('total_deleted')->default(0);
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_local_series_resets');
    }
};
