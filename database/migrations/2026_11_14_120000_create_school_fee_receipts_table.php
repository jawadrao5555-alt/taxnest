<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('school_fee_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('collected_by');
            $table->uuid('request_key');
            $table->string('student_reference', 100);
            $table->string('fee_head', 20);
            $table->unsignedBigInteger('amount_paisa');
            $table->timestamp('collected_at');
            $table->timestamps();
            $table->unique(['company_id', 'request_key']);
            $table->index(['company_id', 'branch_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_fee_receipts');
    }
};
