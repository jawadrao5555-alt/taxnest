<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fbr_pos_submission_evidence')) {
            return;
        }

        Schema::create('fbr_pos_submission_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('transaction_id')->unique();
            $table->string('channel', 32)->default('local_fiscal_device');
            $table->string('endpoint_class', 64)->default('local_fbr_ims');
            $table->string('requested_environment', 20)->nullable();
            // TaxNest cannot infer this from its own environment dropdown. It
            // stays unknown until the installed shop-PC IMS supplies proof.
            $table->string('local_environment_proof', 20)->default('unknown');
            $table->char('pos_id_fingerprint', 64)->nullable();
            $table->string('pos_id_mask', 16)->nullable();
            $table->string('agent_version', 40)->nullable();
            $table->unsignedInteger('dispatch_count')->default(0);
            $table->timestamp('first_dispatched_at')->nullable();
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamp('callback_at')->nullable();
            $table->string('response_code', 40)->nullable();
            $table->char('response_hash', 64)->nullable();
            $table->char('fiscal_number_fingerprint', 64)->nullable();
            $table->string('fiscal_number_mask', 20)->nullable();
            $table->string('result_state', 32)->default('dispatched');
            // A local Code 100 is not a central FBR/Tax Asaan receipt.
            $table->string('central_verification_state', 20)->default('unknown');
            $table->timestamp('central_verified_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'last_dispatched_at'], 'fbr_evidence_company_dispatch_idx');
            $table->index(['company_id', 'result_state'], 'fbr_evidence_company_result_idx');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('transaction_id')->references('id')->on('fbr_pos_transactions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fbr_pos_submission_evidence');
    }
};
