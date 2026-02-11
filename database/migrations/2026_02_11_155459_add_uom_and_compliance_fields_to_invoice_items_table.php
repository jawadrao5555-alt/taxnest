<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('default_uom')->default('Numbers, pieces, units')->after('mrp');
            $table->string('sale_type')->default('Goods at standard rate (default)')->after('default_uom');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['default_uom', 'sale_type']);
        });
    }
};
