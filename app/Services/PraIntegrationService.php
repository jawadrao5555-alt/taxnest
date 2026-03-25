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
        'mixed' => 3,
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

    private function sanitizeBuyerName(?string $name): string
    {
        if (empty($name)) {
            return 'Customer';
        }
        $clean = preg_replace('/[^a-zA-Z\s]/', '', $name);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));
        return !empty($clean) ? $clean : 'Customer';
    }

    public function generatePayload(PosTransaction $transaction): array
    {
        $transaction->load('items');

        $itemsSubtotal = (float) $transaction->subtotal;
        $totalDiscount = (float) $transaction->discount_amount;
        $taxRate = (float) $transaction->tax_rate;

        $items = $transaction->items
            ->filter(function ($item) {
                return (float) $item->unit_price > 0 && (float) $item->quantity > 0;
            })
            ->values()
            ->map(function ($item, $index) use ($itemsSubtotal, $totalDiscount, $taxRate) {
                $qty = (float) $item->quantity;
                $unitPrice = (float) $item->unit_price;
                $lineSubtotal = (float) $item->subtotal;
                $itemDiscount = $itemsSubtotal > 0 ? round($totalDiscount * ($lineSubtotal / $itemsSubtotal), 2) : 0;
                $perUnitDiscount = $qty > 0 ? round($itemDiscount / $qty, 2) : 0;
                $saleValuePerUnit = round($unitPrice - $perUnitDiscount, 2);
                if ($saleValuePerUnit <= 0) {
                    $saleValuePerUnit = 0.01;
                }
                $lineSaleValue = round($saleValuePerUnit * $qty, 2);
                $itemTaxRate = $item->is_tax_exempt ? 0 : ($item->tax_rate ?? $taxRate);
                $taxCharged = round($lineSaleValue * $itemTaxRate / 100, 2);
                $totalAmount = round($lineSaleValue + $taxCharged, 2);

                return [
                    'ItemCode' => $item->item_id ? sprintf('%04d', $item->item_id) : sprintf('IT_%04d', $index + 1),
                    'ItemName' => preg_replace('/[^a-zA-Z0-9\s]/', '', $item->item_name),
                    'Quantity' => $qty,
                    'PCTCode' => '00000000',
                    'TaxRate' => $itemTaxRate,
                    'SaleValue' => $saleValuePerUnit,
                    'TotalAmount' => $totalAmount,
                    'TaxCharged' => $taxCharged,
                    'Discount' => 0.0,
                    'FurtherTax' => 0.0,
                    'InvoiceType' => 1,
                    'RefUSIN' => null,
                ];
            })->toArray();

        $paymentMode = self::PAYMENT_MODE_MAP[$transaction->payment_method] ?? 1;

        $totalSaleValue = array_sum(array_map(fn($i) => round($i['SaleValue'] * $i['Quantity'], 2), $items));
        $totalTaxCharged = array_sum(array_column($items, 'TaxCharged'));
        $totalBillAmount = array_sum(array_column($items, 'TotalAmount'));

        return [
            'InvoiceNumber' => '',
            'POSID' => (int) ($this->company->pra_pos_id ?? 0),
            'USIN' => $transaction->invoice_number,
            'DateTime' => $transaction->created_at->format('Y-m-d\TH:i:s'),
            'BuyerName' => $this->sanitizeBuyerName($transaction->customer_name),
            'BuyerPNTN' => '',
            'BuyerCNIC' => '',
            'BuyerPhoneNumber' => $transaction->customer_phone ?? '',
            'TotalSaleValue' => $totalSaleValue,
            'TotalQuantity' => array_sum(array_column($items, 'Quantity')),
            'TotalTaxCharged' => $totalTaxCharged,
            'Discount' => 0.0,
            'FurtherTax' => 0.0,
            'TotalBillAmount' => $totalBillAmount,
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
            Log::info('PRA Direct: Submitting invoice to PRAL IMS', [
                'transaction_id' => $transaction->id,
                'url' => $this->getApiUrl(),
                'pos_id' => $payload['POSID'],
                'environment' => $this->company->pra_environment ?? 'sandbox',
            ]);

            $apiUrl = $this->getApiUrl();
            $token = $this->getToken();
            $jsonPayload = json_encode($payload);

            $responseBody = null;
            $httpCode = 0;
            $method = 'unknown';

            $sslContext = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                ],
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer {$token}\r\nConnection: close\r\n",
                    'content' => $jsonPayload,
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
            ]);

            $streamResult = @file_get_contents($apiUrl, false, $sslContext);

            if ($streamResult !== false) {
                $method = 'stream';
                $responseBody = $streamResult;
                $httpCode = 200;
                if (isset($http_response_header)) {
                    foreach ($http_response_header as $hdr) {
                        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $hdr, $m)) {
                            $httpCode = (int) $m[1];
                        }
                    }
                }
            }

            if ($responseBody === null) {
                $method = 'curl';
                $ch = curl_init($apiUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $jsonPayload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Authorization: Bearer ' . $token,
                        'Connection: close',
                    ],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                    CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=0',
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_FORBID_REUSE => true,
                    CURLOPT_FRESH_CONNECT => true,
                ]);

                $curlResult = curl_exec($ch);
                $curlError = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($curlResult !== false && !$curlError) {
                    $responseBody = $curlResult;
                } else {
                    throw new \Exception('PRA connection failed (stream+curl): ' . ($curlError ?: 'No response'));
                }
            }

            Log::info('PRA Direct: Raw response', [
                'transaction_id' => $transaction->id,
                'http_code' => $httpCode,
                'method' => $method,
                'body_length' => strlen($responseBody ?? ''),
            ]);

            $responseData = json_decode($responseBody, true) ?? [];
            $responseCode = $responseData['Code'] ?? (string) $httpCode;
            $praInvoiceNumber = $responseData['InvoiceNumber'] ?? null;
            $success = $responseCode === '100';

            if ($praInvoiceNumber === 'Not Available') {
                $praInvoiceNumber = null;
                $success = false;
            }

            Log::info('PRA Direct: Response received', [
                'transaction_id' => $transaction->id,
                'response_code' => $responseCode,
                'success' => $success,
                'pra_invoice_number' => $praInvoiceNumber,
            ]);

            $this->storePraResponse($praLog, $transaction, $responseData, $responseCode, $success, $praInvoiceNumber);

            return [
                'success' => $success,
                'response_code' => $responseCode,
                'data' => $responseData,
                'pra_invoice_number' => $praInvoiceNumber,
                'message' => $responseData['Response'] ?? ($responseData['Errors'] ?? 'No response message'),
            ];
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            $userMessage = 'PRA API connection failed.';

            if (str_contains($errorMsg, 'TLS connect error') || str_contains($errorMsg, 'SSL')) {
                $userMessage = 'PRA server SSL connection failed. Please check your PRA settings and try again.';
            } elseif (str_contains($errorMsg, 'Connection refused') || str_contains($errorMsg, 'timed out')) {
                $userMessage = 'PRA server not reachable. Invoice saved offline — will auto-retry later.';
            }

            Log::error('PRA Integration Error', [
                'transaction_id' => $transaction->id,
                'error' => $errorMsg,
                'url' => $this->getApiUrl(),
            ]);

            $this->storePraResponse($praLog, $transaction, ['error' => $errorMsg], '500', false, null);

            $transaction->update(['pra_status' => 'offline']);

            return [
                'success' => false,
                'response_code' => '500',
                'message' => $userMessage,
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
