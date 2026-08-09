<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents/Partners program (Model A: commission agent, payments direct to TaxNest).
 * - agents: agent register (CNIC, territory, Schedule A rates, status)
 * - companies.agent_id: which agent introduced the company (nullable)
 * - agent_commissions: money ledger — earn lines frozen at verification time
 *   plus manual clawback lines (refund/reversal adjustments).
 *
 * Idempotent + per-column hasColumn guards (prod schema drift self-heal).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agents')) {
            Schema::create('agents', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('cnic', 20)->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('email')->nullable();
                $table->string('territory')->nullable();
                $table->decimal('rate_new', 5, 2)->default(0);      // Schedule A: new sale %
                $table->decimal('rate_renewal', 5, 2)->default(0);  // Schedule A: renewal %
                $table->string('status', 20)->default('active');    // active | terminated
                $table->timestamp('terminated_at')->nullable();     // last termination (window start)
                $table->timestamp('reactivated_at')->nullable();    // last reactivation (window end)
                $table->text('termination_windows')->nullable();    // FULL history: JSON [{from,to|null},...]
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'agent_id')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->unsignedBigInteger('agent_id')->nullable()->index()->after('franchise_id');
            });
        }

        if (!Schema::hasTable('agent_commissions')) {
            Schema::create('agent_commissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agent_id')->index();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('company_name')->nullable();          // survives company hard-delete
                $table->unsignedBigInteger('payment_proof_id')->nullable()->index();
                $table->string('type', 20);                          // new | renewal | clawback
                $table->decimal('base_amount', 12, 2)->default(0);   // cleared payment amount
                $table->decimal('rate_percent', 5, 2)->default(0);   // rate frozen at earn time
                $table->decimal('amount', 12, 2)->default(0);        // signed: clawback is negative
                $table->date('period_month');                        // report month (1st of month)
                $table->string('description')->nullable();
                $table->unsignedBigInteger('created_by_admin_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commissions');
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'agent_id')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('agent_id');
            });
        }
        Schema::dropIfExists('agents');
    }
};
