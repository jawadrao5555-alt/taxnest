<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosTransaction;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/** Typed POS adapter; DI Invoice records and their PDF routes stay unchanged. */
class PosWhatsAppSender
{
    public function send(Company $company, PosTransaction $bill, string $recipient, string $receiptUrl): array
    {
        try {
            $token = Crypt::decryptString($company->wa_api_token);
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'error' => 'Stored WhatsApp token could not be decrypted.'];
        }
        $components = [];
        if ($company->wa_attach_pdf) {
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => [
                // This route already returns the actual POS PDF; no DI /pdf suffix.
                'link' => $receiptUrl,
                'filename' => 'Bill-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $bill->invoice_number) . '.pdf',
            ]]]];
        }
        $components[] = ['type' => 'body', 'parameters' => array_map(
            fn ($text) => ['type' => 'text', 'text' => (string) $text],
            [$bill->invoice_number, $company->name, number_format((float) $bill->total_amount, 2), $receiptUrl]
        )];
        try {
            // No automatic HTTP retry: a lost response can hide an accepted send.
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)
                ->post(WhatsAppBusinessApi::GRAPH_BASE . '/' . $company->wa_phone_number_id . '/messages', [
                    'messaging_product' => 'whatsapp', 'to' => $recipient, 'type' => 'template',
                    'template' => ['name' => trim((string) $company->wa_template_name) ?: WhatsAppBusinessApi::DEFAULT_TEMPLATE,
                        'language' => ['code' => 'en'], 'components' => $components],
                ]);
        } catch (\Throwable $e) {
            return ['status' => 'unknown', 'error' => 'Provider response unavailable; verify delivery before resending.'];
        }
        $wamid = $response->json('messages.0.id');
        if ($response->successful() && is_string($wamid) && $wamid !== '') {
            return ['status' => 'sent', 'provider_message_id' => $wamid];
        }
        if ($response->status() === 429) {
            return ['status' => 'retry', 'error' => 'Provider rate limit; scheduled retry.'];
        }
        if ($response->successful() || $response->serverError()) {
            return ['status' => 'unknown', 'error' => 'Ambiguous provider response; verify delivery before resending.'];
        }
        // Never store raw provider payloads/tokens/customer data in errors.
        return ['status' => 'failed', 'error' => 'Provider rejected message (HTTP ' . $response->status() . '). Check template and credentials.'];
    }
}
