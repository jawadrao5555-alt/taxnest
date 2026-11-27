<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['health_lab_tests', 'health_lab_order_items'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'unit_cost_paisa')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedBigInteger('unit_cost_paisa')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['health_lab_order_items', 'health_lab_tests'] as $tableName) {
            if (Schema::hasColumn($tableName, 'unit_cost_paisa')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('unit_cost_paisa');
                });
            }
        }
    }
};
