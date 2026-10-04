<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('stay_id');
            $table->unsignedBigInteger('original_transaction_id');
            $table->unsignedBigInteger('credit_transaction_id')->unique();
            $table->unsignedBigInteger('created_by');
            $table->string('request_key', 64);
            $table->string('reason', 255);
            $table->json('selection')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'request_key']);
            $table->index(['company_id', 'stay_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('hotel_credit_notes');
    }
};
