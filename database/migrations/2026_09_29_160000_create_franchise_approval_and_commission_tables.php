<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_proofs') && !Schema::hasColumn('payment_proofs', 'franchise_id_at_verification')) {
            Schema::table('payment_proofs', function (Blueprint $table) {
                $table->unsignedBigInteger('franchise_id_at_verification')->nullable();
                $table->decimal('franchise_rate_at_verification', 5, 2)->nullable();
                $table->boolean('franchise_attribution_conflict')->default(false);
            });
        }
        if (!Schema::hasTable('franchise_company_approvals')) {
            Schema::create('franchise_company_approvals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->unsignedBigInteger('franchise_id')->index();
                $table->timestamp('approved_at');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('franchise_commissions')) {
            Schema::create('franchise_commissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('franchise_id')->index();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('company_name');
                $table->unsignedBigInteger('payment_proof_id')->nullable()->unique();
                $table->unsignedBigInteger('source_commission_id')->nullable()->index();
                $table->string('type', 20)->default('earned');
                $table->decimal('base_amount', 12, 2);
                $table->decimal('rate_percent', 5, 2);
                $table->decimal('amount', 12, 2);
                $table->string('status', 30)->default('pending');
                $table->timestamp('earned_at');
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('paid_by_admin_id')->nullable();
                $table->string('payout_reference')->nullable();
                $table->unsignedBigInteger('payout_id')->nullable()->index();
                $table->string('adjustment_reason')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('franchise_payouts')) {
            Schema::create('franchise_payouts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('franchise_id')->index();
                $table->decimal('amount', 12, 2);
                $table->string('reference');
                $table->unsignedBigInteger('paid_by_admin_id');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_commissions');
        Schema::dropIfExists('franchise_payouts');
        Schema::dropIfExists('franchise_company_approvals');
        if (Schema::hasTable('payment_proofs') && Schema::hasColumn('payment_proofs', 'franchise_id_at_verification')) {
            Schema::table('payment_proofs', function (Blueprint $table) {
                $table->dropColumn(['franchise_id_at_verification', 'franchise_rate_at_verification', 'franchise_attribution_conflict']);
            });
        }
    }
};
