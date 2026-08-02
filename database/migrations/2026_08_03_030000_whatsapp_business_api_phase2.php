<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Business API (Phase 2) — server-side "seedha bhejein" delivery.
 *
 *  - companies: per-company Meta Cloud API credentials. Token is
 *    Crypt::encryptString'd → MUST be TEXT (encrypted payload overflows
 *    varchar(255) even for tiny plaintext).
 *  - invoice_deliveries: provider_message_id (Meta wamid) so status webhooks
 *    (sent/delivered/read/failed) can find and update the delivery row.
 *
 * Idempotent per-column hasColumn guards — safe on drifted prod schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                if (!Schema::hasColumn('companies', 'wa_api_enabled')) {
                    $table->boolean('wa_api_enabled')->default(false);
                }
                if (!Schema::hasColumn('companies', 'wa_phone_number_id')) {
                    $table->string('wa_phone_number_id', 100)->nullable();
                }
                if (!Schema::hasColumn('companies', 'wa_api_token')) {
                    $table->text('wa_api_token')->nullable(); // encrypted — TEXT mandatory
                }
                if (!Schema::hasColumn('companies', 'wa_template_name')) {
                    $table->string('wa_template_name', 100)->nullable();
                }
                if (!Schema::hasColumn('companies', 'wa_attach_pdf')) {
                    $table->boolean('wa_attach_pdf')->default(true);
                }
                if (!Schema::hasColumn('companies', 'wa_webhook_verify_token')) {
                    $table->string('wa_webhook_verify_token', 100)->nullable();
                }
            });
        }

        if (Schema::hasTable('invoice_deliveries')) {
            Schema::table('invoice_deliveries', function (Blueprint $table) {
                if (!Schema::hasColumn('invoice_deliveries', 'provider_message_id')) {
                    $table->string('provider_message_id', 191)->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive (matches other ensure-column migrations).
    }
};
