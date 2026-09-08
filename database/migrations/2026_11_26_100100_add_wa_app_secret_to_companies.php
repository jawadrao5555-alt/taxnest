<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp webhook signature verification (security remediation, Sep 2026).
 *
 * Meta signs every webhook POST with the App Secret
 * (X-Hub-Signature-256: sha256=HMAC_SHA256(app_secret, raw body)). The
 * per-company secret lives here, Eloquent-encrypted ('encrypted' cast) —
 * TEXT because the encrypted payload overflows varchar(255).
 *
 * NULL = not configured → WhatsAppWebhookController::receive() keeps the
 * legacy accept-all behaviour (logged as unsigned). Once the owner saves the
 * secret in WhatsApp Settings, unsigned / mis-signed POSTs are refused.
 *
 * Idempotent + column-guarded (prod schema drift convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'wa_app_secret')) {
            Schema::table('companies', function (Blueprint $table) {
                $col = $table->text('wa_app_secret')->nullable();
                if (Schema::hasColumn('companies', 'wa_webhook_verify_token')) {
                    $col->after('wa_webhook_verify_token');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'wa_app_secret')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('wa_app_secret');
            });
        }
    }
};
