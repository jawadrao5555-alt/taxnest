<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Delivery Charges" kitchen ki parchi par nahi chhapni chahiye (1 Sep 2026).
 *
 * Delivery charge cart mein aik MANUAL line ki shakl mein jata hai. KOT/KDS ka
 * koi bhi hissa item_type par chhant-phatak nahi karta — PosStation::mapItems
 * jaan boojh kar manual/service rows ko default kitchen station par bhejta hai,
 * kyunke bohat si manual lines asli khana hoti hain ("extra raita", "half
 * plate"). Is liye sirf 'manual' ko nikaal dena ghalat hota.
 *
 * Hal: har line par apna pakka nishan. Client jis line ke liye kehta hai ke
 * yeh kitchen ki cheez nahi (abhi sirf delivery charge), us ka nishan row ke
 * saath hamesha ke liye mehfooz ho jata hai — baad mein reprint, KDS, ya
 * transaction se bane KOT, sab aik hi sach parhte hain.
 *
 * Idempotent + per-column guard (PROD schema drift ka sabaq).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['restaurant_order_items', 'pos_transaction_items'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'skip_kitchen')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('skip_kitchen')->default(false)->after('item_name');
            });
        }
    }

    public function down(): void
    {
        foreach (['restaurant_order_items', 'pos_transaction_items'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'skip_kitchen')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('skip_kitchen');
                });
            }
        }
    }
};
