<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Business (Cloud) API — Phase 2 of invoice send-to-buyer.
 *
 * Sends the buyer an APPROVED template message (business-initiated chats must
 * use templates) with the invoice number / company / amount / share link as
 * body params, optionally with the public B/W invoice PDF as the template's
 * document header. Buyer-facing template content stays ENGLISH (owner rule).
 *
 * Expected default template ("invoice_notification", language en):
 *   Header: DOCUMENT (only when "PDF attach" is on)
 *   Body:   "Invoice {{1}} from {{2}}. Amount: PKR {{3}}. View or download: {{4}}"
 *
 * Credentials are per-company (each business brings its own Meta Business
 * account + verified number + per-message billing). Never throws to the
 * caller — returns ['ok' => false, 'error' => ...] so the controller logs the
 * failed delivery explicitly (no silent fallback to wa.me).
 */
class WhatsAppBusinessApi
{
    public const GRAPH_BASE = 'https://graph.facebook.com/v21.0';
    public const DEFAULT_TEMPLATE = 'invoice_notification';

    /** Direct sending is available only when enabled AND fully configured. */
    public static function configuredFor(?Company $company): bool
    {
        return $company
            && $company->wa_api_enabled
            && !empty($company->wa_phone_number_id)
            && !empty($company->wa_api_token);
    }

    /**
     * @param string $toDigits normalized international digits (PkPhone::normalize)
     * @return array{ok: bool, message_id?: string, error?: string}
     */
    public static function sendInvoice(Company $company, string $toDigits, Invoice $invoice, string $shareUrl): array
    {
        if (!self::configuredFor($company)) {
            return ['ok' => false, 'error' => 'WhatsApp Business API is not configured for this company.'];
        }

        try {
            $token = Crypt::decryptString($company->wa_api_token);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Stored API token could not be decrypted — save it again in WhatsApp Settings.'];
        }

        $template = trim((string) $company->wa_template_name) ?: self::DEFAULT_TEMPLATE;

        $bodyParams = [
            ['type' => 'text', 'text' => (string) ($invoice->display_invoice_number ?? $invoice->invoice_number ?? ('#' . $invoice->id))],
            ['type' => 'text', 'text' => (string) ($company->name ?? 'our company')],
            ['type' => 'text', 'text' => number_format((float) ($invoice->total_amount ?? 0), 2)],
            ['type' => 'text', 'text' => $shareUrl],
        ];

        $components = [];
        if ($company->wa_attach_pdf) {
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => 'document',
                    'document' => [
                        'link' => rtrim($shareUrl, '/') . '/pdf',
                        'filename' => 'Invoice-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($invoice->display_invoice_number ?? $invoice->id)) . '.pdf',
                    ],
                ]],
            ];
        }
        $components[] = ['type' => 'body', 'parameters' => $bodyParams];

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $toDigits,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => 'en'],
                'components' => $components,
            ],
        ];

        try {
            $res = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->post(self::GRAPH_BASE . '/' . $company->wa_phone_number_id . '/messages', $payload);
        } catch (\Throwable $e) {
            Log::warning("WA Business API network failure (company {$company->id}, invoice {$invoice->id}): " . $e->getMessage());
            return ['ok' => false, 'error' => 'Network error reaching WhatsApp API: ' . $e->getMessage()];
        }

        $json = $res->json();
        if ($res->successful() && !empty($json['messages'][0]['id'])) {
            return ['ok' => true, 'message_id' => (string) $json['messages'][0]['id']];
        }

        $error = $json['error']['message'] ?? ('HTTP ' . $res->status());
        if (!empty($json['error']['error_data']['details'])) {
            $error .= ' — ' . $json['error']['error_data']['details'];
        }
        Log::warning("WA Business API send failed (company {$company->id}, invoice {$invoice->id}): {$error}");

        return ['ok' => false, 'error' => $error];
    }
}
