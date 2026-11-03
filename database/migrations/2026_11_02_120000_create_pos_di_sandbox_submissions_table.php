<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_di_sandbox_submissions', function (Blueprint $table) {
            $table->id();
            // No cascade to operational bills: cleanup must not erase evidence.
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('source_transaction_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->longText('snapshot');
            $table->longText('payload')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('state')->default('draft');
            $table->string('sandbox_invoice_number')->nullable();
            $table->longText('attempts')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'source_transaction_id'], 'pos_di_sandbox_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_di_sandbox_submissions');
    }
};
