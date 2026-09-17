<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('restaurant_order_edit_attempts')) {
            Schema::create('restaurant_order_edit_attempts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('order_id');
                $table->string('edit_uuid', 64);
                $table->unsignedInteger('revision');
                $table->string('kot_status', 32)->default('pending');
                $table->json('add_payload')->nullable();
                $table->json('void_payload')->nullable();
                $table->text('kot_error')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'order_id', 'edit_uuid'], 'roea_company_order_uuid_unique');
                $table->index(['company_id', 'order_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_order_edit_attempts');
    }
};