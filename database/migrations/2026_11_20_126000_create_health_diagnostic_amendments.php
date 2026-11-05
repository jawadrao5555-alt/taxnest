<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('health_diagnostic_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_diagnostic_order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->uuid('request_key');
            $table->text('result');
            $table->text('reason');
            $table->unsignedBigInteger('signed_by');
            $table->timestamp('signed_at');
            $table->unique(['health_diagnostic_order_id', 'revision'], 'health_diagnostic_revision');
            $table->unique(['health_diagnostic_order_id', 'request_key'], 'health_diagnostic_amendment_retry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_diagnostic_amendments');
    }
};
