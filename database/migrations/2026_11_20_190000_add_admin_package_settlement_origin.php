<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->string('admin_settlement_origin', 24)->nullable();
            $table->unsignedBigInteger('admin_settlement_invoice_id')->nullable();
            $table->uuid('admin_settlement_key')->nullable();
            $table->char('admin_settlement_fingerprint', 64)->nullable();
            $table->unique(['company_id', 'admin_settlement_key'], 'proof_company_admin_settlement_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->dropUnique('proof_company_admin_settlement_unique');
            $table->dropColumn(['admin_settlement_origin', 'admin_settlement_invoice_id', 'admin_settlement_key', 'admin_settlement_fingerprint']);
        });
    }
};
