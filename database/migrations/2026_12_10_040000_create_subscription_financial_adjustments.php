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
            $table->foreignId('subscription_invoice_id')->constrained('subscription_invoices')->restrictOnDelete();
            $table->foreignId('subscription_payment_id')->nullable()->constrained('subscription_payments')->restrictOnDelete();
            $table->foreignId('reverses_adjustment_id')->nullable()->unique()
                ->constrained('subscription_financial_adjustments')->restrictOnDelete();
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
            $table->index(['company_id', 'occurred_at']);
            $table->index(['subscription_invoice_id', 'kind']);
            $table->index(['subscription_payment_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_financial_adjustments');
    }
};
