<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\FbrLog;

class FbrService
{
    public function submitInvoice($invoice, int $retryCount = 0)
    {
        $payload = [
            "invoiceType" => "Sale Invoice",
            "invoiceDate" => now()->toDateString(),
            "sellerNTNCNIC" => $invoice->company->ntn ?? "",
            "sellerBusinessName" => $invoice->company->name ?? "",
            "sellerProvince" => "Sindh",
            "sellerAddress" => $invoice->company->address ?? "",
            "buyerNTNCNIC" => $invoice->buyer_ntn,
            "buyerBusinessName" => $invoice->buyer_name,
            "buyerProvince" => "Sindh",
            "buyerAddress" => "Karachi",
            "buyerRegistrationType" => "Unregistered",
            "invoiceRefNo" => "",
            "items" => []
        ];

        foreach ($invoice->items as $item) {
            $payload["items"][] = [
                "hsCode" => $item->hs_code,
                "productDescription" => $item->description,
                "rate" => "18%",
                "uoM" => "Numbers, pieces, units",
                "quantity" => $item->quantity,
                "totalValues" => $item->price + $item->tax,
                "valueSalesExcludingST" => $item->price,
                "fixedNotifiedValueOrRetailPrice" => 0,
                "salesTaxApplicable" => $item->tax,
                "salesTaxWithheldAtSource" => 0,
                "extraTax" => 0,
                "furtherTax" => 0,
                "sroScheduleNo" => "",
                "fedPayable" => 0,
                "discount" => 0,
                "saleType" => "Goods at standard rate (default)",
                "sroItemSerialNo" => ""
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
                ->withToken("YOUR_SANDBOX_TOKEN")
                ->post("https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb", $payload);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $log->response_time_ms = $responseTimeMs;
            $log->response_payload = $response->body();

            if ($response->successful()) {
                $log->status = 'success';
                $log->save();

                return [
                    "status" => "success",
                    "fbr_invoice_number" => (string) time(),
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
