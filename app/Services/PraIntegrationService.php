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
        // Per-cashier toggle (owner rule Jul 2026): this service submits bills that
        // were ALREADY queued (pending/offline/failed) by a user whose own switch was
        // ON — so it's enabled when reporting is active for ANY account of the company.
        return $this->company->praReportingActive();
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
                return (float) $item->unit_price > 0 && (float) $item->quantity > 0 && !$item->is_tax_exempt;
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
                    // PRAL IMS v1.2 spec: SaleValue = LINE TOTAL (qty × unit price), excluding tax.
                    // Sending per-unit caused PRA portal Gross Total summary to be wrong (sum of per-unit
                    // prices instead of sum of line totals). Per-line "Total" column was unaffected
                    // because it reads our TotalAmount field directly.
                    'SaleValue' => $lineSaleValue,
                    'TotalAmount' => $totalAmount,
                    'TaxCharged' => $taxCharged,
                    'Discount' => 0.0,
                    'FurtherTax' => 0.0,
                    'InvoiceType' => 1,
                    'RefUSIN' => null,
                ];
            })->toArray();

        $paymentMode = self::PAYMENT_MODE_MAP[$transaction->payment_method] ?? 1;

        // SaleValue is now per-line (already qty-multiplied), so just sum the column directly.
        $totalSaleValue = array_sum(array_column($items, 'SaleValue'));
        $totalTaxCharged = array_sum(array_column($items, 'TaxCharged'));
        $totalBillAmount = array_sum(array_column($items, 'TotalAmount'));

        // ── Whole-rupee bill at PRA (matches receipt/stored total convention) ──
        // Per-line 2dp values can sum to a fractional bill (e.g. 580.07 @16% → 672.88)
        // even though the STORED total is whole-rupee (673). Absorb the paisa difference
        // into the largest line so Items still sum EXACTLY to TotalBillAmount.
        if (count($items) > 0) {
            $hasExempt = $transaction->items->contains(fn ($item) => (bool) $item->is_tax_exempt);
            // Exempt lines are not reported, so the PRA bill is the taxable-only subset —
            // round that subset. A full (no-exempt) bill mirrors the stored whole-rupee total.
            $target = round($totalBillAmount);
            if (!$hasExempt) {
                $storedTotal = round((float) $transaction->total_amount);
                if (abs($storedTotal - $totalBillAmount) <= 1.00) {
                    $target = $storedTotal;
                }
            }
            $diff = round($target - $totalBillAmount, 2);
            if (abs($diff) >= 0.01) {
                $idx = 0;
                $max = -INF;
                foreach ($items as $i => $ln) {
                    if ($ln['TotalAmount'] > $max) {
                        $max = $ln['TotalAmount'];
                        $idx = $i;
                    }
                }
                if ($items[$idx]['TaxCharged'] > 0 && $items[$idx]['TaxCharged'] + $diff >= 0) {
                    $items[$idx]['TaxCharged'] = round($items[$idx]['TaxCharged'] + $diff, 2);
                } elseif ($items[$idx]['SaleValue'] + $diff > 0) {
                    $items[$idx]['SaleValue'] = round($items[$idx]['SaleValue'] + $diff, 2);
                } else {
                    $diff = 0.0; // cannot absorb safely — keep the raw sums
                }
                if ($diff !== 0.0) {
                    $items[$idx]['TotalAmount'] = round($items[$idx]['TotalAmount'] + $diff, 2);
                    $totalSaleValue = array_sum(array_column($items, 'SaleValue'));
                    $totalTaxCharged = array_sum(array_column($items, 'TaxCharged'));
                    $totalBillAmount = array_sum(array_column($items, 'TotalAmount'));
                }
            }
        }

        // Guard against float-sum drift (e.g. 0.30000000000000004) in the JSON payload.
        $totalSaleValue = round($totalSaleValue, 2);
        $totalTaxCharged = round($totalTaxCharged, 2);
        $totalBillAmount = round($totalBillAmount, 2);

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

        $allExempt = $transaction->items->every(fn($item) => $item->is_tax_exempt);
        if ($allExempt) {
            Log::info("PRA submission skipped: Transaction #{$transaction->id} — all items are tax-exempt. Internal only.");
            $transaction->pra_status = 'exempt_internal';
            $transaction->save();
            return ['success' => true, 'message' => 'All items are tax-exempt — not reported to PRA. Locked internally.', 'exempt_only' => true];
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

        // PRA Fiscal Device mode: the cloud PostData API is retired for this company's POS ID.
        // Submission can ONLY happen from the shop PC (PRAL local service on localhost:8524),
        // so the server never attempts a direct call — the desktop agent picks the row up.
        if (($this->company->pra_connection_mode ?? 'cloud') === 'fiscal_device') {
            $transaction->update(['pra_status' => 'pending']);
            return [
                'success' => false,
                'response_code' => 'QUEUED',
                'message' => 'Queued for desktop agent — PRA Fiscal Device mode submits from your shop PC.',
                'queued_for_agent' => true,
            ];
        }

        $payload = $this->generatePayload($transaction);

        $praLog = PraLog::create([
            'company_id' => $this->company->id,
            'transaction_id' => $transaction->id,
            'request_payload' => $payload,
            'status' => 'pending',
        ]);

        try {
            $apiUrl = $this->getApiUrl();
            $token = $this->getToken();
            $rawProxy = $this->company->pra_proxy_url;
            $relayUrl = !empty($rawProxy) ? rtrim($rawProxy, '/') : null;

            Log::info('PRA DEBUG: relay check', [
                'company_id' => $this->company->id,
                'raw_proxy' => $rawProxy,
                'relay_url' => $relayUrl,
                'relay_active' => $relayUrl ? 'YES' : 'NO',
                'company_class' => get_class($this->company),
            ]);

            Log::info('PRA: Submitting invoice to PRAL IMS', [
                'transaction_id' => $transaction->id,
                'url' => $apiUrl,
                'relay' => $relayUrl ? 'yes' : 'direct',
                'pos_id' => $payload['POSID'],
                'environment' => $this->company->pra_environment ?? 'sandbox',
            ]);

            $responseBody = null;
            $httpCode = 0;
            $method = $relayUrl ? 'relay' : 'curl-direct';

            if ($relayUrl) {
                $relayPayload = $payload;
                $relayPayload['_pra_token'] = $token;
                $relayPayload['_pra_url'] = $apiUrl;
                $jsonPayload = json_encode($relayPayload);

                $ch = curl_init($relayUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $jsonPayload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,            // hard cap — never block cashier > 8s (was 25)
                    CURLOPT_CONNECTTIMEOUT => 3,     // fail fast on dead connection (was 6)
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Accept-Encoding: gzip, deflate',
                        'X-Relay-Token: taxnest-pra-relay-2026',
                        'ngrok-skip-browser-warning: true',
                        'Connection: keep-alive',
                    ],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_ENCODING => 'gzip',          // auto-decompress
                    CURLOPT_TCP_NODELAY => 1,            // no Nagle delay
                    CURLOPT_TCP_KEEPALIVE => 1,
                    CURLOPT_TCP_KEEPIDLE => 60,
                    CURLOPT_TCP_KEEPINTVL => 15,
                    CURLOPT_DNS_CACHE_TIMEOUT => 600,    // 10min DNS cache
                    CURLOPT_FORBID_REUSE => false,
                    CURLOPT_FRESH_CONNECT => false,
                ]);
            } else {
                $jsonPayload = json_encode($payload);

                $ch = curl_init($apiUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $jsonPayload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,            // hard cap — never block cashier > 8s (was 20)
                    CURLOPT_CONNECTTIMEOUT => 3,     // fail fast on dead connection (was 6)
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Accept-Encoding: gzip, deflate',
                        'Authorization: Bearer ' . $token,
                        'Connection: keep-alive',
                    ],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_ENCODING => 'gzip',
                    CURLOPT_TCP_NODELAY => 1,
                    CURLOPT_TCP_KEEPALIVE => 1,
                    CURLOPT_TCP_KEEPIDLE => 60,
                    CURLOPT_TCP_KEEPINTVL => 15,
                    CURLOPT_DNS_CACHE_TIMEOUT => 600,
                    CURLOPT_FORBID_REUSE => false,
                    CURLOPT_FRESH_CONNECT => false,
                ]);
            }

            $curlResult = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlResult !== false && !$curlError) {
                if ($method === 'relay') {
                    $relayData = json_decode($curlResult, true);
                    if (isset($relayData['relay_error'])) {
                        throw new \Exception('PRA relay error: ' . ($relayData['error'] ?? 'Unknown relay error'));
                    }
                }
                $responseBody = $curlResult;
            } else {
                throw new \Exception('PRA connection failed: ' . ($curlError ?: 'No response'));
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

            $message = $responseData['Response'] ?? ($responseData['Errors'] ?? 'No response message');
            if (!$success && (string) $responseCode === '112') {
                // PRAL retired the cloud bulk PostData API for newer POS registrations.
                $message = 'PRA has retired the old cloud API for this POS ID (Code 112). Open PRA Settings and switch Connection Mode to "PRA Fiscal Device", then install PRAL\'s IMS Fiscal Device software + TaxNest Desktop Agent on the shop PC.';
            }

            return [
                'success' => $success,
                'response_code' => $responseCode,
                'data' => $responseData,
                'pra_invoice_number' => $praInvoiceNumber,
                'message' => $message,
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

            // ENTERPRISE SAFE MODE: agent-enabled companies should never go to 'offline' on TLS/transport errors —
            // the desktop agent will pick up these rows and submit them from a Pakistani IP.
            $fallbackStatus = ($this->company->agent_enabled ?? false) ? 'pending' : 'offline';
            $transaction->update(['pra_status' => $fallbackStatus]);

            return [
                'success' => false,
                'response_code' => '500',
                'message' => $fallbackStatus === 'pending'
                    ? 'Queued for desktop agent — will sync from local PC.'
                    : $userMessage,
                'queued_for_agent' => $fallbackStatus === 'pending',
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
        // PRA Sahulat app expects the QR to contain the RAW PRA invoice number
        // (scanning yields the number → app fetches details). Generic scanners
        // give the customer the number to paste on the PRA verification portal.
        // Do NOT encode a URL here.
        try {
            $qr = \App\Support\QrImage::dataUri($praInvoiceNumber);
            if ($qr !== '') {
                return $qr;
            }
            Log::error('QR Code Generation Error', ['error' => 'QrImage returned empty string']);
            return '';
        } catch (\Throwable $e) {
            Log::error('QR Code Generation Error', ['error' => $e->getMessage()]);
            return '';
        }
    }

    public function getVerificationUrl(string $praInvoiceNumber): string
    {
        return 'https://reg.pra.punjab.gov.pk/IMSFiscalReport/SearchPOSInvoice_Report.aspx?PRAInvNo=' . urlencode($praInvoiceNumber);
    }
}
