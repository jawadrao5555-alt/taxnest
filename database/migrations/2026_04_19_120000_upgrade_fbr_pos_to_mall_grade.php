<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Allow decimal quantities (1.5 KG, 0.75 LTR, 2.25 MTR) — mall-grade
        if (Schema::hasColumn('fbr_pos_transaction_items', 'quantity')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE fbr_pos_transaction_items MODIFY COLUMN quantity DECIMAL(15,3) NOT NULL DEFAULT 1');
            } elseif ($driver === 'pgsql') {
                DB::statement('ALTER TABLE fbr_pos_transaction_items ALTER COLUMN quantity TYPE numeric(15,3) USING quantity::numeric(15,3)');
                DB::statement("ALTER TABLE fbr_pos_transaction_items ALTER COLUMN quantity SET DEFAULT 1");
            }
            // sqlite: skip (already flexible typing)
        }

        // 2) Add per-item discount (PKR amount) for mall-style line discounts
        if (!Schema::hasColumn('fbr_pos_transaction_items', 'item_discount')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                $table->decimal('item_discount', 15, 2)->default(0)->after('discount');
            });
        }

        // 3) Add barcode + SKU to products for scanner-driven checkout
        if (!Schema::hasColumn('products', 'barcode')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('barcode', 64)->nullable()->after('name');
                $table->string('sku', 64)->nullable()->after('barcode');
            });
            try { DB::statement('CREATE INDEX products_barcode_idx ON products (company_id, barcode)'); } catch (\Throwable $e) {}
            try { DB::statement('CREATE INDEX products_sku_idx ON products (company_id, sku)'); } catch (\Throwable $e) {}
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fbr_pos_transaction_items', 'item_discount')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                $table->dropColumn('item_discount');
            });
        }
        if (Schema::hasColumn('products', 'barcode')) {
            try { DB::statement('DROP INDEX products_barcode_idx ON products'); } catch (\Throwable $e) {
                try { DB::statement('DROP INDEX products_barcode_idx'); } catch (\Throwable $e2) {}
            }
            try { DB::statement('DROP INDEX products_sku_idx ON products'); } catch (\Throwable $e) {
                try { DB::statement('DROP INDEX products_sku_idx'); } catch (\Throwable $e2) {}
            }
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(['barcode', 'sku']);
            });
        }
        // Quantity revert intentionally skipped (would lose decimal data).
    }
};
