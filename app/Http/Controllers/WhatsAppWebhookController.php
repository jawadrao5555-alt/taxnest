<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\InvoiceDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API webhook (per-company endpoint).
 *
 * Each company points their Meta App's webhook at
 *   https://<site>/webhooks/whatsapp/{company_id}
 * with the Verify Token they saved in WhatsApp Settings.
 *
 *  - GET  = Meta's subscription handshake (hub.mode/verify_token/challenge).
 *  - POST = status notifications; we update the matching invoice_deliveries
 *    row (found by provider_message_id = wamid) to sent/delivered/read/failed.
 *    Statuses only move FORWARD (read never downgrades to delivered); failed
 *    always wins and captures Meta's error message.
 *
 * Public + CSRF-exempt ('webhooks/*'); always answers 200 to POSTs so Meta
 * does not disable the subscription over transient app errors.
 */
class WhatsAppWebhookController extends Controller
{
    private const STATUS_RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3];

    public function verify(Request $request, int $company)
    {
        $c = Company::find($company);
        $token = $request->query('hub_verify_token');

        if (
            $c
            && $request->query('hub_mode') === 'subscribe'
            && !empty($c->wa_webhook_verify_token)
            && hash_equals((string) $c->wa_webhook_verify_token, (string) $token)
        ) {
            return response((string) $request->query('hub_challenge'), 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request, int $company)
    {
        try {
            foreach ((array) $request->input('entry', []) as $entry) {
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                        $this->applyStatus($company, $status);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("WA webhook processing error (company {$company}): " . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    private function applyStatus(int $companyId, array $status): void
    {
        $wamid = $status['id'] ?? null;
        $new = strtolower((string) ($status['status'] ?? ''));
        if (!$wamid || !in_array($new, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        $delivery = InvoiceDelivery::where('company_id', $companyId)
            ->where('provider_message_id', $wamid)
            ->first();
        if (!$delivery) {
            return; // not ours (or purged) — ack silently
        }

        if ($new === 'failed') {
            $errors = $status['errors'] ?? [];
            $msg = 'WhatsApp delivery failed';
            if (!empty($errors[0])) {
                $msg = trim(($errors[0]['title'] ?? '') . ' ' . ($errors[0]['message'] ?? ''));
                if (!empty($errors[0]['error_data']['details'])) {
                    $msg .= ' — ' . $errors[0]['error_data']['details'];
                }
            }
            $delivery->status = 'failed';
            $delivery->error = mb_substr($msg, 0, 500);
            $delivery->save();
            return;
        }

        // Forward-only progression; failed is terminal.
        $currentRank = self::STATUS_RANK[$delivery->status] ?? 0;
        if ($delivery->status !== 'failed' && (self::STATUS_RANK[$new] ?? 0) > $currentRank) {
            $delivery->status = $new;
            $delivery->save();
        }
    }
}
