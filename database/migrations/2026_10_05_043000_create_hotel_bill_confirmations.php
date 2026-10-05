<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotel_bill_confirmations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('stay_id');
            $table->string('request_key', 64);
            $table->string('fingerprint', 64);
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'request_key'], 'hotel_bill_confirm_request');
        });
    }
    public function down(): void { Schema::dropIfExists('hotel_bill_confirmations'); }
};
