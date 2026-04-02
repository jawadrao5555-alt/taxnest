<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            $table->string('batch_number', 100)->nullable()->after('is_tax_exempt');
            $table->date('expiry_date')->nullable()->after('batch_number');
            $table->string('drug_type', 50)->nullable()->after('expiry_date');
            $table->boolean('prescription_required')->default(false)->after('drug_type');
            $table->boolean('weight_based')->default(false)->after('prescription_required');
            $table->string('unit_type', 20)->nullable()->after('weight_based');
            $table->string('size', 30)->nullable()->after('unit_type');
            $table->string('color', 50)->nullable()->after('size');
            $table->string('season', 30)->nullable()->after('color');
            $table->string('serial_number', 100)->nullable()->after('season');
            $table->integer('warranty_months')->nullable()->after('serial_number');
            $table->string('imei', 20)->nullable()->after('warranty_months');
            $table->integer('bulk_discount_qty')->nullable()->after('imei');
            $table->decimal('bulk_discount_pct', 5, 2)->nullable()->after('bulk_discount_qty');
            $table->integer('service_duration')->nullable()->after('bulk_discount_pct');
            $table->string('staff_assignment', 100)->nullable()->after('service_duration');
            $table->string('vehicle_make', 50)->nullable()->after('staff_assignment');
            $table->string('vehicle_model', 50)->nullable()->after('vehicle_make');
            $table->string('part_number', 100)->nullable()->after('vehicle_model');
            $table->boolean('custom_order')->default(false)->after('part_number');
            $table->string('box_type', 50)->nullable()->after('custom_order');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            $table->dropColumn([
                'batch_number', 'expiry_date', 'drug_type', 'prescription_required',
                'weight_based', 'unit_type', 'size', 'color', 'season',
                'serial_number', 'warranty_months', 'imei',
                'bulk_discount_qty', 'bulk_discount_pct',
                'service_duration', 'staff_assignment',
                'vehicle_make', 'vehicle_model', 'part_number',
                'custom_order', 'box_type',
            ]);
        });
    }
};
