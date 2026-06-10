<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment proof submissions (manual bank-transfer verification flow).
 *
 * A locked / trial-ended company uploads a payment receipt; an admin reviews
 * it and, on approval, a subscription is assigned and the company unlocks.
 * Idempotent (hasTable guard) so it is safe to re-run on a drifted schema.
 * No FK constraints by design — keeps the self-heal philosophy and avoids
 * migrate failures on production databases with partial data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_proofs')) {
            Schema::create('payment_proofs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->decimal('amount', 12, 2)->nullable();
                $table->string('reference')->nullable();
                $table->date('payment_date')->nullable();
                $table->string('proof_path');
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedBigInteger('pricing_plan_id')->nullable();
                $table->string('billing_cycle', 20)->nullable();
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->string('reject_reason')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
    }
};
