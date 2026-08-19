<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1231: DI invoice push API for third-party software (DMS/ERP vendors).
 *
 * companies:
 *   - di_api_key_hash        SHA-256 hex of the full API key (shown once, only hash stored)
 *   - di_api_key_hint        display hint (prefix…last4) so the admin can recognise the key
 *   - di_api_key_created_at  when the current key was generated
 *   - di_api_key_last_used_at last successful authenticated API call (updated max 1/min)
 *
 * invoices:
 *   - source            'api' when pushed via the DI invoice API (NULL = panel/import)
 *   - client_reference  caller's idempotency key; unique per company so a retry
 *                       can never create a duplicate invoice
 *
 * Idempotent + per-column guarded (PROD schema-drift self-heal pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                if (!Schema::hasColumn('companies', 'di_api_key_hash')) {
                    $table->string('di_api_key_hash', 64)->nullable();
                }
                if (!Schema::hasColumn('companies', 'di_api_key_hint')) {
                    $table->string('di_api_key_hint', 40)->nullable();
                }
                if (!Schema::hasColumn('companies', 'di_api_key_created_at')) {
                    $table->timestamp('di_api_key_created_at')->nullable();
                }
                if (!Schema::hasColumn('companies', 'di_api_key_last_used_at')) {
                    $table->timestamp('di_api_key_last_used_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('invoices', 'source')) {
                    $table->string('source', 20)->nullable();
                }
                if (!Schema::hasColumn('invoices', 'client_reference')) {
                    $table->string('client_reference', 100)->nullable();
                }
            });

            // Unique per company — the DB-level idempotency guard. NULLs are
            // exempt from MySQL unique indexes, so panel invoices (NULL ref)
            // are unaffected. try/catch: index may already exist on re-run.
            try {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->unique(['company_id', 'client_reference'], 'invoices_company_client_ref_unique');
                });
            } catch (\Throwable $e) {
                // already exists — idempotent re-run
            }
        }
    }

    public function down(): void
    {
        // Intentionally left blank — additive, guarded migration (never destructive on PROD).
    }
};
