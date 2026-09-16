<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_bulk_submission_outbox', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'invoice_id']);
            $table->index(['batch_id', 'dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_bulk_submission_outbox');
    }
};