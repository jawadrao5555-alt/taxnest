<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('school_fee_day_closes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            // Zero denotes no campus. A non-null scope makes the uniqueness
            // constraint effective on MySQL as well as SQLite.
            $table->unsignedBigInteger('branch_key')->default(0);
            $table->date('report_date');
            $table->unsignedBigInteger('cash_amount_paisa');
            $table->unsignedBigInteger('receipt_count');
            $table->unsignedBigInteger('closed_by');
            $table->timestamp('closed_at');
            $table->timestamps();
            // Also serves history/date lookups within the current campus.
            $table->unique(['company_id', 'branch_key', 'report_date'], 'school_fee_day_closes_scope_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_fee_day_closes');
    }
};
