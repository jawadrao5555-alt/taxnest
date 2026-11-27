<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_lab_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('health_visit_id')->nullable();
            $table->index(['company_id', 'health_visit_id'], 'health_lab_orders_visit');
        });
    }

    public function down(): void
    {
        Schema::table('health_lab_orders', function (Blueprint $table) {
            $table->dropIndex('health_lab_orders_visit');
            $table->dropColumn('health_visit_id');
        });
    }
};
