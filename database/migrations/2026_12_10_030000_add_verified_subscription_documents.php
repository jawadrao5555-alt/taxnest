<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            // The existing invoice/payment tables are also the immutable billing
            // document ledger for add-ons. Some top-ups legitimately have no
            // subscription, so the attribution must be company + payment proof.
            $table->unsignedBigInteger('subscription_id')->nullable()->change();
            $table->unsignedBigInteger('payment_proof_id')->nullable()->unique();
            $table->string('document_number', 64)->nullable()->unique();
            $table->json('document_snapshot')->nullable();
        });
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->string('receipt_number', 64)->nullable()->unique();
            $table->json('receipt_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', fn (Blueprint $table) => $table->dropColumn(['receipt_number', 'receipt_snapshot']));
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_proof_id', 'document_number', 'document_snapshot']);
            $table->unsignedBigInteger('subscription_id')->nullable(false)->change();
        });
    }
};
