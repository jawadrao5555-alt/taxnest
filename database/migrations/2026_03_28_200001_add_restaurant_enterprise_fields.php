<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_products', 'image')) {
                $table->string('image')->nullable()->after('category');
            }
        });

        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'kds_enabled')) {
                $table->boolean('kds_enabled')->default(true)->after('pra_reporting_enabled');
            }
            if (!Schema::hasColumn('companies', 'kitchen_printer_enabled')) {
                $table->boolean('kitchen_printer_enabled')->default(false)->after('kds_enabled');
            }
            if (!Schema::hasColumn('companies', 'print_on_hold')) {
                $table->boolean('print_on_hold')->default(false)->after('kitchen_printer_enabled');
            }
            if (!Schema::hasColumn('companies', 'print_on_pay')) {
                $table->boolean('print_on_pay')->default(true)->after('print_on_hold');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            $table->dropColumn('image');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['kds_enabled', 'kitchen_printer_enabled', 'print_on_hold', 'print_on_pay']);
        });
    }
};
