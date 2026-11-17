<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('school_fee_receipts')) {
            return;
        }

        $columns = [
            'fbr_pos_transaction_id' => fn (Blueprint $table) => $table->unsignedBigInteger('fbr_pos_transaction_id')->nullable(),
            'fbr_status' => fn (Blueprint $table) => $table->string('fbr_status', 32)->nullable(),
            'fbr_invoice_number' => fn (Blueprint $table) => $table->string('fbr_invoice_number', 100)->nullable(),
            'fbr_response_code' => fn (Blueprint $table) => $table->string('fbr_response_code', 250)->nullable(),
            'fbr_error_message' => fn (Blueprint $table) => $table->text('fbr_error_message')->nullable(),
            'fbr_qr_payload' => fn (Blueprint $table) => $table->string('fbr_qr_payload', 255)->nullable(),
        ];

        foreach ($columns as $column => $add) {
            if (!Schema::hasColumn('school_fee_receipts', $column)) {
                Schema::table('school_fee_receipts', $add);
            }
        }

        if (!Schema::hasIndex('school_fee_receipts', 'school_fee_receipts_fbr_pos_transaction_id_index')) {
            Schema::table('school_fee_receipts', fn (Blueprint $table) => $table->index('fbr_pos_transaction_id'));
        }
    }

    public function down(): void
    {
        // Fiscal identity and callback evidence are deliberately retained on rollback.
        // Older application code safely ignores these additive nullable columns.
    }
};
