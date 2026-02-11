<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\FbrLog;

class FbrService
{
    private const SANDBOX_URL = 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb';
    private const PRODUCTION_URL = 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata';
    private const VALIDATE_ONLY_URL = 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb';

    public function buildPayload($invoice): array
    {
        $company = $invoice->company;

        $payload = [
            "invoiceType" => $invoice->document_type ?? "Sale Invoice",
            "invoiceDate" => $invoice->invoice_date ?? ($invoice->created_at ? $invoice->created_at->toDateString() : now()->toDateString()),
            "sellerNTNCNIC" => $company->ntn ?? "",
            "sellerBusinessName" => $company->fbr_business_name ?: ($company->name ?? ""),
            "sellerProvince" => $invoice->supplier_province ?? $company->province ?? "",
            "sellerAddress" => $company->address ?? "",
            "buyerNTNCNIC" => $invoice->buyer_ntn,
            "buyerBusinessName" => $invoice->buyer_name,
            "buyerProvince" => $invoice->destination_province ?? "",
            "buyerAddress" => $invoice->buyer_address ?? "",
            "buyerRegistrationType" => $invoice->buyer_registration_type ?? $this->determineBuyerRegistrationType($invoice->buyer_ntn),
            "invoiceRefNo" => $invoice->internal_invoice_number ?? $invoice->invoice_number ?? "",
            "items" => []
        ];

        if (in_array($invoice->document_type, ['Credit Note', 'Debit Note']) && $invoice->reference_invoice_number) {
            $payload["referenceInvoiceNo"] = $invoice->reference_invoice_number;
        }

        foreach ($invoice->items as $item) {
            $scheduleType = $item->schedule_type ?? 'standard';
            $taxRate = $item->tax_rate ?? 18;
            $quantity = floatval($item->quantity);
            $unitPrice = floatval($item->price);
            $valueSalesExcludingST = round($quantity * $unitPrice, 2);
            $salesTaxApplicable = round($valueSalesExcludingST * ($taxRate / 100), 2);
            $totalValues = round($valueSalesExcludingST + $salesTaxApplicable, 2);

            $itemPayload = [
                "hsCode" => $item->hs_code,
                "productDescription" => $item->description,
                "rate" => $taxRate . "%",
                "uoM" => $item->default_uom ?: "Numbers, pieces, units",
                "quantity" => $quantity,
                "totalValues" => $totalValues,
                "valueSalesExcludingST" => $valueSalesExcludingST,
                "fixedNotifiedValueOrRetailPrice" => floatval($item->mrp ?? 0),
                "salesTaxApplicable" => $salesTaxApplicable,
                "salesTaxWithheldAtSource" => $item->st_withheld_at_source ? $salesTaxApplicable : 0,
                "extraTax" => 0,
                "furtherTax" => 0,
                "sroScheduleNo" => $item->sro_schedule_no ?? "",
                "fedPayable" => 0,
                "discount" => 0,
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

    private function determineBuyerRegistrationType(?string $buyerNtn): string
    {
        if (empty($buyerNtn)) return "Unregistered";
        $clean = preg_replace('/[^0-9]/', '', $buyerNtn);
        if (strlen($clean) >= 7) return "Registered";
        return "Unregistered";
    }

    private function getApiToken($company): string
    {
        $env = $company->fbr_environment ?? 'sandbox';
        if ($env === 'production') {
            return $company->fbr_production_token ?? '';
        }
        return $company->fbr_sandbox_token ?? '';
    }

    private function getApiUrl($company): string
    {
        $env = $company->fbr_environment ?? 'sandbox';
        return $env === 'production' ? self::PRODUCTION_URL : self::SANDBOX_URL;
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
        $url = $this->getApiUrl($company);

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
                $log->status = 'success';
                $log->save();

                $responseData = $response->json();
                $fbrNumber = $responseData['InvoiceNumber'] ?? $responseData['fbr_invoice_number'] ?? ((string) time());

                return [
                    "status" => "success",
                    "fbr_invoice_number" => $fbrNumber,
                    "response_time_ms" => $responseTimeMs,
                ];
            }

            $failureType = $this->classifyFailure($response->status(), $response->body());
            $log->status = 'failed';
            $log->failure_type = $failureType;
            $log->save();

            return [
                "status" => "failed",
                "failure_type" => $failureType,
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
                "response_time_ms" => $responseTimeMs,
            ];
        }
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

        if ($env === 'production') {
            return [
                'status' => 'valid',
                'message' => 'Payload structure validated locally. Validate-only mode is available in sandbox environment only. Switch to sandbox in FBR Settings to test against FBR servers.',
                'payload' => $payload,
            ];
        }

        $token = $this->getApiToken($company);
        if (empty($token)) {
            return [
                'status' => 'valid',
                'message' => 'Payload structure validated locally. Configure a sandbox token in FBR Settings to test against FBR servers.',
                'payload' => $payload,
            ];
        }

        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->post(self::VALIDATE_ONLY_URL, $payload);

            if ($response->successful()) {
                return [
                    'status' => 'valid',
                    'message' => 'FBR sandbox payload validated successfully',
                    'payload' => $payload,
                    'fbr_response' => $response->json(),
                ];
            }

            return [
                'status' => 'invalid',
                'message' => 'FBR sandbox rejected the payload',
                'errors' => [$response->body()],
                'payload' => $payload,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'valid',
                'message' => 'Payload structure validated locally. FBR sandbox endpoint unreachable - this is normal if sandbox is not available.',
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
            $decoded = json_decode($body, true);
            if ($decoded && isset($decoded['errors'])) {
                return 'validation_error';
            }
            return 'payload_error';
        }

        if ($statusCode >= 500) {
            return 'network_error';
        }

        return 'payload_error';
    }
}
