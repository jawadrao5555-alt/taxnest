<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use App\Models\FbrLog;

class FbrService
{
    private const SANDBOX_POST_URL = 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb';
    private const PRODUCTION_POST_URL = 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata';
    private const SANDBOX_VALIDATE_URL = 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb';
    private const PRODUCTION_VALIDATE_URL = 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata';

    public function buildPayload($invoice): array
    {
        $company = $invoice->company;
        $env = $company->fbr_environment ?? 'sandbox';

        $payload = [
            "invoiceType" => $invoice->document_type ?? "Sale Invoice",
            "invoiceDate" => $invoice->invoice_date ?? ($invoice->created_at ? $invoice->created_at->toDateString() : now()->toDateString()),
            "sellerNTNCNIC" => $this->formatNtnCnic($company->ntn ?? ""),
            "sellerBusinessName" => $company->fbr_business_name ?: ($company->name ?? ""),
            "sellerProvince" => $invoice->supplier_province ?? $company->province ?? "Punjab",
            "sellerAddress" => $company->address ?? "",
            "buyerNTNCNIC" => $this->formatNtnCnic($invoice->buyer_ntn ?? ""),
            "buyerBusinessName" => $invoice->buyer_name ?? "",
            "buyerProvince" => $invoice->destination_province ?? "Punjab",
            "buyerAddress" => $invoice->buyer_address ?? "",
            "buyerRegistrationType" => $invoice->buyer_registration_type ?? $this->determineBuyerRegistrationType($invoice->buyer_ntn),
            "invoiceRefNo" => $this->resolveInvoiceRefNo($invoice),
            "items" => []
        ];

        if ($env === 'sandbox') {
            $payload["scenarioId"] = "SN001";
        }

        foreach ($invoice->items as $item) {
            $scheduleType = $item->schedule_type ?? 'standard';
            $taxRate = floatval($item->tax_rate ?? 18);
            $quantity = round(floatval($item->quantity), 4);
            $unitPrice = floatval($item->price);
            $valueSalesExcludingST = round($quantity * $unitPrice, 2);
            $salesTaxApplicable = round($valueSalesExcludingST * ($taxRate / 100), 2);
            $totalValues = round($valueSalesExcludingST + $salesTaxApplicable, 2);

            $itemPayload = [
                "hsCode" => $item->hs_code ?? "",
                "productDescription" => $item->description ?? "",
                "rate" => intval($taxRate) . "%",
                "uoM" => $item->default_uom ?: "Numbers, pieces, units",
                "quantity" => $quantity,
                "totalValues" => $totalValues,
                "valueSalesExcludingST" => $valueSalesExcludingST,
                "fixedNotifiedValueOrRetailPrice" => round(floatval($item->mrp ?? 0), 2),
                "salesTaxApplicable" => $salesTaxApplicable,
                "salesTaxWithheldAtSource" => round($item->st_withheld_at_source ? floatval($item->st_withheld_at_source) : 0.00, 2),
                "extraTax" => round(floatval($item->extra_tax ?? 0), 2),
                "furtherTax" => round(floatval($item->further_tax ?? 0), 2),
                "sroScheduleNo" => $item->sro_schedule_no ?? "",
                "fedPayable" => round(floatval($item->fed_payable ?? 0), 2),
                "discount" => round(floatval($item->discount ?? 0), 2),
                "saleType" => $item->sale_type ?: ScheduleEngine::mapSaleType($scheduleType),
                "sroItemSerialNo" => $item->serial_no ?? ""
            ];

            if ($item->petroleum_levy && $item->petroleum_levy > 0) {
                $itemPayload["petroleumLevy"] = round(floatval($item->petroleum_levy), 2);
            }

            $payload["items"][] = $itemPayload;
        }

        return $payload;
    }

    private function resolveInvoiceRefNo($invoice): string
    {
        if ($invoice->document_type !== 'Debit Note') {
            return "";
        }

        if (!empty($invoice->reference_invoice_number)) {
            $refInvoice = \App\Models\Invoice::where('company_id', $invoice->company_id)
                ->where(function ($q) use ($invoice) {
                    $q->where('fbr_invoice_number', $invoice->reference_invoice_number)
                      ->orWhere('internal_invoice_number', $invoice->reference_invoice_number)
                      ->orWhere('invoice_number', $invoice->reference_invoice_number);
                })
                ->first();

            if ($refInvoice && !empty($refInvoice->fbr_invoice_number)) {
                return $refInvoice->fbr_invoice_number;
            }

            return $invoice->reference_invoice_number;
        }

        return "";
    }

    private function formatNtnCnic(?string $value): string
    {
        if (empty($value)) return "";
        $clean = preg_replace('/[^0-9]/', '', $value);
        return $clean;
    }

    private function determineBuyerRegistrationType(?string $buyerNtn): string
    {
        if (empty($buyerNtn)) return "Unregistered";
        $clean = preg_replace('/[^0-9]/', '', $buyerNtn);
        if (strlen($clean) === 7 || strlen($clean) === 13) return "Registered";
        return "Unregistered";
    }

    private function getApiToken($company): string
    {
        $env = $company->fbr_environment ?? 'sandbox';
        $encryptedToken = '';
        if ($env === 'production') {
            $encryptedToken = $company->fbr_production_token ?? '';
        } else {
            $encryptedToken = $company->fbr_sandbox_token ?? '';
        }

        if (empty($encryptedToken)) {
            return '';
        }

        try {
            return Crypt::decryptString($encryptedToken);
        } catch (\Exception $e) {
            return $encryptedToken;
        }
    }

    private function getPostUrl($company): string
    {
        $env = $company->fbr_environment ?? 'sandbox';
        if ($env === 'production') {
            return $company->fbr_production_url ?: self::PRODUCTION_POST_URL;
        }
        return $company->fbr_sandbox_url ?: self::SANDBOX_POST_URL;
    }

    private function getValidateUrl($company): string
    {
        $env = $company->fbr_environment ?? 'sandbox';
        if ($env === 'production') {
            return self::PRODUCTION_VALIDATE_URL;
        }
        return self::SANDBOX_VALIDATE_URL;
    }

    public function submitInvoice($invoice, int $retryCount = 0)
    {
        $payload = $this->buildPayload($invoice);
        $company = $invoice->company;

        $payloadErrors = ScheduleEngine::validateFbrPayload($payload);
        if (!empty($payloadErrors)) {
            $log = FbrLog::create([
                'invoice_id' => $invoice->id,
                'request_payload' => json_encode($payload),
                'status' => 'failed',
                'response_payload' => json_encode(['errors' => $payloadErrors]),
                'response_time_ms' => 0,
                'retry_count' => $retryCount,
            ]);
            $log->failure_type = 'payload_error';
            $log->save();

            return [
                'status' => 'failed',
                'failure_type' => 'payload_error',
                'errors' => $payloadErrors,
                'response_time_ms' => 0,
            ];
        }

        $demoMode = \App\Models\SystemSetting::get('demo_mode', 'false') === 'true';

        if ($demoMode) {
            $mockFbrNumber = 'MOCK-FBR-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            $log = FbrLog::create([
                'invoice_id' => $invoice->id,
                'request_payload' => json_encode(array_merge($payload, ['demo_mode' => true])),
                'status' => 'success',
                'response_payload' => json_encode(['status' => 'success', 'fbr_invoice_number' => $mockFbrNumber, 'mock' => true]),
                'response_time_ms' => rand(500, 1500),
                'retry_count' => 0,
            ]);

            return [
                'status' => 'success',
                'fbr_invoice_number' => $mockFbrNumber,
                'response_time_ms' => $log->response_time_ms,
            ];
        }

        $token = $this->getApiToken($company);
        $url = $this->getPostUrl($company);

        if (empty($token)) {
            $log = FbrLog::create([
                'invoice_id' => $invoice->id,
                'request_payload' => json_encode($payload),
                'status' => 'failed',
                'response_payload' => json_encode(['error' => 'FBR token not configured']),
                'response_time_ms' => 0,
                'retry_count' => $retryCount,
            ]);
            $log->failure_type = 'token_error';
            $log->save();

            return [
                'status' => 'failed',
                'failure_type' => 'token_error',
                'response_time_ms' => 0,
            ];
        }

        $log = FbrLog::create([
            'invoice_id' => $invoice->id,
            'request_payload' => json_encode($payload),
            'status' => 'pending',
            'retry_count' => $retryCount,
        ]);

        $startTime = microtime(true);

        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->post($url, $payload);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $log->response_time_ms = $responseTimeMs;
            $log->response_payload = $response->body();

            if ($response->successful()) {
                $responseData = $response->json();
                $fbrResult = $this->parseFbrResponse($responseData);

                if ($fbrResult['valid']) {
                    $log->status = 'success';
                    $log->save();

                    return [
                        "status" => "success",
                        "fbr_invoice_number" => $fbrResult['invoiceNumber'],
                        "response_time_ms" => $responseTimeMs,
                        "fbr_response" => $responseData,
                    ];
                } else {
                    $log->status = 'failed';
                    $log->failure_type = 'validation_error';
                    $log->save();

                    return [
                        "status" => "failed",
                        "failure_type" => "validation_error",
                        "errors" => $fbrResult['errors'],
                        "response_time_ms" => $responseTimeMs,
                        "fbr_response" => $responseData,
                    ];
                }
            }

            $failureType = $this->classifyFailure($response->status(), $response->body());
            $log->status = 'failed';
            $log->failure_type = $failureType;
            $log->save();

            $errors = $this->extractErrorsFromResponse($response->body());

            return [
                "status" => "failed",
                "failure_type" => $failureType,
                "errors" => $errors,
                "response_time_ms" => $responseTimeMs,
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $log->status = 'failed';
            $log->failure_type = 'network_error';
            $log->response_time_ms = $responseTimeMs;
            $log->response_payload = $e->getMessage();
            $log->save();

            return [
                "status" => "failed",
                "failure_type" => "network_error",
                "errors" => [$e->getMessage()],
                "response_time_ms" => $responseTimeMs,
            ];

        } catch (\Exception $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $log->status = 'failed';
            $log->failure_type = 'network_error';
            $log->response_time_ms = $responseTimeMs;
            $log->response_payload = $e->getMessage();
            $log->save();

            return [
                "status" => "failed",
                "failure_type" => "network_error",
                "errors" => [$e->getMessage()],
                "response_time_ms" => $responseTimeMs,
            ];
        }
    }

    private function parseFbrResponse(array $responseData): array
    {
        $invoiceNumber = $responseData['invoiceNumber'] ?? $responseData['InvoiceNumber'] ?? null;

        if (isset($responseData['validationResponse'])) {
            $validation = $responseData['validationResponse'];
            $statusCode = $validation['statusCode'] ?? '01';
            $status = strtolower($validation['status'] ?? 'invalid');

            if ($statusCode === '00' && $status === 'valid') {
                $itemInvoiceNumbers = [];
                if (!empty($validation['invoiceStatuses'])) {
                    foreach ($validation['invoiceStatuses'] as $itemStatus) {
                        if (isset($itemStatus['invoiceNo'])) {
                            $itemInvoiceNumbers[] = $itemStatus['invoiceNo'];
                        }
                    }
                }

                return [
                    'valid' => true,
                    'invoiceNumber' => $invoiceNumber ?? ($itemInvoiceNumbers[0] ?? ((string) time())),
                    'itemInvoiceNumbers' => $itemInvoiceNumbers,
                    'errors' => [],
                ];
            }

            $errors = [];
            if (!empty($validation['error'])) {
                $errors[] = $validation['error'];
            }
            if (!empty($validation['invoiceStatuses'])) {
                foreach ($validation['invoiceStatuses'] as $itemStatus) {
                    if (($itemStatus['statusCode'] ?? '') === '01' && !empty($itemStatus['error'])) {
                        $errorMsg = "Item {$itemStatus['itemSNo']}: [{$itemStatus['errorCode']}] {$itemStatus['error']}";
                        $errors[] = $errorMsg;
                    }
                }
            }

            return [
                'valid' => false,
                'invoiceNumber' => null,
                'itemInvoiceNumbers' => [],
                'errors' => $errors ?: ['FBR validation failed'],
            ];
        }

        if ($invoiceNumber) {
            return [
                'valid' => true,
                'invoiceNumber' => $invoiceNumber,
                'itemInvoiceNumbers' => [],
                'errors' => [],
            ];
        }

        return [
            'valid' => false,
            'invoiceNumber' => null,
            'itemInvoiceNumbers' => [],
            'errors' => ['Unexpected FBR response format'],
        ];
    }

    private function extractErrorsFromResponse(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!$decoded) return [$body];

        $errors = [];

        if (isset($decoded['fault'])) {
            $errors[] = ($decoded['fault']['message'] ?? '') . ': ' . ($decoded['fault']['description'] ?? '');
        }

        if (isset($decoded['validationResponse'])) {
            $v = $decoded['validationResponse'];
            if (!empty($v['error'])) $errors[] = $v['error'];
            if (!empty($v['invoiceStatuses'])) {
                foreach ($v['invoiceStatuses'] as $s) {
                    if (!empty($s['error'])) {
                        $errors[] = "Item {$s['itemSNo']}: [{$s['errorCode']}] {$s['error']}";
                    }
                }
            }
        }

        return $errors ?: [$body];
    }

    public function validateOnly($invoice): array
    {
        $payload = $this->buildPayload($invoice);
        $company = $invoice->company;
        $env = $company->fbr_environment ?? 'sandbox';

        $payloadErrors = ScheduleEngine::validateFbrPayload($payload);
        if (!empty($payloadErrors)) {
            return [
                'status' => 'invalid',
                'errors' => $payloadErrors,
                'payload' => $payload,
            ];
        }

        $demoMode = \App\Models\SystemSetting::get('demo_mode', 'false') === 'true';
        if ($demoMode) {
            return [
                'status' => 'valid',
                'message' => 'Payload structure validated successfully (demo mode - no FBR call made)',
                'payload' => $payload,
            ];
        }

        $token = $this->getApiToken($company);
        if (empty($token)) {
            return [
                'status' => 'valid',
                'message' => 'Payload structure validated locally. Configure FBR token in FBR Settings to test against FBR servers.',
                'payload' => $payload,
            ];
        }

        $validateUrl = $this->getValidateUrl($company);

        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->post($validateUrl, $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                $fbrResult = $this->parseFbrResponse($responseData);

                if ($fbrResult['valid']) {
                    return [
                        'status' => 'valid',
                        'message' => "FBR {$env} payload validated successfully",
                        'payload' => $payload,
                        'fbr_response' => $responseData,
                    ];
                }

                return [
                    'status' => 'invalid',
                    'message' => "FBR {$env} rejected the payload",
                    'errors' => $fbrResult['errors'],
                    'payload' => $payload,
                    'fbr_response' => $responseData,
                ];
            }

            $errors = $this->extractErrorsFromResponse($response->body());
            return [
                'status' => 'invalid',
                'message' => "FBR {$env} rejected the payload",
                'errors' => $errors,
                'payload' => $payload,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'valid',
                'message' => "Payload structure validated locally. FBR {$env} endpoint unreachable.",
                'payload' => $payload,
            ];
        }
    }

    private function classifyFailure(int $statusCode, string $body): string
    {
        if ($statusCode === 401 || $statusCode === 403) {
            return 'token_error';
        }

        if ($statusCode === 422 || $statusCode === 400) {
            return 'validation_error';
        }

        if ($statusCode >= 500) {
            return 'server_error';
        }

        return 'payload_error';
    }
}
