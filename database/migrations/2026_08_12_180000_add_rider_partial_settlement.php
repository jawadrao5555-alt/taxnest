<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rider PARTIAL cash settlement (Task 525, customer request Aug 2026):
 * "rider aadha cash abhi deta hai, baqi khullay karwa ke baad" — cashier
 * enters the amount received; it applies to the oldest bills first, fully
 * covered bills settle, and the remainder stays outstanding on the last bill.
 *
 * - pos_transactions.rider_partial_paid / fbr_pos_transactions.rider_partial_paid:
 *   cash already received against a still-OPEN bill (rider_settlement_id stays
 *   NULL until fully covered). Khata remaining = total_amount - rider_partial_paid.
 * - pos_rider_settlements.outstanding_after: rider's whole-khata remaining right
 *   after this settlement (audit: "us waqt kitna reh gaya tha").
 * - pos_rider_settlements.allocation: JSON breakdown [{bill_id, amount,
 *   business_date}] of where the received cash landed — day-close uses it to
 *   count today's receipts against older bills precisely.
 * - pos_rider_settlements.panel: 'pra' | 'fbr' — the settlements table is shared
 *   by both panels; day-close must only count its own panel's receipts.
 *
 * Idempotent (hasColumn guards) — prod schema drift safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_transactions') && !Schema::hasColumn('pos_transactions', 'rider_partial_paid')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->decimal('rider_partial_paid', 12, 2)->default(0)->after('rider_settled_at');
            });
        }

        if (Schema::hasTable('fbr_pos_transactions') && !Schema::hasColumn('fbr_pos_transactions', 'rider_partial_paid')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->decimal('rider_partial_paid', 12, 2)->default(0)->after('rider_settled_at');
            });
        }

        if (Schema::hasTable('pos_rider_settlements')) {
            Schema::table('pos_rider_settlements', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_rider_settlements', 'outstanding_after')) {
                    $table->decimal('outstanding_after', 12, 2)->nullable()->after('bill_count');
                }
                if (!Schema::hasColumn('pos_rider_settlements', 'allocation')) {
                    $table->text('allocation')->nullable()->after('outstanding_after');
                }
                if (!Schema::hasColumn('pos_rider_settlements', 'panel')) {
                    $table->string('panel', 10)->nullable()->after('allocation');
                }
            });
        }
    }

    public function down(): void
    {
        // Additive only — no destructive down (prod safety).
    }
};
