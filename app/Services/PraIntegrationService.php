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

    private const PAYMENT_MODE_MAP = [
        'cash' => 1,
        'debit_card' => 2,
        'credit_card' => 2,
        'qr_payment' => 2,
        'mixed' => 5,
    ];

    private const SANDBOX_URL = 'https://ims.pral.com.pk/ims/sandbox/api/Live/PostData';
    private const PRODUCTION_URL = 'https://ims.pral.com.pk/ims/production/api/Live/PostData';
    private const SANDBOX_TOKEN = '24d8fab3-f2e9-398f-ae17-b387125ec4a2';

    public function __construct(Company $company)
    {
        $this->company = $company;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->company->pra_reporting_enabled;
    }

    public function getApiUrl(): string
    {
        $env = $this->company->pra_environment ?? 'sandbox';
        return $env === 'production' ? self::PRODUCTION_URL : self::SANDBOX_URL;
    }

    public function getToken(): string
    {
        $env = $this->company->pra_environment ?? 'sandbox';
        if ($env === 'production') {
            return $this->company->pra_production_token ?? '';
        }
        return self::SANDBOX_TOKEN;
    }

    public function generatePayload(PosTransaction $transaction): array
    {
        $transaction->load('items');

        $itemsSubtotal = (float) $transaction->subtotal;
        $totalDiscount = (float) $transaction->discount_amount;
        $taxRate = (float) $transaction->tax_rate;

        $items = $transaction->items->map(function ($item, $index) use ($itemsSubtotal, $totalDiscount, $taxRate) {
            $itemSubtotal = (float) $item->subtotal;
            $itemDiscount = $itemsSubtotal > 0 ? round($totalDiscount * ($itemSubtotal / $itemsSubtotal), 2) : 0;
            $saleValue = $itemSubtotal - $itemDiscount;
            $taxCharged = round($saleValue * $taxRate / 100, 2);
            $totalAmount = round($saleValue + $taxCharged, 2);

            return [
                'ItemCode' => $item->item_id ? sprintf('%04d', $item->item_id) : sprintf('IT_%04d', $index + 1),
                'ItemName' => $item->item_name,
                'Quantity' => (float) $item->quantity,
                'PCTCode' => '00000000',
                'TaxRate' => $taxRate,
                'SaleValue' => $saleValue,
                'TotalAmount' => $totalAmount,
                'TaxCharged' => $taxCharged,
                'Discount' => $itemDiscount,
                'FurtherTax' => 0.0,
                'InvoiceType' => 1,
                'RefUSIN' => null,
            ];
        })->toArray();

        $paymentMode = self::PAYMENT_MODE_MAP[$transaction->payment_method] ?? 1;

        return [
            'InvoiceNumber' => '',
            'POSID' => (int) ($this->company->pra_pos_id ?? 0),
            'USIN' => $transaction->invoice_number,
            'DateTime' => $transaction->created_at->format('Y-m-d H:i:s'),
            'BuyerName' => $transaction->customer_name ?? '',
            'BuyerPNTN' => '',
            'BuyerCNIC' => '',
            'BuyerPhoneNumber' => $transaction->customer_phone ?? '',
            'TotalSaleValue' => (float) $transaction->subtotal,
            'TotalQuantity' => (float) $transaction->items->sum('quantity'),
            'TotalTaxCharged' => (float) $transaction->tax_amount,
            'Discount' => (float) $transaction->discount_amount,
            'FurtherTax' => 0.0,
            'TotalBillAmount' => (float) $transaction->total_amount,
            'PaymentMode' => $paymentMode,
            'RefUSIN' => null,
            'InvoiceType' => 1,
            'Items' => $items,
        ];
    }

    public function sendInvoice(PosTransaction $transaction): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'PRA reporting is disabled'];
        }

        if ($transaction->pra_invoice_number) {
            return ['success' => false, 'message' => 'Invoice already submitted to PRA. PRA Invoice #: ' . $transaction->pra_invoice_number];
        }

        if ($transaction->pra_status === 'local') {
            return ['success' => false, 'message' => 'Local invoice cannot be synced to PRA'];
        }

        if ($transaction->submission_hash) {
            $duplicate = PosTransaction::where('submission_hash', $transaction->submission_hash)
                ->where('id', '!=', $transaction->id)
                ->whereNotNull('pra_invoice_number')
                ->exists();
            if ($duplicate) {
                return ['success' => false, 'message' => 'Duplicate submission detected via hash'];
            }
        }

        $payload = $this->generatePayload($transaction);

        $praLog = PraLog::create([
            'company_id' => $this->company->id,
            'transaction_id' => $transaction->id,
            'request_payload' => $payload,
            'status' => 'pending',
        ]);

        try {
            $response = Http::timeout(30)
                ->withToken($this->getToken())
                ->post($this->getApiUrl(), $payload);

            $responseData = $response->json();
            $responseCode = $responseData['Code'] ?? (string) $response->status();
            $praInvoiceNumber = $responseData['InvoiceNumber'] ?? null;
            $success = $responseCode === '100' || $response->successful();

            $this->storePraResponse($praLog, $transaction, $responseData, $responseCode, $success, $praInvoiceNumber);

            return [
                'success' => $success,
                'response_code' => $responseCode,
                'data' => $responseData,
                'pra_invoice_number' => $praInvoiceNumber,
                'message' => $responseData['Response'] ?? 'No response message',
            ];
        } catch (\Exception $e) {
            Log::error('PRA Integration Error', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            $this->storePraResponse($praLog, $transaction, ['error' => $e->getMessage()], '500', false, null);

            return [
                'success' => false,
                'response_code' => '500',
                'message' => 'PRA API connection failed: ' . $e->getMessage(),
            ];
        }
    }

    public function storePraResponse(PraLog $praLog, PosTransaction $transaction, ?array $responseData, string $responseCode, bool $success, ?string $praInvoiceNumber): void
    {
        $praLog->update([
            'response_payload' => $responseData,
            'response_code' => $responseCode,
            'status' => $success ? 'success' : 'failed',
        ]);

        $updateData = [
            'pra_response_code' => $responseCode,
            'pra_status' => $success ? 'submitted' : 'failed',
            'pra_invoice_number' => $praInvoiceNumber ?? $transaction->pra_invoice_number,
        ];

        if ($success && $praInvoiceNumber) {
            $updateData['pra_qr_code'] = $this->generateQrCode($praInvoiceNumber);
        }

        $transaction->update($updateData);
    }

    public function generateQrCode(string $praInvoiceNumber): string
    {
        $verificationUrl = 'https://reg.pra.punjab.gov.pk/IMSFiscalReport/SearchPOSInvoice_Report.aspx?PRAInvNo=' . urlencode($praInvoiceNumber);

        try {
            $qrCode = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')
                ->size(150)
                ->margin(1)
                ->generate($verificationUrl);

            return 'data:image/svg+xml;base64,' . base64_encode($qrCode);
        } catch (\Exception $e) {
            Log::error('QR Code Generation Error', ['error' => $e->getMessage()]);
            return '';
        }
    }

    public function getVerificationUrl(string $praInvoiceNumber): string
    {
        return 'https://reg.pra.punjab.gov.pk/IMSFiscalReport/SearchPOSInvoice_Report.aspx?PRAInvNo=' . urlencode($praInvoiceNumber);
    }
}
