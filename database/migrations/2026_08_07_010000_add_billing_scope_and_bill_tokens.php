<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing Scope + Bill Number Style (owner request 07 Aug 2026, 2-3 companies
 * ki tajweez):
 *
 * 1. users.pos_billing_scope — per staff account stream lock:
 *      NULL/'both' = dono (default, purana behaviour)
 *      'local'     = sirf offline/local billing (PRA pipeline tak rasai nahi)
 *      'pra'       = sirf PRA-reporting billing (local/provisional se door)
 *    Applies to pos_cashier + pos_manager; owner/admin hamesha both.
 *
 * 2. companies.{pra,local}_number_style — per stream receipt numbering display:
 *      'serial' (default) = chalti series (POS-YYYY-NNNNN / L-NNN)
 *      'token'            = roz ka token (1,2,3… business-day 6AM reset)
 *    Dono streams ke alag counters; token pos_transactions.bill_token par
 *    freeze hota hai taake reprint par number kabhi na badle.
 *
 * Idempotent per-column guards (PROD schema drift self-heal convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'pos_billing_scope')) {
                    $table->string('pos_billing_scope', 10)->nullable();
                }
            });
        }

        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                if (!Schema::hasColumn('companies', 'pra_number_style')) {
                    $table->string('pra_number_style', 10)->default('serial');
                }
                if (!Schema::hasColumn('companies', 'local_number_style')) {
                    $table->string('local_number_style', 10)->default('serial');
                }
                if (!Schema::hasColumn('companies', 'bill_token_counter_pra')) {
                    $table->integer('bill_token_counter_pra')->default(0);
                }
                if (!Schema::hasColumn('companies', 'bill_token_date_pra')) {
                    $table->date('bill_token_date_pra')->nullable();
                }
                if (!Schema::hasColumn('companies', 'bill_token_counter_local')) {
                    $table->integer('bill_token_counter_local')->default(0);
                }
                if (!Schema::hasColumn('companies', 'bill_token_date_local')) {
                    $table->date('bill_token_date_local')->nullable();
                }
            });
        }

        if (Schema::hasTable('pos_transactions')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_transactions', 'bill_token')) {
                    $table->integer('bill_token')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally no column drops — self-heal migrations never destroy data.
    }
};
