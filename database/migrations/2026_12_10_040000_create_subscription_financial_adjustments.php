<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_financial_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // Explicit short names are required: the table's descriptive name
            // makes Laravel's generated FK/index identifiers exceed MariaDB's
            // 64-character identifier limit.
            $table->unsignedBigInteger('subscription_invoice_id');
            $table->foreign('subscription_invoice_id', 'sfa_invoice_fk')
                ->references('id')->on('subscription_invoices')->restrictOnDelete();
            $table->unsignedBigInteger('subscription_payment_id')->nullable();
            $table->foreign('subscription_payment_id', 'sfa_payment_fk')
                ->references('id')->on('subscription_payments')->restrictOnDelete();
            $table->unsignedBigInteger('reverses_adjustment_id')->nullable();
            $table->unique('reverses_adjustment_id', 'sfa_reversal_unique');
            $table->foreign('reverses_adjustment_id', 'sfa_reversal_fk')
                ->references('id')->on('subscription_financial_adjustments')->restrictOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('kind', 32);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PKR');
            $table->string('reason', 500);
            $table->string('external_reference')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('occurred_at');
            $table->json('evidence_snapshot');
            $table->timestamps();
            $table->index(['company_id', 'occurred_at'], 'sfa_company_time_index');
            $table->index(['subscription_invoice_id', 'kind'], 'sfa_invoice_kind_index');
            $table->index(['subscription_payment_id', 'kind'], 'sfa_payment_kind_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_financial_adjustments');
    }
};
