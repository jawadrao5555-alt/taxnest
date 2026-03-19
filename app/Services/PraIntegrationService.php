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
    private const LOCAL_FISCAL_ENDPOINT = '/api/IMSFiscal/GetInvoiceNumberByModel';
    private const SANDBOX_TOKEN = '24d8fab3-f2e9-398f-ae17-b387125ec4a2';

    public function __construct(Company $company)
    {
        $this->company = $company;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->company->pra_reporting_enabled;
    }

    public function getLocalFiscalUrl(): string
    {
        $url = env('PRA_LOCAL_FISCAL_URL') ?: (isset($_ENV['PRA_LOCAL_FISCAL_URL']) ? $_ENV['PRA_LOCAL_FISCAL_URL'] : (getenv('PRA_LOCAL_FISCAL_URL') ?: ''));
        return $url;
    }

    public function useLocalFiscal(): bool
    {
        return !empty($this->getLocalFiscalUrl());
    }

    public function useProxy(): bool
    {
        $url = env('PRA_PROXY_URL') ?: (isset($_ENV['PRA_PROXY_URL']) ? $_ENV['PRA_PROXY_URL'] : getenv('PRA_PROXY_URL'));
        return !empty($url);
    }

    public function getProxyUrl(): string
    {
        return env('PRA_PROXY_URL') ?: (isset($_ENV['PRA_PROXY_URL']) ? $_ENV['PRA_PROXY_URL'] : (getenv('PRA_PROXY_URL') ?: ''));
    }

    public function getProxySecret(): string
    {
        return env('PRA_PROXY_SECRET') ?: (isset($_ENV['PRA_PROXY_SECRET']) ? $_ENV['PRA_PROXY_SECRET'] : (getenv('PRA_PROXY_SECRET') ?: ''));
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

        $items = $transaction->items->map(function ($item, $index) use ($itemsSubtotal, $totalDiscount, $taxRate) {
            $qty = (float) $item->quantity;
            $unitPrice = (float) $item->unit_price;
            $lineSubtotal = (float) $item->subtotal;
            $itemDiscount = $itemsSubtotal > 0 ? round($totalDiscount * ($lineSubtotal / $itemsSubtotal), 2) : 0;
            $perUnitDiscount = $qty > 0 ? round($itemDiscount / $qty, 2) : 0;
            $saleValuePerUnit = round($unitPrice - $perUnitDiscount, 2);
            $lineSaleValue = round($saleValuePerUnit * $qty, 2);
            $taxCharged = round($lineSaleValue * $taxRate / 100, 2);
            $totalAmount = round($lineSaleValue + $taxCharged, 2);

            return [
                'ItemCode' => $item->item_id ? sprintf('%04d', $item->item_id) : sprintf('IT_%04d', $index + 1),
                'ItemName' => preg_replace('/[^a-zA-Z0-9\s]/', '', $item->item_name),
                'Quantity' => $qty,
                'PCTCode' => '00000000',
                'TaxRate' => $taxRate,
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
            'TotalQuantity' => (float) $transaction->items->sum('quantity'),
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

        $mode = 'direct';
        if ($this->useLocalFiscal()) {
            $mode = 'local_fiscal';
        } elseif ($this->useProxy()) {
            $mode = 'proxy';
        }

        $response = null;

        try {
            if ($mode === 'local_fiscal') {
                $localUrl = rtrim($this->getLocalFiscalUrl(), '/') . self::LOCAL_FISCAL_ENDPOINT;

                Log::info('PRA Local Fiscal: Submitting invoice', [
                    'transaction_id' => $transaction->id,
                    'url' => $localUrl,
                    'pos_id' => $payload['POSID'],
                ]);

                $response = Http::timeout(30)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'ngrok-skip-browser-warning' => 'true',
                    ])
                    ->post($localUrl, $payload);

                $responseData = $response->json();

                if ($response->status() === 404 || $response->status() === 502 || $response->status() === 503) {
                    Log::warning('PRA Local Fiscal unavailable, trying proxy/direct', [
                        'transaction_id' => $transaction->id,
                        'status' => $response->status(),
                    ]);
                    $mode = $this->useProxy() ? 'proxy' : 'direct';
                    $response = null;
                } else {
                    $responseCode = $responseData['Code'] ?? (string) $response->status();
                    $praInvoiceNumber = $responseData['InvoiceNumber'] ?? null;
                    $success = $responseCode === '100';

                    if ($praInvoiceNumber === 'Not Available') {
                        $praInvoiceNumber = null;
                        $success = false;
                    }

                    $this->storePraResponse($praLog, $transaction, $responseData, $responseCode, $success, $praInvoiceNumber);

                    return [
                        'success' => $success,
                        'response_code' => $responseCode,
                        'data' => $responseData,
                        'pra_invoice_number' => $praInvoiceNumber,
                        'message' => $responseData['Response'] ?? ($responseData['Errors'] ?? 'No response message'),
                    ];
                }
            }

            if ($mode === 'proxy') {
                $proxyPayload = [
                    'pra_url' => $this->getApiUrl(),
                    'pra_token' => $this->getToken(),
                    'invoice_data' => $payload,
                ];

                $proxyRequest = Http::timeout(45);

                $proxySecret = $this->getProxySecret();
                if ($proxySecret) {
                    $proxyRequest = $proxyRequest->withHeaders([
                        'X-Proxy-Secret' => $proxySecret,
                    ]);
                }

                $response = $proxyRequest->post($this->getProxyUrl(), $proxyPayload);

                if ($response->status() === 404 || $response->status() === 502 || $response->status() === 503) {
                    Log::warning('PRA Proxy unavailable, attempting direct connection', [
                        'transaction_id' => $transaction->id,
                        'proxy_url' => $this->getProxyUrl(),
                        'proxy_status' => $response->status(),
                    ]);
                    $mode = 'direct_fallback';
                    $response = null;
                }
            }

            if ($response === null) {
                $response = Http::timeout(30)
                    ->withToken($this->getToken())
                    ->withOptions([
                        'curl' => [
                            CURLOPT_SSL_CIPHER_LIST => 'DEFAULT:!DH',
                        ],
                    ])
                    ->post($this->getApiUrl(), $payload);
            }

            $responseData = $response->json();
            $responseCode = $responseData['Code'] ?? (string) $response->status();
            $praInvoiceNumber = $responseData['InvoiceNumber'] ?? null;
            $success = $responseCode === '100';

            if ($praInvoiceNumber === 'Not Available') {
                $praInvoiceNumber = null;
                $success = false;
            }

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
                $userMessage = 'PRA server blocked connection (IP not whitelisted). Invoice saved offline — will sync when Pakistani proxy server is configured.';
            } elseif (str_contains($errorMsg, 'Connection refused') || str_contains($errorMsg, 'timed out')) {
                $userMessage = 'PRA server not reachable. Invoice saved offline — will auto-retry later.';
            }

            Log::error('PRA Integration Error', [
                'transaction_id' => $transaction->id,
                'error' => $errorMsg,
                'mode' => $mode,
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
