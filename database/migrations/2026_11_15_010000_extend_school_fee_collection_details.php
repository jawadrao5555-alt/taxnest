<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('school_fee_heads', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('code', 20);
            $table->string('label', 80); $table->boolean('active')->default(true); $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
        Schema::table('school_fee_receipts', function (Blueprint $table) {
            $table->string('fee_period', 7)->nullable(); $table->string('fee_head_label', 80)->nullable();
            $table->string('campus_name')->nullable(); $table->string('cashier_name')->nullable();
            $table->index(['company_id', 'branch_id', 'fee_period'], 'school_receipts_period_idx');
        });
        Schema::table('school_fee_day_closes', function (Blueprint $table) {
            $table->unsignedBigInteger('counted_cash_paisa')->nullable(); $table->bigInteger('variance_paisa')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('school_fee_day_closes', fn (Blueprint $table) => $table->dropColumn(['counted_cash_paisa', 'variance_paisa']));
        Schema::table('school_fee_receipts', function (Blueprint $table) {
            $table->dropIndex('school_receipts_period_idx');
            $table->dropColumn(['fee_period', 'fee_head_label', 'campus_name', 'cashier_name']);
        });
        Schema::dropIfExists('school_fee_heads');
    }
};
