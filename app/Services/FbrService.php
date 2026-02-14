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

    private function sanitizeForFbr(?string $text): string
    {
        if (empty($text)) return "";
        $text = preg_replace('/[\n\r\t]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function getUomByHsCode(?string $hsCode, ?string $defaultUom = 'U'): string
    {
        if (empty($hsCode)) return $this->normalizeUom($defaultUom);
        $clean = str_replace('.', '', $hsCode);
        $chapter = intval(substr($clean, 0, 2));

        if ($chapter === 22) return "Liter";
        if ($chapter === 27) return "Liter";
        if ($chapter === 31) return "KG";

        return $this->normalizeUom($defaultUom);
    }

    public function buildPayload($invoice): array
    {
        $company = $invoice->company;
        $env = $company->fbr_environment ?? 'sandbox';

        $invoiceType = $invoice->document_type ?? "Sale Invoice";

        $items = [];
        foreach ($invoice->items as $item) {
            $scheduleType = $item->schedule_type ?? 'standard';
            $taxRate = floatval($item->tax_rate ?? 18);
            $quantity = round(floatval($item->quantity), 4);
            $unitPrice = floatval($item->price);
            $valueSalesExcludingST = round($unitPrice * $quantity, 2);

            $rawSaleType = $item->sale_type ?: ScheduleEngine::mapSaleType($scheduleType);
            $is3rdSchedule = (stripos($rawSaleType, '3rd Schedule') !== false);
            $isExempt = (stripos($rawSaleType, 'Exempt') !== false || stripos($rawSaleType, 'exempt') !== false);
            $isReduced = (stripos($rawSaleType, 'Reduced') !== false || stripos($rawSaleType, 'reduced') !== false);
            $saleTypeNormalized = $this->normalizeSaleType($rawSaleType, $env);

            if ($is3rdSchedule) {
                $mrpPerUnit = floatval($item->mrp ?? 0);
                if ($mrpPerUnit <= 0) {
                    $mrpPerUnit = $unitPrice;
                }
                $retailPrice = round($mrpPerUnit * $quantity, 2);
                $salesTaxApplicable = round(($valueSalesExcludingST * $taxRate) / 100, 2);
            } elseif ($isExempt) {
                $retailPrice = $valueSalesExcludingST;
                $salesTaxApplicable = 0.00;
            } elseif ($isReduced) {
                $retailPrice = $valueSalesExcludingST;
                $salesTaxApplicable = round(($valueSalesExcludingST * $taxRate) / 100, 2);
            } else {
                $retailPrice = $valueSalesExcludingST;
                $salesTaxApplicable = round(($valueSalesExcludingST * $taxRate) / 100, 2);
            }

            if ($isExempt) {
                $salesTaxApplicable = 0.00;
                $extraTaxVal = 0.00;
            } elseif ($isReduced) {
                $extraTaxVal = 0.00;
            } else {
                $extraTaxVal = round(floatval($item->extra_tax ?? 0) * $quantity, 2);
            }

            $furtherTax = round(floatval($item->further_tax ?? 0) * $quantity, 2);
            $fedPayable = round(floatval($item->fed_payable ?? 0) * $quantity, 2);
            $discount = round(floatval($item->discount ?? 0) * $quantity, 2);

            $totalValues = round($valueSalesExcludingST + $salesTaxApplicable + floatval($extraTaxVal) + $furtherTax + $fedPayable - $discount, 2);

            if ($isExempt) {
                $rateStr = "Exempt";
            } else {
                $rateStr = ($taxRate == intval($taxRate)) ? intval($taxRate) . "%" : number_format($taxRate, 1) . "%";
            }

            $hsCode = $item->hs_code ?? "";
            $uomCode = $this->getUomByHsCode($hsCode, $item->default_uom ?? 'U');

            $itemPayload = [
                "uoM" => $uomCode,
                "rate" => $rateStr,
                "hsCode" => $hsCode,
                "discount" => (float) round($discount, 2),
                "extraTax" => (float) round(floatval($extraTaxVal), 2),
                "quantity" => (float) round($quantity, 4),
                "saleType" => $saleTypeNormalized,
                "fedPayable" => (float) round($fedPayable, 2),
                "furtherTax" => (float) round($furtherTax, 2),
                "totalValues" => (float) round($totalValues, 2),
                "productDescription" => $item->description ?? "",
                "salesTaxApplicable" => (float) round($salesTaxApplicable, 2),
                "valueSalesExcludingST" => (float) round($valueSalesExcludingST, 2),
                "salesTaxWithheldAtSource" => (float) round($item->st_withheld_at_source ? floatval($item->st_withheld_at_source) : 0.00, 2),
                "fixedNotifiedValueOrRetailPrice" => (float) round($retailPrice, 2),
            ];

            $needsSro = ($is3rdSchedule && $taxRate < 18) || $isExempt || $isReduced;
            if ($needsSro) {
                $sroValue = $item->sro_schedule_no ?? "";
                if (stripos($sroValue, '3rd schedule') !== false) {
                    $sroValue = '3rd Schedule goods';
                }
                $itemPayload["sroScheduleNo"] = $sroValue;
                $itemPayload["sroItemSerialNo"] = $item->serial_no ?? "";
            }

            if ($item->petroleum_levy && $item->petroleum_levy > 0) {
                $itemPayload["petroleumLevy"] = round(floatval($item->petroleum_levy), 2);
            }

            $items[] = $itemPayload;
        }

        $docTypeMap = [
            'Sale Invoice' => 1,
            'Debit Note' => 4,
            'Credit Note' => 3,
        ];

        $payload = [
            "items" => $items,
            "invoiceDate" => $invoice->invoice_date ?? ($invoice->created_at ? $invoice->created_at->toDateString() : now()->toDateString()),
            "invoiceType" => $invoiceType,
            "documentTypeId" => $docTypeMap[$invoiceType] ?? 1,
            "buyerAddress" => $this->sanitizeForFbr($invoice->buyer_address ?? 'CUSTOMER ADDRESS'),
            "invoiceRefNo" => $this->resolveInvoiceRefNo($invoice),
            "buyerProvince" => $this->normalizeProvince($invoice->destination_province ?? "Punjab"),
            "sellerAddress" => $this->sanitizeForFbr($company->address ?? ""),
            "sellerNTNCNIC" => $this->formatNtnCnic($company->fbr_registration_no ?: ($company->ntn ?? "")),
            "sellerProvince" => $this->normalizeProvince($invoice->supplier_province ?? $company->province ?? "Punjab"),
            "buyerBusinessName" => $this->sanitizeForFbr($invoice->buyer_name ?? 'CUSTOMER'),
            "sellerBusinessName" => $this->sanitizeForFbr($company->fbr_business_name ?: ($company->name ?? "")),
            "buyerRegistrationType" => $invoice->buyer_registration_type ?? $this->determineBuyerRegistrationType($invoice->buyer_ntn),
            "buyerNTNCNIC" => $this->formatNtnCnic($invoice->buyer_ntn ?? ""),
        ];

        if ($env === 'sandbox') {
            $payload["scenarioId"] = "SN001";
        }

        return $payload;
    }

    public function validatePayloadPreSubmission(array $payload): array
    {
        $errors = [];

        if (empty($payload['sellerNTNCNIC'])) {
            $errors[] = ['code' => '0001', 'message' => 'Seller NTN/CNIC is missing. Please configure FBR Registration Number.'];
        }

        if (empty($payload['invoiceType'])) {
            $errors[] = ['code' => '0011', 'message' => 'Invoice type is missing.'];
        }

        if (empty($payload['invoiceDate'])) {
            $errors[] = ['code' => '0042', 'message' => 'Invoice date is missing.'];
        }

        if (empty($payload['buyerBusinessName'])) {
            $errors[] = ['code' => '0010', 'message' => 'Buyer name is missing.'];
        }

        if (empty($payload['buyerRegistrationType'])) {
            $errors[] = ['code' => '0012', 'message' => 'Buyer registration type is missing.'];
        }

        if (empty($payload['sellerProvince'])) {
            $errors[] = ['code' => '0073', 'message' => 'Seller province (Sale Origination) is missing.'];
        }

        if (empty($payload['buyerProvince'])) {
            $errors[] = ['code' => '0074', 'message' => 'Buyer province (Destination of Supply) is missing.'];
        }

        $invoiceType = $payload['invoiceType'] ?? '';
        if (in_array($invoiceType, ['Debit Note', 'Credit Note'])) {
            if (empty($payload['invoiceRefNo'])) {
                $errors[] = ['code' => '0026', 'message' => 'Invoice Reference No. is required for ' . $invoiceType . '.'];
            }
        }

        if (empty($payload['items']) || !is_array($payload['items'])) {
            $errors[] = ['code' => 'ITEM', 'message' => 'No items found in payload.'];
            return $errors;
        }

        foreach ($payload['items'] as $idx => $item) {
            $sn = $idx + 1;

            if (empty($item['hsCode'])) {
                $errors[] = ['code' => '0044', 'message' => "Item #{$sn}: HS Code is missing."];
            }

            if (empty($item['rate'])) {
                $errors[] = ['code' => '0046', 'message' => "Item #{$sn}: Rate is missing."];
            }

            if (empty($item['saleType'])) {
                $errors[] = ['code' => '0013', 'message' => "Item #{$sn}: Sale type is missing."];
            }

            $rate = str_replace('%', '', $item['rate'] ?? '0');
            $valueExclST = floatval($item['valueSalesExcludingST'] ?? 0);
            if (is_numeric($rate) && floatval($rate) == 5 && $valueExclST > 20000) {
                $errors[] = ['code' => '0079', 'message' => "Item #{$sn}: Value exceeds Rs. 20,000 - 5% rate is not allowed for values above this threshold."];
            }

            $saleType = strtolower($item['saleType'] ?? '');
            if (strpos($saleType, '3rd schedule') !== false) {
                $retailPrice = floatval($item['fixedNotifiedValueOrRetailPrice'] ?? 0);
                if ($retailPrice <= 0) {
                    $errors[] = ['code' => '0090', 'message' => "Item #{$sn}: Retail/MRP price is required for 3rd Schedule goods."];
                }

                if ($valueExclST > 0 && is_numeric($rate)) {
                    $expectedTax = round(($valueExclST * floatval($rate)) / 100, 2);
                    $actualTax = floatval($item['salesTaxApplicable'] ?? 0);
                    if (abs($expectedTax - $actualTax) > 0.02) {
                        $errors[] = ['code' => '0102', 'message' => "Item #{$sn}: Calculated tax ({$actualTax}) doesn't match expected ({$expectedTax}) for 3rd Schedule."];
                    }
                }
            }

            if ($saleType === 'exempt goods' || $saleType === 'exempt') {
                if (floatval($item['salesTaxApplicable'] ?? 0) != 0) {
                    $errors[] = ['code' => '0018', 'message' => "Item #{$sn}: Exempt goods should have zero sales tax."];
                }
            }
        }

        return $errors;
    }

    private function normalizeProvince(?string $province): string
    {
        if (empty($province)) return "Punjab";

        $map = [
            'punjab' => 'Punjab',
            'sindh' => 'Sindh',
            'balochistan' => 'Balochistan',
            'khyber pakhtunkhwa' => 'Khyber Pakhtunkhwa',
            'kpk' => 'Khyber Pakhtunkhwa',
            'kp' => 'Khyber Pakhtunkhwa',
            'islamabad' => 'Islamabad Capital Territory',
            'capital territory' => 'Islamabad Capital Territory',
            'ict' => 'Islamabad Capital Territory',
            'islamabad capital territory' => 'Islamabad Capital Territory',
            'azad kashmir' => 'Azad Jammu and Kashmir',
            'azad jammu and kashmir' => 'Azad Jammu and Kashmir',
            'ajk' => 'Azad Jammu and Kashmir',
            'gilgit baltistan' => 'Gilgit Baltistan',
            'gilgit-baltistan' => 'Gilgit Baltistan',
            'gb' => 'Gilgit Baltistan',
        ];

        $normalized = $map[strtolower(trim($province))] ?? null;
        if ($normalized) return $normalized;

        return ucwords(strtolower(trim($province)));
    }

    private function normalizeUom(?string $uom): string
    {
        if (empty($uom)) return "Numbers, pieces, units";

        $map = [
            'kilograms' => 'KG',
            'kilogram' => 'KG',
            'kgs' => 'KG',
            'kg' => 'KG',
            'liters' => 'Liter',
            'liter' => 'Liter',
            'litres' => 'Liter',
            'litre' => 'Liter',
            'ltr' => 'Liter',
            'ltrs' => 'Liter',
            'l' => 'Liter',
            'pieces' => 'Numbers, pieces, units',
            'piece' => 'Numbers, pieces, units',
            'pcs' => 'Pcs',
            'units' => 'Numbers, pieces, units',
            'unit' => 'Numbers, pieces, units',
            'nos' => 'Numbers, pieces, units',
            'numbers' => 'Numbers, pieces, units',
            'number' => 'Numbers, pieces, units',
            'each' => 'Numbers, pieces, units',
            'ea' => 'Numbers, pieces, units',
            'meters' => 'Meter',
            'meter' => 'Meter',
            'metre' => 'Meter',
            'metres' => 'Meter',
            'mtr' => 'Meter',
            'mt' => 'MT',
            'metric ton' => 'MT',
            'metric tons' => 'MT',
            'ton' => 'MT',
            'tons' => 'MT',
            'set' => 'SET',
            'sets' => 'SET',
            'bags' => 'Bag',
            'bag' => 'Bag',
            'dozen' => 'Dozen',
            'dzn' => 'Dozen',
            'dz' => 'Dozen',
            'pair' => 'Pair',
            'pairs' => 'Pair',
            'packs' => 'Packs',
            'pack' => 'Packs',
            'packet' => 'Packs',
            'packets' => 'Packs',
            'gallon' => 'Gallon',
            'gallons' => 'Gallon',
            'gal' => 'Gallon',
            'gram' => 'Gram',
            'grams' => 'Gram',
            'gm' => 'Gram',
            'gms' => 'Gram',
            'g' => 'Gram',
            'pound' => 'Pound',
            'pounds' => 'Pound',
            'lb' => 'Pound',
            'lbs' => 'Pound',
            'carat' => 'Carat',
            'carats' => 'Carat',
            'sqft' => 'Square Foot',
            'sq ft' => 'Square Foot',
            'square foot' => 'Square Foot',
            'square feet' => 'Square Foot',
            'sqm' => 'Square Metre',
            'sq m' => 'Square Metre',
            'square meter' => 'Square Metre',
            'square metre' => 'Square Metre',
            'sqy' => 'SqY',
            'sq y' => 'SqY',
            'square yard' => 'SqY',
            'square yards' => 'SqY',
            'kwh' => 'KWH',
            'kilowatt hour' => 'KWH',
            'foot' => 'Foot',
            'feet' => 'Foot',
            'ft' => 'Foot',
            'barrels' => 'Barrels',
            'barrel' => 'Barrels',
            'bbl' => 'Barrels',
            'mmbtu' => 'MMBTU',
            'cubic metre' => 'Cubic Metre',
            'cubic meter' => 'Cubic Metre',
            'cbm' => 'Cubic Metre',
            'others' => 'Others',
            'other' => 'Others',
            '40kg' => '40KG',
            'bill of lading' => 'Bill of lading',
            'bol' => 'Bill of lading',
            'no' => 'NO',
            'timber logs' => 'Timber Logs',
            'mega watt' => 'Mega Watt',
            'mw' => 'Mega Watt',
            'thousand unit' => 'Thousand Unit',
            'thousand units' => 'Thousand Unit',
        ];

        $normalized = $map[strtolower(trim($uom))] ?? null;
        if ($normalized) return $normalized;

        return $uom;
    }

    private function normalizeSaleType(string $saleType, string $env = 'production'): string
    {
        $sandboxMap = [
            'goods at standard rate' => 'Goods at standard rate',
            'goods at standard rate (default)' => 'Goods at standard rate (default)',
            'goods at standard rate (fmcg)' => 'Goods at standard rate (FMCG)',
            'goods at standard rate (cng)' => 'Goods at standard rate (CNG)',
            'goods at standard rate (wholesale)' => 'Goods at standard rate (wholesale)',
            'goods at standard rate (retail)' => 'Goods at standard rate (retail)',
            'cement /concrete block' => 'Cement /Concrete Block',
            'cement/concrete block' => 'Cement /Concrete Block',
            '3rd schedule (taxable)' => '3rd Schedule Goods',
            '3rd schedule goods' => '3rd Schedule Goods',
            'goods under 3rd schedule' => '3rd Schedule Goods',
            'goods at zero rate' => 'Zero Rated',
            'zero rated' => 'Zero Rated',
            'goods exempt' => 'Exempt',
            'exempt' => 'Exempt',
            'exempt goods' => 'Exempt goods',
            'goods at reduced rate' => 'Goods at Reduced Rate',
            'export of goods' => 'Export',
            'export' => 'Export',
            'services at standard rate' => 'Services',
            'services' => 'Services',
            'steel melting and re-rolling' => 'Steel melting and re-rolling',
            'ship breaking' => 'Ship breaking',
            'cotton ginners' => 'Cotton Ginners',
            'telecommunication services' => 'Telecommunication services',
            'toll manufacturing' => 'Toll Manufacturing',
            'petroleum products' => 'Petroleum Products',
            'electricity supply to retailers' => 'Electricity Supply to Retailers',
            'gas to cng stations' => 'Gas to CNG stations',
            'mobile phones' => 'Mobile Phones',
            'processing/ conversion of goods' => 'Processing/ Conversion of Goods',
            'processing/conversion of goods' => 'Processing/ Conversion of Goods',
            'goods (fed in st mode)' => 'Goods (FED in ST Mode)',
            'services (fed in st mode)' => 'Services (FED in ST Mode)',
            'electric vehicle' => 'Electric Vehicle',
            'potassium chlorate' => 'Potassium Chlorate',
            'cng sales' => 'CNG Sales',
            'goods as per sro.297(|)/2023' => 'Goods as per SRO.297(|)/2023',
            'non-adjustable supplies' => 'Non-Adjustable Supplies',
        ];

        $productionMap = [
            'goods at standard rate' => 'Goods at standard rate (default)',
            'goods at standard rate (default)' => 'Goods at standard rate (default)',
            'goods at standard rate (fmcg)' => 'Goods at standard rate (default)',
            'goods at standard rate (cng)' => 'Goods at standard rate (default)',
            'goods at standard rate (wholesale)' => 'Goods at standard rate (default)',
            'goods at standard rate (retail)' => 'Goods at standard rate (default)',
            'cement /concrete block' => 'Cement/Concrete Block',
            'cement/concrete block' => 'Cement/Concrete Block',
            '3rd schedule (taxable)' => '3rd Schedule Goods',
            '3rd schedule goods' => '3rd Schedule Goods',
            'goods under 3rd schedule' => '3rd Schedule Goods',
            'goods at zero rate' => 'Goods at zero rate',
            'zero rated' => 'Goods at zero rate',
            'goods exempt' => 'Exempt goods',
            'exempt' => 'Exempt goods',
            'exempt goods' => 'Exempt goods',
            'goods at reduced rate' => 'Goods at Reduced Rate',
            'export of goods' => 'Export of goods',
            'export' => 'Export of goods',
            'services at standard rate' => 'Services at standard rate',
            'services' => 'Services at standard rate',
            'steel melting and re-rolling' => 'Steel melting and re-rolling',
            'ship breaking' => 'Ship breaking',
            'cotton ginners' => 'Cotton Ginners',
            'telecommunication services' => 'Telecommunication services',
            'toll manufacturing' => 'Toll Manufacturing',
            'petroleum products' => 'Petroleum Products',
            'electricity supply to retailers' => 'Electricity Supply to Retailers',
            'gas to cng stations' => 'Gas to CNG stations',
            'mobile phones' => 'Mobile Phones',
            'processing/ conversion of goods' => 'Processing/ Conversion of Goods',
            'processing/conversion of goods' => 'Processing/ Conversion of Goods',
            'goods (fed in st mode)' => 'Goods (FED in ST Mode)',
            'services (fed in st mode)' => 'Services (FED in ST Mode)',
            'electric vehicle' => 'Electric Vehicle',
            'potassium chlorate' => 'Potassium Chlorate',
            'cng sales' => 'CNG Sales',
            'goods as per sro.297(|)/2023' => 'Goods as per SRO.297(|)/2023',
            'non-adjustable supplies' => 'Non-Adjustable Supplies',
        ];

        $map = ($env === 'production') ? $productionMap : $sandboxMap;
        $normalized = $map[strtolower(trim($saleType))] ?? null;
        if ($normalized) {
            return $normalized;
        }

        return $saleType;
    }

    private function resolveInvoiceRefNo($invoice): string
    {
        $company = $invoice->company;

        if ($invoice->document_type === 'Debit Note' && !empty($invoice->reference_invoice_number)) {
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

        return $this->buildFbrFormatInvoiceRef($company, $invoice);
    }

    private function buildFbrFormatInvoiceRef($company, $invoice): string
    {
        $regNo = $company->fbr_registration_no ?? $company->ntn ?? '';
        $cleanRegNo = preg_replace('/[^0-9]/', '', $regNo);

        if (strlen($cleanRegNo) === 13) {
            $identifier = $cleanRegNo;
        } elseif (strlen($cleanRegNo) >= 7) {
            $identifier = substr($cleanRegNo, 0, 7);
        } else {
            $identifier = str_pad($cleanRegNo, 7, '0', STR_PAD_LEFT);
        }

        $existingRef = $invoice->internal_invoice_number ?? '';
        if (preg_match('/DI(\d{13})$/', $existingRef, $matches)) {
            $timestamp = $matches[1];
        } else {
            $timestamp = (string)(int)(microtime(true) * 1000);
            $timestamp = substr($timestamp, 0, 13);
        }

        return $identifier . 'DI' . $timestamp;
    }

    private function formatNtnCnic(?string $value): string
    {
        if (empty($value)) return "";
        $clean = preg_replace('/[^0-9]/', '', $value);
        if (strlen($clean) === 13) return $clean;
        if (strlen($clean) >= 7) return substr($clean, 0, 7);
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

    private function sendDirectToFbr(string $url, string $token, string $jsonBody, int $invoiceId): array
    {
        $cookieFile = storage_path('app/fbr_cookies_' . md5($token) . '.txt');

        $attempt = 0;
        $maxAttempts = 2;
        $responseBody = '';
        $httpCode = 0;
        $curlError = '';
        $curlInfo = [];
        $responseHeaders = [];

        while ($attempt < $maxAttempts) {
            $attempt++;
            $responseHeaders = [];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $jsonBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
                CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
                    $len = strlen($header);
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return $len;
                },
                CURLOPT_COOKIEFILE     => $cookieFile,
                CURLOPT_COOKIEJAR      => $cookieFile,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_VERBOSE        => false,
                CURLOPT_USERAGENT      => 'TaxNest/1.0 FBR-DI-Client',
            ]);

            $responseBody = curl_exec($ch);
            $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError    = curl_error($ch);
            $curlInfo     = curl_getinfo($ch);
            curl_close($ch);

            \Log::info("FBR Direct Attempt {$attempt}", [
                'invoice_id' => $invoiceId,
                'http_code' => $httpCode,
                'body_length' => strlen($responseBody ?: ''),
                'response_preview' => substr($responseBody ?: '(empty)', 0, 500),
                'time_sec' => $curlInfo['total_time'] ?? null,
                'has_cookie' => file_exists($cookieFile),
            ]);

            if ($httpCode === 200 && strlen(trim($responseBody ?: '')) > 0) {
                break;
            }

            if ($httpCode === 200 && strlen(trim($responseBody ?: '')) === 0 && $attempt < $maxAttempts) {
                \Log::info("FBR WAF cookie challenge detected, retrying with cookie for invoice #{$invoiceId}");
                usleep(500000);
                continue;
            }

            break;
        }

        return [
            'body' => $responseBody,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response_headers' => $responseHeaders,
            'curl_info' => $curlInfo,
            'attempts' => $attempt,
        ];
    }

    private function sendViaProxy(string $token, string $jsonBody, int $invoiceId, string $action = 'submit'): array
    {
        $proxyUrl = 'https://nestpay.replit.app/api/fbr-proxy/submit';
        $payload = json_decode($jsonBody, true);

        $proxyData = json_encode([
            'token' => $token,
            'action' => $action,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        $responseHeaders = [];
        $ch = curl_init($proxyUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $proxyData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
            ],
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'TaxNest/1.0 FBR-DI-Client',
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        $curlInfo     = curl_getinfo($ch);
        curl_close($ch);

        \Log::info("FBR Proxy Attempt", [
            'invoice_id' => $invoiceId,
            'http_code' => $httpCode,
            'body_length' => strlen($responseBody ?: ''),
            'response_preview' => substr($responseBody ?: '(empty)', 0, 500),
            'time_sec' => $curlInfo['total_time'] ?? null,
        ]);

        return [
            'body' => $responseBody,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response_headers' => $responseHeaders,
            'curl_info' => $curlInfo,
            'attempts' => 1,
        ];
    }

    private function sendToFbr(string $url, string $token, string $jsonBody, int $invoiceId, string $action = 'submit'): array
    {
        $result = $this->sendDirectToFbr($url, $token, $jsonBody, $invoiceId);

        $body = trim($result['body'] ?? '');
        $hasError = !empty($result['curl_error']);
        $emptyResponse = ($result['http_code'] === 200 && strlen($body) === 0);
        $connectionFailed = ($result['http_code'] === 0);

        if ($hasError || $emptyResponse || $connectionFailed) {
            \Log::info("FBR direct failed, falling back to proxy for invoice #{$invoiceId}");
            $result = $this->sendViaProxy($token, $jsonBody, $invoiceId, $action);
        }

        return $result;
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
            $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $result = $this->sendToFbr($url, $token, $jsonBody, $invoice->id);
            $responseBody = $result['body'];
            $httpCode = $result['http_code'];
            $curlError = $result['curl_error'];
            $responseHeaders = $result['response_headers'];
            $curlInfo = $result['curl_info'];

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $log->response_time_ms = $responseTimeMs;
            $log->response_payload = $responseBody ?: '';

            if ($curlError) {
                $log->status = 'failed';
                $log->failure_type = 'connection_error';
                $log->save();
                return [
                    "status" => "failed",
                    "failure_type" => "connection_error",
                    "errors" => ["FBR connection failed: " . $curlError],
                    "response_time_ms" => $responseTimeMs,
                ];
            }

            $response = new class($responseBody, $httpCode) {
                private $body;
                private $status;
                public function __construct($body, $status) { $this->body = $body; $this->status = $status; }
                public function body() { return $this->body; }
                public function json() { return json_decode($this->body, true); }
                public function status() { return $this->status; }
                public function successful() { return $this->status >= 200 && $this->status < 300; }
            };

            $responseData = $response->json();

            if (!$response->successful()) {
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
                    "http_status" => $response->status(),
                ];
            }

            if (!is_array($responseData)) {
                $bodyStr = $response->body();

                if ($response->successful() && strlen(trim($bodyStr)) === 0) {
                    $log->status = 'pending_verification';
                    $log->failure_type = 'ambiguous_response';
                    $log->response_payload = json_encode([
                        'note' => 'FBR returned 200 OK with empty body - status unknown, needs manual verification',
                        'http_code' => $httpCode,
                        'response_headers' => $responseHeaders ?? [],
                        'server_ip' => $curlInfo['primary_ip'] ?? 'unknown',
                        'total_time_sec' => $curlInfo['total_time'] ?? null,
                    ]);
                    $log->save();

                    return [
                        "status" => "pending_verification",
                        "failure_type" => "ambiguous_response",
                        "errors" => ['FBR returned 200 OK but empty response. Invoice may have been accepted. Check FBR portal to verify.'],
                        "response_time_ms" => $responseTimeMs,
                    ];
                }

                $log->status = 'failed';
                $log->failure_type = 'invalid_response';
                $log->save();
                $errorMsg = 'FBR returned unexpected response: ' . substr($bodyStr, 0, 500);
                return [
                    "status" => "failed",
                    "failure_type" => $log->failure_type,
                    "errors" => [$errorMsg],
                    "response_time_ms" => $responseTimeMs,
                ];
            }

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
            }

            $isFbrServerError = false;
            if (isset($responseData['validationResponse'])) {
                $errCode = $responseData['validationResponse']['errorCode'] ?? '';
                $errMsg = $responseData['validationResponse']['error'] ?? '';
                if ($errCode === '500' && stripos($errMsg, 'went wrong') !== false) {
                    $isFbrServerError = true;
                }
            }

            if ($isFbrServerError && $response->successful()) {
                $log->status = 'pending_verification';
                $log->failure_type = 'ambiguous_response';
                $log->response_payload = json_encode([
                    'note' => 'FBR returned error 500 "Something went wrong" - invoice may be accepted, needs manual verification',
                    'original_response' => $responseData,
                ]);
                $log->save();

                return [
                    "status" => "pending_verification",
                    "failure_type" => "ambiguous_response",
                    "errors" => ['FBR returned error 500 but invoice may have been accepted. Check FBR portal to verify.'],
                    "response_time_ms" => $responseTimeMs,
                    "fbr_response" => $responseData,
                ];
            }

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

            $itemInvoiceNumbers = [];
            $itemErrors = [];
            $allItemsValid = true;

            if (!empty($validation['invoiceStatuses']) && is_array($validation['invoiceStatuses'])) {
                foreach ($validation['invoiceStatuses'] as $itemStatus) {
                    $itemInvNo = $itemStatus['invoiceNumber'] ?? $itemStatus['invoiceNo'] ?? null;
                    if ($itemInvNo !== null) {
                        $itemInvoiceNumbers[] = $itemInvNo;
                    }
                    if (($itemStatus['statusCode'] ?? '') === '01') {
                        $allItemsValid = false;
                        if (!empty($itemStatus['error'])) {
                            $errorCode = $itemStatus['errorCode'] ?? '';
                            $itemErrors[] = "Item {$itemStatus['itemSNo']}: [{$errorCode}] {$itemStatus['error']}";
                        }
                    }
                }
            }

            if ($statusCode === '00' && $status === 'valid' && $allItemsValid) {
                return [
                    'valid' => true,
                    'invoiceNumber' => $invoiceNumber ?? ($itemInvoiceNumbers[0] ?? null),
                    'itemInvoiceNumbers' => $itemInvoiceNumbers,
                    'errors' => [],
                ];
            }

            $errors = [];
            $headerErrorCode = $validation['errorCode'] ?? '';
            if (!empty($validation['error'])) {
                $prefix = $headerErrorCode ? "[{$headerErrorCode}] " : '';
                $errors[] = $prefix . $validation['error'];
            }
            $errors = array_merge($errors, $itemErrors);

            return [
                'valid' => false,
                'invoiceNumber' => null,
                'itemInvoiceNumbers' => [],
                'errors' => $errors ?: ['FBR validation failed (statusCode: ' . $statusCode . ', status: ' . $status . ')'],
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

        if (isset($responseData['fault'])) {
            $faultMsg = ($responseData['fault']['message'] ?? 'Unknown') . ': ' . ($responseData['fault']['description'] ?? '');
            return [
                'valid' => false,
                'invoiceNumber' => null,
                'itemInvoiceNumbers' => [],
                'errors' => [$faultMsg],
            ];
        }

        return [
            'valid' => false,
            'invoiceNumber' => null,
            'itemInvoiceNumbers' => [],
            'errors' => ['Unexpected FBR response format: ' . json_encode($responseData)],
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
            $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $result = $this->sendToFbr($validateUrl, $token, $jsonBody, 0);
            $responseBody = $result['body'];
            $httpCode = $result['http_code'];

            $response = new class($responseBody, $httpCode) {
                private $body;
                private $status;
                public function __construct($body, $status) { $this->body = $body; $this->status = $status; }
                public function body() { return $this->body; }
                public function json() { return json_decode($this->body, true); }
                public function status() { return $this->status; }
                public function successful() { return $this->status >= 200 && $this->status < 300; }
            };

            if ($response->successful()) {
                $responseData = $response->json();
                if (!is_array($responseData)) {
                    return [
                        'status' => 'invalid',
                        'message' => "FBR {$env} returned non-JSON response",
                        'errors' => [substr($response->body(), 0, 500)],
                        'payload' => $payload,
                    ];
                }
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
        if ($statusCode === 401 || $statusCode === 402 || $statusCode === 403) {
            return 'token_error';
        }

        if ($statusCode === 422 || $statusCode === 400) {
            return 'validation_error';
        }

        if ($statusCode >= 500) {
            return 'server_error';
        }

        $decoded = json_decode($body, true);
        if ($decoded && isset($decoded['fault'])) {
            $code = $decoded['fault']['code'] ?? '';
            if (in_array($code, ['900901', '900902', '900900'])) {
                return 'token_error';
            }
        }

        return 'payload_error';
    }
}
