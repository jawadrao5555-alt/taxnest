<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS optional add-ons and their payment-proof metadata.
 *
 * Add-ons are separate from the subscription row: approval activates only the
 * selected features until the current package expires. No foreign keys are
 * used, matching the existing payment-proof schema drift policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_addons')) {
            Schema::create('pos_addons', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('addon_code', 50);
                $table->boolean('active')->default(true)->index();
                $table->string('billing_cycle', 20)->default('annual');
                $table->decimal('amount', 12, 2)->default(0);
                $table->date('starts_at')->nullable();
                $table->date('ends_at')->nullable()->index();
                $table->unsignedBigInteger('payment_proof_id')->nullable();
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'addon_code']);
            });
        }

        if (Schema::hasTable('payment_proofs')) {
            if (!Schema::hasColumn('payment_proofs', 'addon_codes')) {
                Schema::table('payment_proofs', function (Blueprint $table) {
                    $table->text('addon_codes')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Add-on entitlement is billing history; never remove it automatically.
    }
};