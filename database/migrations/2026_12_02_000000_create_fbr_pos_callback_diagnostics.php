<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fbr_pos_callback_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('fbr_pos_transactions')->cascadeOnDelete();
            $table->string('source', 40)->default('fiscal_device_agent');
            $table->string('agent_version', 32)->nullable();
            $table->string('ims_version', 64)->nullable();
            $table->string('requested_environment', 20)->nullable();
            $table->string('client_environment', 20)->nullable();
            $table->timestamp('callback_received_at');
            $table->boolean('success')->default(false);
            $table->boolean('offline')->nullable();
            $table->string('response_code', 64)->nullable();
            $table->string('invoice_number_field', 64)->nullable();
            $table->string('central_sync_status', 80)->nullable();
            $table->string('central_reference', 160)->nullable();
            $table->json('response_diagnostics')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Keep explicit names below MySQL/MariaDB's 64-character
            // identifier limit. Laravel's generated name for the company
            // lookup is 66 characters and makes a fresh MariaDB migration
            // fail after the table definition has been assembled.
            $table->index(['company_id', 'callback_received_at'], 'fbr_cbdiag_company_received_idx');
            $table->index(['transaction_id', 'callback_received_at'], 'fbr_cbdiag_tx_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fbr_pos_callback_diagnostics');
    }
};
