<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Task 1389 — FBR store-slip REPRINT gate. The FBR sale screen prints its
// kitchen/store slip from two places: a held sale (fbr_pos_held_sales — FBR
// holds are JSON carts, there is no RestaurantOrder row) and a completed bill
// (fbr_pos_transactions). Neither carried a "the slip is already out" signal,
// so nothing could tell a genuine FIRST print from a reprint.
//
// These stamps are the FBR twins of pos_transactions.kot_sent_at: written the
// first time a slip is rendered/enqueued, read by
// KotPrintService::isTransactionReprint() so only real reprints are gated.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fbr_pos_held_sales') && !Schema::hasColumn('fbr_pos_held_sales', 'kot_sent_at')) {
            Schema::table('fbr_pos_held_sales', function (Blueprint $table) {
                $table->timestamp('kot_sent_at')->nullable();
            });
        }
        if (Schema::hasTable('fbr_pos_transactions') && !Schema::hasColumn('fbr_pos_transactions', 'kot_sent_at')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->timestamp('kot_sent_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fbr_pos_held_sales') && Schema::hasColumn('fbr_pos_held_sales', 'kot_sent_at')) {
            Schema::table('fbr_pos_held_sales', function (Blueprint $table) {
                $table->dropColumn('kot_sent_at');
            });
        }
        if (Schema::hasTable('fbr_pos_transactions') && Schema::hasColumn('fbr_pos_transactions', 'kot_sent_at')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->dropColumn('kot_sent_at');
            });
        }
    }
};
