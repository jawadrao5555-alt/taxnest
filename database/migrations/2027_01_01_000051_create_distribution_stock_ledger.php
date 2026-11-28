<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'code']);
        });

        Schema::create('distribution_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('name', 120);
            $table->string('base_unit', 24);
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'sku']);
        });

        Schema::create('distribution_item_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('item_id');
            $table->string('code', 24);
            $table->unsignedInteger('base_units_per_unit');
            $table->unique(['company_id', 'item_id', 'id'], 'dist_unit_item_identity');
            $table->unique(['company_id', 'item_id', 'code'], 'dist_unit_item_code');
            $table->foreign(['company_id', 'item_id'], 'dist_unit_item_fk')
                ->references(['company_id', 'id'])->on('distribution_items')->restrictOnDelete();
        });

        Schema::create('distribution_stock_balances', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('quantity_base_units');
            $table->primary(['company_id', 'warehouse_id', 'item_id'], 'dist_balance_identity');
            $table->foreign(['company_id', 'warehouse_id'], 'dist_balance_warehouse_fk')
                ->references(['company_id', 'id'])->on('distribution_warehouses')->restrictOnDelete();
            $table->foreign(['company_id', 'item_id'], 'dist_balance_item_fk')
                ->references(['company_id', 'id'])->on('distribution_items')->restrictOnDelete();
        });

        Schema::create('distribution_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('item_unit_id');
            $table->uuid('request_key');
            $table->string('quantity', 20);
            $table->string('unit_code', 24);
            $table->unsignedInteger('base_units_per_unit');
            $table->bigInteger('delta_base_units');
            $table->unsignedBigInteger('balance_after_base_units');
            $table->unsignedBigInteger('recorded_by');
            $table->timestamp('created_at');
            $table->unique(['company_id', 'request_key'], 'dist_movement_request');
            $table->index(['company_id', 'warehouse_id', 'item_id', 'id'], 'dist_movement_history');
            $table->foreign(['company_id', 'warehouse_id'], 'dist_movement_warehouse_fk')
                ->references(['company_id', 'id'])->on('distribution_warehouses')->restrictOnDelete();
            $table->foreign(['company_id', 'item_id', 'item_unit_id'], 'dist_movement_unit_fk')
                ->references(['company_id', 'item_id', 'id'])->on('distribution_item_units')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_stock_movements');
        Schema::dropIfExists('distribution_stock_balances');
        Schema::dropIfExists('distribution_item_units');
        Schema::dropIfExists('distribution_items');
        Schema::dropIfExists('distribution_warehouses');
    }
};
