<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('health_lab_tests')) {
            Schema::create('health_lab_tests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('code', 32);
                $table->string('name', 120);
                $table->unsignedBigInteger('price_paisa');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['company_id', 'code']);
                $table->index(['company_id', 'is_active']);
            });
        }

        if (!Schema::hasTable('health_lab_orders')) {
            Schema::create('health_lab_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();
                $table->unsignedBigInteger('health_patient_id');
                $table->string('order_no', 30);
                $table->uuid('request_key');
                $table->char('request_fingerprint', 64);
                $table->unsignedBigInteger('ordered_by');
                $table->timestamps();
                $table->unique(['company_id', 'order_no']);
                $table->unique(['company_id', 'request_key']);
                $table->index(['company_id', 'branch_id', 'id'], 'health_lab_orders_branch');
                $table->index(['company_id', 'health_patient_id'], 'health_lab_orders_patient');
            });
        }

        if (!Schema::hasTable('health_lab_order_items')) {
            Schema::create('health_lab_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_lab_order_id');
                $table->unsignedBigInteger('health_lab_test_id');
                $table->string('test_code', 32);
                $table->string('test_name', 120);
                $table->unsignedBigInteger('price_paisa');
                $table->timestamps();
                $table->unique(['health_lab_order_id', 'health_lab_test_id'], 'health_lab_order_test');
                $table->index(['company_id', 'health_lab_order_id'], 'health_lab_items_order');
            });
        }

        if (!Schema::hasTable('health_lab_specimens')) {
            Schema::create('health_lab_specimens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_lab_order_item_id');
                $table->unsignedInteger('sequence');
                $table->string('barcode', 30);
                $table->string('status', 16)->default('pending');
                $table->unsignedBigInteger('collected_by')->nullable();
                $table->timestamp('collected_at')->nullable();
                $table->unsignedBigInteger('rejected_by')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->string('rejection_reason', 300)->nullable();
                $table->timestamps();
                $table->unique(['health_lab_order_item_id', 'sequence'], 'health_lab_specimen_attempt');
                $table->unique(['company_id', 'barcode'], 'health_lab_specimen_barcode');
            });
        }

        if (!Schema::hasTable('health_lab_results')) {
            Schema::create('health_lab_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_lab_order_item_id');
                $table->unsignedBigInteger('health_lab_specimen_id');
                $table->unsignedInteger('revision');
                $table->unsignedInteger('version')->default(1);
                $table->text('value');
                $table->string('unit', 50)->nullable();
                $table->string('reference', 140)->nullable();
                $table->string('amendment_reason', 300)->nullable();
                $table->unsignedBigInteger('entered_by');
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->string('verifier_name', 150)->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
                $table->unique(['health_lab_order_item_id', 'revision'], 'health_lab_result_revision');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_lab_results');
        Schema::dropIfExists('health_lab_specimens');
        Schema::dropIfExists('health_lab_order_items');
        Schema::dropIfExists('health_lab_orders');
        Schema::dropIfExists('health_lab_tests');
    }
};
