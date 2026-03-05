<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PraLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PraIntegrationService
{
    protected Company $company;

    public function __construct(Company $company)
    {
        $this->company = $company;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->company->pra_reporting_enabled;
    }

    public function generatePayload(PosTransaction $transaction): array
    {
        $items = $transaction->items->map(function ($item) {
            return [
                'name' => $item->item_name,
                'quantity' => $item->quantity,
                'unitPrice' => (float) $item->unit_price,
                'totalPrice' => (float) $item->subtotal,
                'itemType' => $item->item_type,
            ];
        })->toArray();

        return [
            'invoiceNumber' => $transaction->invoice_number,
            'terminalId' => $transaction->terminal_id,
            'companyNtn' => $this->company->ntn,
            'companyName' => $this->company->name,
            'customerName' => $transaction->customer_name,
            'customerPhone' => $transaction->customer_phone,
            'subtotal' => (float) $transaction->subtotal,
            'discountType' => $transaction->discount_type,
            'discountValue' => (float) $transaction->discount_value,
            'discountAmount' => (float) $transaction->discount_amount,
            'taxRate' => (float) $transaction->tax_rate,
            'taxAmount' => (float) $transaction->tax_amount,
            'totalAmount' => (float) $transaction->total_amount,
            'paymentMethod' => $transaction->payment_method,
            'items' => $items,
            'timestamp' => $transaction->created_at->toIso8601String(),
        ];
    }

    public function sendInvoice(PosTransaction $transaction): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'PRA reporting is disabled'];
        }

        $payload = $this->generatePayload($transaction);

        $praLog = PraLog::create([
            'company_id' => $this->company->id,
            'transaction_id' => $transaction->id,
            'request_payload' => $payload,
            'status' => 'pending',
        ]);

        try {
            $response = Http::timeout(30)->post(
                config('services.pra.api_url', 'https://api.pra.punjab.gov.pk/pos/invoice'),
                $payload
            );

            $responseData = $response->json();
            $responseCode = (string) $response->status();

            $this->storePraResponse($praLog, $transaction, $responseData, $responseCode, $response->successful());

            return [
                'success' => $response->successful(),
                'response_code' => $responseCode,
                'data' => $responseData,
                'pra_invoice_number' => $responseData['praInvoiceNumber'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('PRA Integration Error', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            $this->storePraResponse($praLog, $transaction, ['error' => $e->getMessage()], '500', false);

            return [
                'success' => false,
                'response_code' => '500',
                'message' => 'PRA API connection failed: ' . $e->getMessage(),
            ];
        }
    }

    public function storePraResponse(PraLog $praLog, PosTransaction $transaction, ?array $responseData, string $responseCode, bool $success): void
    {
        $praLog->update([
            'response_payload' => $responseData,
            'response_code' => $responseCode,
            'status' => $success ? 'success' : 'failed',
        ]);

        $transaction->update([
            'pra_response_code' => $responseCode,
            'pra_status' => $success ? 'reported' : 'failed',
            'pra_invoice_number' => $responseData['praInvoiceNumber'] ?? $transaction->pra_invoice_number,
        ]);
    }
}
