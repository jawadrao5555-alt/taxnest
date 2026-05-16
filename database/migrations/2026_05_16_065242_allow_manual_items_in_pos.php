<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T006: Restaurant POS allows manual cart lines (item_id=null, item_type='manual').
        // Make restaurant_order_items.item_id nullable so manual rows can persist on hold.
        if (Schema::hasTable('restaurant_order_items')) {
            Schema::table('restaurant_order_items', function (Blueprint $table) {
                $table->unsignedBigInteger('item_id')->nullable()->change();
            });
        }

        // pos_transaction_items.item_type was an enum('product','service') — extend with 'manual'.
        // Use raw SQL because doctrine/dbal enum altering is fragile across pgsql/mysql.
        if (Schema::hasTable('pos_transaction_items')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE pos_transaction_items MODIFY COLUMN item_type ENUM('product','service','manual') NOT NULL DEFAULT 'product'");
                DB::statement("ALTER TABLE pos_transaction_items MODIFY COLUMN item_id BIGINT UNSIGNED NULL");
            } elseif ($driver === 'pgsql') {
                DB::statement("ALTER TABLE pos_transaction_items ALTER COLUMN item_type TYPE varchar(20)");
                DB::statement("ALTER TABLE pos_transaction_items ALTER COLUMN item_id DROP NOT NULL");
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('restaurant_order_items')) {
            // Best-effort revert; if nullable rows exist this would fail, so guard.
            DB::table('restaurant_order_items')->whereNull('item_id')->delete();
            Schema::table('restaurant_order_items', function (Blueprint $table) {
                $table->unsignedBigInteger('item_id')->nullable(false)->change();
            });
        }

        if (Schema::hasTable('pos_transaction_items')) {
            DB::table('pos_transaction_items')->where('item_type', 'manual')->update(['item_type' => 'product']);
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE pos_transaction_items MODIFY COLUMN item_type ENUM('product','service') NOT NULL DEFAULT 'product'");
            }
        }
    }
};
