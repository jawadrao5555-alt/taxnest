<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NestPOS: widen quantity to decimal so 1.5 KG / 0.75 LTR work
        if (Schema::hasColumn('pos_transaction_items', 'quantity')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE pos_transaction_items MODIFY COLUMN quantity DECIMAL(15,3) NOT NULL DEFAULT 1');
            } elseif ($driver === 'pgsql') {
                DB::statement('ALTER TABLE pos_transaction_items ALTER COLUMN quantity TYPE numeric(15,3) USING quantity::numeric(15,3)');
                DB::statement("ALTER TABLE pos_transaction_items ALTER COLUMN quantity SET DEFAULT 1");
            }
            // sqlite: skip
        }
    }

    public function down(): void
    {
        // Intentionally not narrowing back to integer — would lose decimal data.
    }
};
