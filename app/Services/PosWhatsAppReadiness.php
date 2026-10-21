<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

final class PosWhatsAppReadiness
{
    /** Local saved configuration only; never contacts Meta or claims template/delivery verification. */
    public static function inspect(Company $company): array
    {
        $token = false;
        $secret = false;
        try {
            $token = trim(Crypt::decryptString((string) $company->wa_api_token)) !== '';
        } catch (\Throwable) {
            // No raw credentials or exception payload enter the view/session/log.
        }
        try {
            $secret = trim((string) $company->wa_app_secret) !== '';
        } catch (\Throwable) {
        }
        $checks = [
            'Package mein WhatsApp Bill' => PosFeatureService::planAllows($company, 'whatsapp_enabled'),
            'WhatsApp Bill enabled' => (bool) $company->pos_whatsapp_bill_enabled,
            'Business API enabled' => (bool) $company->wa_api_enabled,
            'Phone Number ID saved' => preg_match('/^[0-9]+$/D', (string) $company->wa_phone_number_id) === 1,
            'Saved Access Token readable' => $token,
            'Saved App Secret readable' => $secret,
            'Delivery storage installed' => Schema::hasTable('pos_whatsapp_outbox') && Schema::hasColumn('companies', 'pos_whatsapp_auto_send'),
        ];

        return ['checks' => $checks, 'setup_ready' => !in_array(false, $checks, true),
            'webhook_verify_token_saved' => trim((string) $company->wa_webhook_verify_token) !== ''];
    }
}
