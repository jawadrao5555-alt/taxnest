<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // information_schema is MySQL-only; on sqlite (tests) the FK-by-name
        // drop is neither needed nor supported — skip.
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $exists = DB::select("SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'inventory_movements_product_id_foreign' AND table_name = 'inventory_movements'");
        if (!empty($exists)) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropForeign('inventory_movements_product_id_foreign');
            });
        }
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }
};
