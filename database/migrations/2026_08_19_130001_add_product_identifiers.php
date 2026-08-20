<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'barcode')) {
                $table->string('barcode', 80)->nullable()->index()->after('name');
            }
            if (!Schema::hasColumn('products', 'sku')) {
                $table->string('sku', 80)->nullable()->index()->after('barcode');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            foreach (['sku', 'barcode'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};