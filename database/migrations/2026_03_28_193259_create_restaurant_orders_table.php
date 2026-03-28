<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('order_number', 30)->unique();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type', 20)->default('dine_in');
            $table->string('status', 20)->default('held');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('payment_method', 30)->nullable();
            $table->text('kitchen_notes')->nullable();
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'table_id']);
            $table->index(['company_id', 'created_at']);
            $table->foreign('table_id')->references('id')->on('restaurant_tables')->onDelete('set null');
            $table->foreign('customer_id')->references('id')->on('pos_customers')->onDelete('set null');
            $table->foreign('pos_transaction_id')->references('id')->on('pos_transactions')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_orders');
    }
};
