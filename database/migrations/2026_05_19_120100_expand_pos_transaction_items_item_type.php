<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T006 follow-up — pos_transaction_items.item_type is an ENUM('product','service'). Restaurant
 * manual cart lines (T006) need a third value 'manual'. Switch to a plain VARCHAR(20) so app-side
 * validation owns the allowed set (matches restaurant_order_items.item_type which is already
 * string(20)). Migration is dialect-aware for MySQL prod + Postgres dev.
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE pos_transaction_items MODIFY item_type VARCHAR(20) NOT NULL DEFAULT 'product'");
        } elseif ($driver === 'pgsql') {
            // Drop the implicit check constraint Laravel creates for enum, then widen the column.
            $check = DB::selectOne("
                SELECT conname FROM pg_constraint
                WHERE conrelid = 'pos_transaction_items'::regclass
                  AND contype = 'c'
                  AND pg_get_constraintdef(oid) ILIKE '%item_type%'
                LIMIT 1
            ");
            if ($check && !empty($check->conname)) {
                DB::statement('ALTER TABLE pos_transaction_items DROP CONSTRAINT ' . $check->conname);
            }
            DB::statement('ALTER TABLE pos_transaction_items ALTER COLUMN item_type TYPE VARCHAR(20)');
            DB::statement("ALTER TABLE pos_transaction_items ALTER COLUMN item_type SET DEFAULT 'product'");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        // Collapse any 'manual' rows back to 'service' so the tighter constraint can be re-applied.
        DB::table('pos_transaction_items')->where('item_type', 'manual')->update(['item_type' => 'service']);
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE pos_transaction_items MODIFY item_type ENUM('product','service') NOT NULL DEFAULT 'product'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE pos_transaction_items ADD CONSTRAINT pos_transaction_items_item_type_check CHECK (item_type IN ('product','service'))");
        }
    }
};
