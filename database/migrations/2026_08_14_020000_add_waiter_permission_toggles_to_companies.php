<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 527 (owner voice notes, 12 Aug 2026): admin-controlled waiter
 * permissions — company-level toggles (waiters are excluded from per-user
 * Custom Access, so these live on the company row):
 *
 *   pos_waiter_cancel_enabled   — waiter self-cancel of own un-claimed open
 *                                 orders. DEFAULT OFF (owner: waiters must
 *                                 not cancel unless the admin allows it).
 *   pos_waiter_takeaway_enabled — waiter may punch takeaway (parcel) orders.
 *                                 DEFAULT ON (existing companies keep the
 *                                 current behavior).
 *
 * Idempotent with per-column hasColumn guards (prod schema-drift convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_waiter_cancel_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pos_waiter_cancel_enabled')->default(false);
            });
        }
        if (!Schema::hasColumn('companies', 'pos_waiter_takeaway_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pos_waiter_takeaway_enabled')->default(true);
            });
        }
    }

    public function down(): void
    {
        foreach (['pos_waiter_cancel_enabled', 'pos_waiter_takeaway_enabled'] as $col) {
            if (Schema::hasColumn('companies', $col)) {
                Schema::table('companies', function (Blueprint $table) use ($col) {
                    $table->dropColumn($col);
                });
            }
        }
    }
};
