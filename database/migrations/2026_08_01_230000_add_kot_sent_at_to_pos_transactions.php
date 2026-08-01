<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Payment First, Then KOT" v2 (customer feedback, 1 Aug 2026): the KOT for a
// delivery provisional bill must be fireable the moment PAYMENT CONFIRMS —
// which can be hours before the bill is made final at night. This stamp tracks
// whether a transaction's kitchen ticket has been rendered/printed yet, so the
// F10 list can show a "Send KOT" button and promote can skip a duplicate KOT.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_transactions', 'kot_sent_at')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->timestamp('kot_sent_at')->nullable()->after('order_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_transactions', 'kot_sent_at')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->dropColumn('kot_sent_at');
            });
        }
    }
};
