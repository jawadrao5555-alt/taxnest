<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_upgrade_settlements', function (Blueprint $table) {
            $table->id();
            // Keep the original numeric IDs as financial evidence, including after
            // an account's retention period ends. No cascade alters this ledger.
            $table->unsignedBigInteger('source_company_id')->index();
            $table->unsignedBigInteger('destination_company_id')->index();
            $table->unsignedBigInteger('source_invoice_id')->index();
            $table->unsignedBigInteger('allocated_source_invoice_id')->nullable()->unique('erp_upgrade_one_source_charge');
            $table->unsignedBigInteger('pricing_plan_id');
            $table->string('billing_cycle', 16);
            $table->date('quoted_on');
            $table->unsignedBigInteger('quoted_by');
            $table->string('owner_authorization_reference', 150);
            $table->char('quote_key', 64)->unique();
            $table->json('quote_snapshot');
            $table->unsignedBigInteger('target_price_paisa');
            $table->unsignedBigInteger('credit_paisa');
            $table->unsignedBigInteger('difference_paisa');
            $table->unsignedBigInteger('verified_received_paisa')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->char('payment_reference_key', 64)->nullable()->unique('erp_upgrade_one_payment_reference');
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('destination_subscription_id')->nullable()->unique('erp_upgrade_one_destination_term');
            $table->json('branch_map')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('activation_reference', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_upgrade_settlements');
    }
};
