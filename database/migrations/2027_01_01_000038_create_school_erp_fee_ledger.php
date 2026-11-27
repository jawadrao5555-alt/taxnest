<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_erp_fee_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('term_id');
            $table->string('label', 100);
            $table->unsignedBigInteger('amount_paisa');
            $table->date('due_on');
            $table->timestamps();
            $table->unique(['company_id', 'term_id', 'label'], 'se_fee_schedule_label');
            $table->unique(['company_id', 'id'], 'se_fee_schedule_scope');
            $table->foreign(['company_id', 'term_id'], 'se_fee_schedule_term')
                ->references(['company_id', 'id'])->on('school_erp_terms')->restrictOnDelete();
        });

        Schema::create('school_erp_fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('amount_paisa');
            $table->unsignedBigInteger('issued_by');
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'se_fee_invoice_scope');
            $table->unique(['company_id', 'student_id', 'schedule_id'], 'se_fee_invoice_identity');
            $table->index(['company_id', 'campus_id'], 'se_fee_invoice_campus_idx');
            $table->foreign(['company_id', 'student_id'], 'se_fee_invoice_student')
                ->references(['company_id', 'id'])->on('school_erp_students')->restrictOnDelete();
            $table->foreign(['company_id', 'schedule_id'], 'se_fee_invoice_schedule')
                ->references(['company_id', 'id'])->on('school_erp_fee_schedules')->restrictOnDelete();
            $table->foreign(['company_id', 'campus_id'], 'se_fee_invoice_campus')
                ->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('school_erp_fee_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('invoice_id');
            $table->uuid('request_key');
            $table->unsignedBigInteger('amount_paisa');
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->unsignedBigInteger('received_by');
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'se_fee_payment_scope');
            $table->unique(['company_id', 'request_key'], 'se_fee_payment_retry');
            $table->foreign(['company_id', 'invoice_id'], 'se_fee_payment_invoice')
                ->references(['company_id', 'id'])->on('school_erp_fee_invoices')->restrictOnDelete();
        });

        Schema::create('school_erp_fee_reversals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('reversed_by');
            $table->string('reason', 200);
            $table->timestamps();
            $table->unique(['company_id', 'payment_id'], 'se_fee_reversal_identity');
            $table->foreign(['company_id', 'payment_id'], 'se_fee_reversal_payment')
                ->references(['company_id', 'id'])->on('school_erp_fee_payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_erp_fee_reversals');
        Schema::dropIfExists('school_erp_fee_payments');
        Schema::dropIfExists('school_erp_fee_invoices');
        Schema::dropIfExists('school_erp_fee_schedules');
    }
};
