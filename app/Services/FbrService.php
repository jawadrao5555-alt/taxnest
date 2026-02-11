<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class FbrService
{
    public function submitInvoice($invoice)
    {
        $payload = [
            "invoiceType" => "Sale Invoice",
            "invoiceDate" => now()->toDateString(),
            "sellerNTNCNIC" => $invoice->company->ntn ?? "",
            "buyerNTNCNIC" => $invoice->buyer_ntn,
            "buyerBusinessName" => $invoice->buyer_name,
            "items" => []
        ];

        foreach ($invoice->items as $item) {
            $payload["items"][] = [
                "hsCode" => $item->hs_code,
                "quantity" => $item->quantity,
                "valueSalesExcludingST" => $item->price,
                "salesTaxApplicable" => $item->tax
            ];
        }

        // Sandbox example
        // $response = Http::withToken("YOUR_FBR_TOKEN")
        //     ->post("https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata", $payload);

        return [
            "status" => "success",
            "fbr_invoice_number" => "FBR" . time()
        ];
    }
}
