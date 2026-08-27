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

        // Tax-Inclusive Pricing (Menu-Rate-Final, owner Jul 2026): on inclusive bills
        // the stored item lines are MENU (tax-in) prices and the header subtotal is
        // ex-tax-consistent — so the discount-share denominator must be the INCLUSIVE
        // line sum, and per-line SaleValue/TaxCharged are back-calculated out of the
        // inclusive line (SaleValue = line×100/(100+r); TaxCharged = line − SaleValue;
        // TotalAmount = the inclusive line itself → bill sums to the menu total).
        // Column-missing prod drift → attribute reads null → false (exclusive math).
        $taxInclusive = (bool) ($transaction->tax_inclusive ?? false);
        // Card-save (mode 3, owner Jul 2026): the bill's SNAPSHOT menu rate — base is
        // divided out at the MENU (cash) rate, then the bill's own rate is charged on
        // top (card bills cheaper). NULL / missing column = classic inclusive.
        $menuRate = $taxInclusive && ($transaction->tax_menu_rate ?? null) !== null
            ? (float) $transaction->tax_menu_rate
            : null;
        $shareBase = $taxInclusive ? (float) $transaction->items->sum('subtotal') : $itemsSubtotal;

        // Task 760 (owner, 15 Aug 2026): exempt items are ZERO-RATED at PRA —
        // included in the payload with TaxRate 0 / TaxCharged 0 (competitor
        // parity: LinksXpert at ZFC reports the same items at 0% and the bill
        // verifies in the Sahulat app with "Total Tax Charged: 0.00"). They
        // are no longer filtered out, so the reported bill total finally
        // matches the printed receipt on mixed bills too.
        $items = $transaction->items
            ->filter(function ($item) {
                return (float) $item->unit_price > 0 && (float) $item->quantity > 0;
            })
            ->values()
            ->map(function ($item, $index) use ($shareBase, $totalDiscount, $taxRate, $taxInclusive, $menuRate) {
                $qty = (float) $item->quantity;
                $unitPrice = (float) $item->unit_price;
                $lineSubtotal = (float) $item->subtotal;
                $itemDiscount = $shareBase > 0 ? round($totalDiscount * ($lineSubtotal / $shareBase), 2) : 0;
                $perUnitDiscount = $qty > 0 ? round($itemDiscount / $qty, 2) : 0;
                $saleValuePerUnit = round($unitPrice - $perUnitDiscount, 2);
                if ($saleValuePerUnit <= 0) {
                    $saleValuePerUnit = 0.01;
                }
                $lineSaleValue = round($saleValuePerUnit * $qty, 2);
                $itemTaxRate = $item->is_tax_exempt ? 0 : ($item->tax_rate ?? $taxRate);
                // Exempt lines NEVER take the card-save divide-out below: their
                // menu price contains no embedded tax (frontend/receipt treat the
                // exempt share as whole menu money), so SaleValue = the line
                // itself with TaxCharged 0 — the classic-inclusive branch at
                // rate 0 produces exactly that.
                if (!$item->is_tax_exempt && $taxInclusive && $menuRate !== null && $menuRate > 0 && abs($menuRate - (float) $itemTaxRate) >= 0.005) {
                    // Card-save: base divided out at the MENU rate, own rate charged on
                    // top. TotalAmount MUST be SaleValue + TaxCharged (NOT menu money —
                    // the customer pays the cheaper card total, bill must sum to it).
                    $lineInclusive = $lineSaleValue;
                    $lineSaleValue = round($lineInclusive * 100 / (100 + $menuRate), 2);
                    $taxCharged = round($lineInclusive * $itemTaxRate / (100 + $menuRate), 2);
                    $totalAmount = round($lineSaleValue + $taxCharged, 2);
                } elseif ($taxInclusive) {
                    // $lineSaleValue here = INCLUSIVE line after discount (menu money).
                    $lineInclusive = $lineSaleValue;
                    $lineSaleValue = round($lineInclusive * 100 / (100 + $itemTaxRate), 2);
                    $taxCharged = round($lineInclusive - $lineSaleValue, 2);
                    $totalAmount = $lineInclusive;
                } else {
                    $taxCharged = round($lineSaleValue * $itemTaxRate / 100, 2);
                    $totalAmount = round($lineSaleValue + $taxCharged, 2);
                }

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
            // Task 760: exempt lines are now zero-rated INSIDE the payload, so
            // every reported bill covers the WHOLE receipt — always mirror the
            // stored whole-rupee total (memory rule pos-rounding-convention).
            $target = round($totalBillAmount);
            $storedTotal = round((float) $transaction->total_amount);
            if (abs($storedTotal - $totalBillAmount) <= 1.00) {
                $target = $storedTotal;
            }
            $diff = round($target - $totalBillAmount, 2);
            if (abs($diff) >= 0.01) {
                // Prefer the largest line that actually carries tax (absorb the
                // paisa drift into TaxCharged); an all-exempt bill falls back to
                // the largest line overall (drift absorbed into SaleValue at
                // TaxRate 0 — still consistent, 0% of anything is 0).
                $idx = 0;
                $max = -INF;
                foreach ($items as $i => $ln) {
                    if ($ln['TaxCharged'] > 0 && $ln['TotalAmount'] > $max) {
                        $max = $ln['TotalAmount'];
                        $idx = $i;
                    }
                }
                if ($max === -INF) {
                    foreach ($items as $i => $ln) {
                        if ($ln['TotalAmount'] > $max) {
                            $max = $ln['TotalAmount'];
                            $idx = $i;
                        }
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

        // ── Return / credit-note (Task 570, Aug 2026; corrected Aug 2026) ────────
        // Return rows store POSITIVE amounts (FBR Phase-2 convention).
        // PRA IMS credit-note model: InvoiceType=3, RefUSIN = original bill's
        // merchant USIN, and ALL amounts stay POSITIVE — PRA signals the reversal
        // via InvoiceType=3 alone (Code 102 "Invalid Total Bill Amount…" is what
        // PRA returns when you send negative amounts on a credit note; confirmed
        // live Aug 2026 on MALIK CHICKEN BROAST, POS ID 191963).
        // FBR IMS also uses InvoiceType=3 with positive amounts for its credit notes.
        $isReturn = ($transaction->transaction_type ?? 'sale') === 'return';
        $refUsin = null;
        $invoiceType = 1;
        if ($isReturn) {
            $invoiceType = 3;
            $parent = $transaction->parent_transaction_id
                ? PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $transaction->company_id)
                    ->find($transaction->parent_transaction_id)
                : null;
            $refUsin = $parent?->invoice_number;
            // Amounts remain positive; only InvoiceType and RefUSIN are set per line.
            foreach ($items as $i => $ln) {
                $items[$i]['InvoiceType'] = 3;
                $items[$i]['RefUSIN'] = $refUsin;
            }
            // Header totals stay positive — no sign flip.
        }

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
            'RefUSIN' => $refUsin,
            'InvoiceType' => $invoiceType,
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

        // A resale of a cooked return is a separate PRA sale, but its parent
        // credit note must reach PRA first.  Keep the resale pending when the
        // credit note is queued, offline or rejected; retrying the credit note
        // then unlocks the sale without ever submitting the wrong order.
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'pra_dependency_transaction_id')
            && $transaction->pra_dependency_transaction_id) {
            $dependency = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $transaction->company_id)
                ->find($transaction->pra_dependency_transaction_id);
            if (!$dependency || $dependency->pra_status !== 'submitted' || !$dependency->pra_invoice_number) {
                $transaction->update([
                    'pra_status' => 'pending',
                    'pra_error_message' => 'Waiting for the related PRA credit note before resale submission.',
                ]);
                return [
                    'success' => false,
                    'response_code' => 'DEPENDENCY_PENDING',
                    'queued' => true,
                    'message' => 'Resale queued until its PRA credit note is submitted.',
                ];
            }
        }

        // Task 760 (owner, 15 Aug 2026): exempt items report at 0%, so an
        // all-exempt bill submits like any other (fiscal number + QR, verifies
        // with tax 0.00) — the old 'exempt_internal' short-circuit is GONE for
        // new bills. Historical bills already stamped 'exempt_internal' stay
        // untouched: never retro-submitted.
        if ($transaction->pra_status === PosTransaction::EXEMPT_INTERNAL) {
            return ['success' => false, 'message' => 'Historical exempt bill (pre zero-rating) — kept internal, never reported to PRA.'];
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
                        'X-Relay-Token: ' . config('services.pra.relay_token', ''),
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

            $message = $responseData['Response'] ?? ($responseData['Errors'] ?? 'No response message');
            if (!$success && (string) $responseCode === '112') {
                // PRAL retired the cloud bulk PostData API for newer POS registrations.
                $message = 'PRA has retired the old cloud API for this POS ID (Code 112). Open PRA Settings and switch Connection Mode to "PRA Fiscal Device", then install PRAL\'s IMS Fiscal Device software + TaxNest Desktop Agent on the shop PC.';
            }

            // Task 624: store the real reason on the bill so the F11 modal can show it.
            $errorForBill = null;
            if (!$success) {
                $hasPraMessage = isset($responseData['Response']) || isset($responseData['Errors']);
                if ($hasPraMessage || (string) $responseCode === '112') {
                    $errorForBill = is_string($message) ? $message : json_encode($message);
                } elseif ((int) $httpCode >= 500) {
                    // Non-JSON / empty body with a 5xx status = PRA server failure.
                    $errorForBill = 'PRA server error (HTTP ' . $httpCode . ') — PRA ki taraf se masla, aapka token theek hai. Retry karein.';
                } else {
                    $errorForBill = 'PRA ne bill accept nahi kiya (HTTP ' . $httpCode . ', code ' . $responseCode . ') — PRA response mein koi wajah nahi mili.';
                }
            }
            $this->storePraResponse($praLog, $transaction, $responseData, $responseCode, $success, $praInvoiceNumber, $errorForBill);

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

            // Task 624: short human reason stored on the bill (shown in F11 modal).
            $billError = self::shortTransportError($errorMsg);

            Log::error('PRA Integration Error', [
                'transaction_id' => $transaction->id,
                'error' => $errorMsg,
                'url' => $this->getApiUrl(),
            ]);

            $this->storePraResponse($praLog, $transaction, ['error' => $errorMsg], '500', false, null, $billError);

            // ENTERPRISE SAFE MODE: Agent-Sync companies should never go to 'offline' on TLS/transport errors —
            // the desktop agent will pick up these rows and submit them from a Pakistani IP.
            // Direct Production shops (agent connected only for printing) DO fall back to 'offline'
            // so the server-side auto-retry job rescues them.
            $fallbackStatus = $this->company->agentHandlesPra() ? 'pending' : 'offline';
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

    public function storePraResponse(PraLog $praLog, PosTransaction $transaction, ?array $responseData, string $responseCode, bool $success, ?string $praInvoiceNumber, ?string $errorMessage = null): void
    {
        // Task 1475: 'submitted' is a PROMISE that PRA holds this bill under a fiscal
        // number. A "success" carrying no number cannot keep that promise — and it is
        // unrenderable: both thermal receipts gate the Sahulat QR on
        // pra_status === 'submitted' AND pra_invoice_number, so the bill falls through
        // to the local/menu-QR branch. The customer then walks out with a receipt that
        // claims PRA reporting but carries a menu QR, and nothing on screen says so.
        // Downgrade to a plain failure instead: honest, and retryable from F11.
        // Incoming number wins ONLY when it is actually a number. `??` guards null
        // but not '   ', so a blank value in the response would otherwise overwrite —
        // and then wipe — the fiscal number of a bill PRA has already accepted.
        $incomingNumber = trim((string) ($praInvoiceNumber ?? ''));
        $storedNumber = trim((string) ($transaction->pra_invoice_number ?? ''));
        $effectiveNumber = $incomingNumber !== '' ? $incomingNumber : $storedNumber;

        if ($success && $effectiveNumber === '') {
            $success = false;
            $errorMessage = 'PRA ne success (code ' . $responseCode . ') diya magar fiscal invoice number nahi bheja — bill report nahi hua. Retry karein.';
            Log::error('PRA reported success without a fiscal invoice number', [
                'transaction_id' => $transaction->id,
                'company_id' => $this->company->id ?? null,
                'response_code' => $responseCode,
                'response' => $responseData,
            ]);
        }

        $praLog->update([
            'response_payload' => $responseData,
            'response_code' => $responseCode,
            'status' => $success ? 'success' : 'failed',
        ]);

        $updateData = [
            'pra_response_code' => $responseCode,
            'pra_status' => $success ? 'submitted' : 'failed',
            'pra_invoice_number' => $effectiveNumber !== '' ? $effectiveNumber : null,
        ];

        // Task 624: persist the failure reason for the F11 modal; clear it on success.
        // hasColumn guard = prod-schema-drift-selfheal (live cPanel may lag the migration).
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'pra_error_message')) {
            $updateData['pra_error_message'] = $success ? null : mb_substr((string) ($errorMessage ?? 'PRA submission failed'), 0, 1000);
        }

        // Task 1475: keyed off the SAME value that decides the status, so
        // "submitted" always ships with both its number and its Sahulat QR.
        if ($success && $effectiveNumber !== '') {
            $updateData['pra_qr_code'] = $this->generateQrCode($effectiveNumber);
        }

        $transaction->update($updateData);
    }

    /**
     * Task 624: turn a raw transport exception into a short cashier-readable reason.
     * Roman-Urdu on purpose — this shows verbatim in the F11 Failed Bills modal.
     */
    public static function shortTransportError(string $errorMsg): string
    {
        $lower = strtolower($errorMsg);

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return 'PRA server timeout — PRA ki taraf se masla, aapka token theek hai. Retry karein.';
        }
        if (str_contains($lower, 'tls connect error') || str_contains($lower, 'ssl')) {
            return 'PRA server SSL/TLS error — PRA server se secure connection nahi bana.';
        }
        if (str_contains($lower, 'connection refused') || str_contains($lower, 'could not resolve') || str_contains($lower, 'no response')) {
            return 'PRA server not reachable — internet ya PRA server down. Auto-retry hoga.';
        }
        if (str_contains($lower, 'relay error')) {
            return 'PRA relay error — ' . mb_substr($errorMsg, 0, 200);
        }

        return mb_substr($errorMsg, 0, 300);
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
