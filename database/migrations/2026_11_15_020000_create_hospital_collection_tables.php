<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('hospital_invoices', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->uuid('request_key'); $t->string('patient_reference',100); $t->string('service_head',32);
            $t->unsignedBigInteger('total_paisa'); $t->unsignedBigInteger('created_by');
            $t->string('campus_name'); $t->timestamps();
            $t->unique(['company_id','request_key']); $t->index(['company_id','branch_id','created_at']);
        });
        Schema::create('hospital_payments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('invoice_id'); $t->uuid('request_key'); $t->unsignedBigInteger('amount_paisa');
            $t->string('payment_method',16); $t->unsignedBigInteger('balance_paisa');
            $t->unsignedBigInteger('collected_by'); $t->string('cashier_name'); $t->string('campus_name');
            $t->timestamp('collected_at'); $t->timestamps();
            $t->unique(['company_id','request_key']); $t->index(['company_id','branch_id','collected_at']);
            $t->index('invoice_id');
        });
        Schema::create('hospital_day_closes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_key')->default(0);
            $t->date('report_date'); $t->unsignedBigInteger('cash_paisa'); $t->unsignedBigInteger('card_paisa');
            $t->unsignedBigInteger('bank_paisa'); $t->unsignedBigInteger('counted_cash_paisa');
            $t->bigInteger('variance_paisa'); $t->string('variance_note',500)->nullable();
            $t->unsignedInteger('receipt_count'); $t->unsignedBigInteger('closed_by');
            $t->string('cashier_name'); $t->string('campus_name'); $t->timestamp('closed_at'); $t->timestamps();
            $t->unique(['company_id','branch_key','report_date']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('hospital_day_closes'); Schema::dropIfExists('hospital_payments'); Schema::dropIfExists('hospital_invoices');
    }
};
