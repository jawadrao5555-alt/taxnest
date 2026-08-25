<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Payment aa rahi hai — ONLINE" marker on a held restaurant order
 * (owner batch, 26 Aug 2026).
 *
 * The counter prints a Proof Bill for a dine-in table that says NOT PAID; the
 * cashier then waits for cash. When the customer says they will transfer the
 * money online, the slip must say so instead — and the bill must stay OPEN
 * (not final) until someone actually sees the payment.
 *
 * Deliberately NOT a new `status` value: held/preparing/ready drive the KDS,
 * the table board, the pending-bills tile and the day-close blocker. A separate
 * nullable stamp rides alongside the existing status so none of those
 * predicates change.
 *
 * Idempotent + hasColumn-guarded (prod schema drift: some live installs carry
 * migrations marked "Ran" whose columns never landed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('restaurant_orders')) {
            return;
        }

        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_orders', 'online_payment_awaited_at')) {
                $table->timestamp('online_payment_awaited_at')->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('restaurant_orders', 'online_payment_marked_by')) {
                $table->unsignedBigInteger('online_payment_marked_by')->nullable()->after('online_payment_awaited_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('restaurant_orders')) {
            return;
        }

        Schema::table('restaurant_orders', function (Blueprint $table) {
            foreach (['online_payment_marked_by', 'online_payment_awaited_at'] as $column) {
                if (Schema::hasColumn('restaurant_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
