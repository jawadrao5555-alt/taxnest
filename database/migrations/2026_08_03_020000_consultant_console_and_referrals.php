<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax Consultant Console + affiliate program (Task: consultant console).
 *
 * - consultant_profiles:      a user opts in as consultant; carries referral code + commission rate.
 * - consultant_client_links:  consent-based link between a consultant USER and a client COMPANY.
 *                             One row per pair; status transitions pending → active → revoked.
 * - consultant_invites:       client-generated single-use invite codes (client-side consent).
 * - consultant_commissions:   money ledger — one row per admin-recorded payment of a referred
 *                             company. Survives company/user deletion (nullable FKs + name snapshot).
 * - companies:                referral attribution set once at signup (referred_by_user_id).
 *
 * Idempotent guards (hasTable/hasColumn) — safe to re-run on prod where partial
 * schema drift has happened before (see prod-schema-drift-selfheal memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('consultant_profiles')) {
            Schema::create('consultant_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('referral_code', 20)->unique();
                $table->string('status', 20)->default('active'); // active | disabled
                $table->decimal('commission_rate', 5, 2)->default(10.00);
                $table->string('payout_notes', 500)->nullable(); // admin free text (bank/JazzCash etc.)
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('consultant_client_links')) {
            Schema::create('consultant_client_links', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('consultant_user_id');
                $table->unsignedBigInteger('company_id');
                $table->string('status', 20)->default('pending'); // pending | active | revoked
                $table->string('initiated_by', 20)->nullable();   // consultant | client
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->string('revoked_by', 20)->nullable();     // client | consultant | admin
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->unique(['consultant_user_id', 'company_id']);
                $table->index(['company_id', 'status']);
                $table->foreign('consultant_user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('consultant_invites')) {
            Schema::create('consultant_invites', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('code', 20)->unique();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->unsignedBigInteger('used_by_user_id')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index('company_id');
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('consultant_commissions')) {
            Schema::create('consultant_commissions', function (Blueprint $table) {
                $table->id();
                // Money ledger: keep rows even if the consultant user or client
                // company is later deleted — nullable + nullOnDelete, plus a
                // company_name snapshot so history stays readable.
                $table->unsignedBigInteger('consultant_user_id')->nullable();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('company_name')->nullable();
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->string('description')->nullable();
                $table->decimal('base_amount', 12, 2)->default(0);
                $table->decimal('rate_percent', 5, 2)->default(0);
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('status', 20)->default('pending'); // pending | paid
                $table->string('source', 30)->default('subscription');
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('paid_by_admin_id')->nullable();
                $table->string('payout_reference')->nullable();
                $table->timestamps();

                $table->index(['consultant_user_id', 'status']);
                $table->index('subscription_id');
                $table->foreign('consultant_user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('companies', 'referred_by_user_id')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->unsignedBigInteger('referred_by_user_id')->nullable()->index();
            });
        }
        if (!Schema::hasColumn('companies', 'referral_code_used')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('referral_code_used', 30)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_commissions');
        Schema::dropIfExists('consultant_invites');
        Schema::dropIfExists('consultant_client_links');
        Schema::dropIfExists('consultant_profiles');
        if (Schema::hasColumn('companies', 'referred_by_user_id')) {
            Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('referred_by_user_id'));
        }
        if (Schema::hasColumn('companies', 'referral_code_used')) {
            Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('referral_code_used'));
        }
    }
};
