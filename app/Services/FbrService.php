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

    // FBR IMS POS Fiscalization (SRO 1279/2021) — separate system from Digital Invoicing.
    // FBR POS bills submit here (Bearer token, IMS invoice model), NOT to the di_data/v1 DI API.
    private const IMS_POS_SANDBOX_URL = 'https://esp.fbr.gov.pk:8244/FBR/v1/api/Live/PostData';
    private const IMS_POS_PRODUCTION_URL = 'https://gw.fbr.gov.pk/imsp/v1/api/Live/PostData';

    private function sanitizeForFbr(?string $text): string
    {
        if (empty($text)) return "";
        $text = preg_replace('/[\n\r\t]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function getExemptSerialNo(?string $hsCode, ?string $fallback = ""): string
    {
        if (empty($hsCode)) return $fallback ?: "";

        $clean = str_replace('.', '', $hsCode);
        $chapter = intval(substr($clean, 0, 2));

        $chapterSerialMap = [
            1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5',
            6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '163',
            11 => '11', 12 => '12', 13 => '13', 14 => '14', 15 => '15',
            16 => '16', 17 => '17', 19 => '18', 20 => '19', 21 => '20',
            22 => '21', 23 => '22', 24 => '23', 25 => '24', 27 => '25',
            28 => '25', 29 => '25', 30 => '26', 31 => '168',
            32 => '28', 33 => '29', 34 => '30', 35 => '31',
            36 => '32', 37 => '33', 38 => '25', 39 => '34',
            40 => '35', 41 => '36', 42 => '37', 43 => '38',
            44 => '39', 47 => '40', 48 => '41', 49 => '42',
            50 => '43', 51 => '44', 52 => '45', 53 => '46',
            54 => '47', 55 => '48', 56 => '49', 57 => '50',
            58 => '51', 59 => '52', 60 => '53', 61 => '54',
            62 => '55', 63 => '56', 64 => '57', 65 => '58',
            68 => '59', 69 => '60', 70 => '61', 71 => '62',
            72 => '63', 73 => '64', 76 => '65', 82 => '66',
            84 => '67', 85 => '68', 87 => '69', 90 => '70',
            94 => '71', 96 => '72', 97 => '73',
        ];

        $hsSpecificMap = [
            '10051000' => '163',
            '31021000' => '168',
            '31051000' => '51',
            '31053000' => '51',
            '38089210' => '133',
            '29302020' => '100',
            '28332940' => '25',
            '28401900' => '25',
        ];

        if (isset($hsSpecificMap[$clean])) {
            return $hsSpecificMap[$clean];
        }

        if (isset($chapterSerialMap[$chapter])) {
            return $chapterSerialMap[$chapter];
        }

        return $fallback ?: "";
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

        $allHsCodes = $invoice->items->pluck('hs_code')->filter()->unique()->values()->toArray();
        $preloadedMappings = [];
        if (!empty($allHsCodes)) {
            try {
                $dbMappings = \Illuminate\Support\Facades\DB::table('hs_code_mappings')
                    ->whereIn('hs_code', $allHsCodes)
                    ->where('is_active', true)
                    ->where('sro_applicable', true)
                    ->orderBy('priority')
                    ->get();
                foreach ($dbMappings as $m) {
                    $key = $m->hs_code . '|' . $m->sale_type;
                    if (!isset($preloadedMappings[$key])) {
                        $preloadedMappings[$key] = $m;
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('HS mapping preload failed: ' . $e->getMessage());
            }
        }

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
                $valueSalesExcludingST = $retailPrice;
                $salesTaxApplicable = round($retailPrice * $taxRate / 100, 2);
            } elseif ($isExempt) {
                $retailPrice = round($unitPrice, 2);
                $salesTaxApplicable = 0.00;
            } elseif ($isReduced) {
                $retailPrice = round($unitPrice, 2);
                $salesTaxApplicable = round(($valueSalesExcludingST * $taxRate) / 100, 2);
            } else {
                $retailPrice = round($unitPrice, 2);
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

            $furtherTax = round(floatval($item->further_tax ?? 0), 2);
            $fedPayable = round(floatval($item->fed_payable ?? 0) * $quantity, 2);
            $discount = round(floatval($item->discount ?? 0) * $quantity, 2);

            $totalValues = round($valueSalesExcludingST + $salesTaxApplicable + floatval($extraTaxVal) + $furtherTax + $fedPayable - $discount, 2);

            if ($isExempt) {
                $rateValue = "Exempt";
            } else {
                $rateNum = ($taxRate == intval($taxRate)) ? intval($taxRate) : round($taxRate, 2);
                $rateValue = $rateNum . '%';
                $rateValue = rtrim(trim($rateValue), '%') . '%';
            }

            $hsCode = $item->hs_code ?? "";
            $uomCode = $this->getUomByHsCode($hsCode, $item->default_uom ?? 'U');

            $itemPayload = [
                "uoM" => $uomCode,
                "rate" => $rateValue,
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
                $serialNo = $item->serial_no ?? "";

                $dbSroMapping = null;
                $saleTypeForLookup = $isExempt ? 'exempt' : ($is3rdSchedule ? '3rd_schedule' : ($isReduced ? 'reduced' : null));
                if ($saleTypeForLookup && $hsCode) {
                    $lookupKey = trim($hsCode) . '|' . $saleTypeForLookup;
                    $dbSroMapping = $preloadedMappings[$lookupKey] ?? null;
                }

                if (!$dbSroMapping && $saleTypeForLookup && $hsCode && config('features.enable_hs_mapping_manager', false)) {
                    \Illuminate\Support\Facades\Log::warning("HS mapping missing", [
                        'invoice_id' => $invoice->id,
                        'hs_code' => $hsCode,
                        'sale_type' => $saleTypeForLookup,
                        'company_id' => $invoice->company_id ?? null,
                        'fallback' => 'static_chapter_map',
                    ]);
                }

                if ($dbSroMapping && $dbSroMapping->sro_number) {
                    $sroValue = $dbSroMapping->sro_number;
                    $serialNo = $dbSroMapping->serial_number_value ?? $serialNo;
                } elseif ($isExempt) {
                    $sroValue = '6th Schd Table I';
                    $serialNo = $this->getExemptSerialNo($hsCode, $item->serial_no);
                } elseif ($is3rdSchedule || stripos($sroValue, '3rd schedule') !== false) {
                    $sroValue = '3rd Schedule goods';
                    $serialNo = $item->serial_no ?? "51";
                } elseif (stripos($sroValue, 'zero') !== false || stripos($sroValue, '5th') !== false) {
                    $sroValue = '5th Schedule';
                    $serialNo = $item->serial_no ?? "";
                } elseif (stripos($sroValue, '8th') !== false || $isReduced) {
                    $sroValue = 'EIGHTH SCHEDULE Table 1';
                    $serialNo = $item->serial_no ?: "1";
                }
                $itemPayload["sroScheduleNo"] = $sroValue;
                $itemPayload["sroItemSerialNo"] = $serialNo;
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
            $payload["scenarioId"] = $this->detectScenarioId($invoice, $payload);
        }

        return $payload;
    }

    /**
     * Smart FBR Scenario detector.
     * Auto-picks correct sandbox scenarioId based on first-item characteristics
     * (3rd Schedule / Reduced / Exempt / Steel / Standard) + buyer registration.
     *
     * Override priority:
     *   1. $invoice->fbr_scenario_id (explicit invoice-level override)
     *   2. $invoice->items[0]->fbr_scenario_id (explicit item-level override)
     *   3. Auto-detection (this method)
     *
     * Mapping (per FBR Excel scenario list):
     *   SN001 — Goods at Standard Rate / Registered buyer
     *   SN002 — Goods at Standard Rate / Unregistered buyer
     *   SN003 — Steel Melting & Re-Rolling (HS 7204/7213/7214/7227/7228)
     *   SN006 — Goods at Standard Rate (default) / Unregistered (legacy)
     *   SN007 — Exempt Goods
     *   SN008 — 3rd Schedule Goods / Registered
     *   SN026 — Goods at Standard Rate / End Consumer
     *   SN027 — 3rd Schedule Goods / End Consumer
     *   SN028 — Goods at Reduced Rate (8th Schedule)
     */
    private function detectScenarioId($invoice, array $payload): string
    {
        if (!empty($invoice->fbr_scenario_id)) {
            return strtoupper(trim($invoice->fbr_scenario_id));
        }

        $firstItem = $invoice->items->first();
        if ($firstItem && !empty($firstItem->fbr_scenario_id)) {
            return strtoupper(trim($firstItem->fbr_scenario_id));
        }

        if (!$firstItem) {
            return 'SN001';
        }

        $saleType = strtolower($firstItem->sale_type ?? '');
        $scheduleType = strtolower($firstItem->schedule_type ?? 'standard');
        $hsCode = trim($firstItem->hs_code ?? '');
        $taxRate = floatval($firstItem->tax_rate ?? 18);
        $buyerRegType = strtolower($payload['buyerRegistrationType'] ?? 'unregistered');
        $isRegistered = ($buyerRegType === 'registered');
        $buyerNtn = trim($invoice->buyer_ntn ?? '');
        $buyerCnic = trim($invoice->buyer_cnic ?? '');
        $isEndConsumer = !$isRegistered && empty($buyerNtn);

        $is3rdSchedule = (strpos($saleType, '3rd schedule') !== false || $scheduleType === 'third_schedule');
        $isExempt = (strpos($saleType, 'exempt') !== false || $scheduleType === 'exempt');
        $isReduced = (strpos($saleType, 'reduced') !== false || $scheduleType === 'reduced' || $scheduleType === '8th_schedule');
        $isSteel = $this->isSteelHsCode($hsCode);

        if ($isExempt) {
            return 'SN007';
        }

        if ($isReduced) {
            return 'SN028';
        }

        if ($isSteel) {
            return 'SN003';
        }

        if ($is3rdSchedule) {
            return $isRegistered ? 'SN008' : 'SN027';
        }

        if ($isRegistered) {
            return 'SN001';
        }

        return $isEndConsumer ? 'SN026' : 'SN002';
    }

    /**
     * Smart scenario detector for FBR POS transactions.
     * Same logic as detectScenarioId() but adapted to FbrPosTransaction model
     * (uses customer_* fields and items collection from POS transaction).
     */
    private function detectScenarioIdForFbrPos($transaction, array $payload): string
    {
        if (!empty($transaction->fbr_scenario_id)) {
            return strtoupper(trim($transaction->fbr_scenario_id));
        }

        $items = $transaction->items ?? collect();
        $firstItem = is_object($items) && method_exists($items, 'first') ? $items->first() : ($items[0] ?? null);

        if ($firstItem && !empty($firstItem->fbr_scenario_id)) {
            return strtoupper(trim($firstItem->fbr_scenario_id));
        }

        if (!$firstItem) {
            $buyerNtn = trim($transaction->customer_ntn ?? '');
            return !empty($buyerNtn) ? 'SN001' : 'SN002';
        }

        $saleType = strtolower($firstItem->sale_type ?? '');
        $scheduleType = strtolower($firstItem->schedule_type ?? 'standard');
        $hsCode = trim($firstItem->hs_code ?? '');
        $buyerRegType = strtolower($payload['buyerRegistrationType'] ?? 'unregistered');
        $isRegistered = ($buyerRegType === 'registered');
        $buyerNtn = trim($transaction->customer_ntn ?? '');
        $isEndConsumer = !$isRegistered && empty($buyerNtn);

        $is3rdSchedule = (strpos($saleType, '3rd schedule') !== false || $scheduleType === 'third_schedule');
        $isExempt = (strpos($saleType, 'exempt') !== false || $scheduleType === 'exempt');
        $isReduced = (strpos($saleType, 'reduced') !== false || $scheduleType === 'reduced' || $scheduleType === '8th_schedule');
        $isSteel = $this->isSteelHsCode($hsCode);

        if ($isExempt) return 'SN007';
        if ($isReduced) return 'SN028';
        if ($isSteel) return 'SN003';
        if ($is3rdSchedule) return $isRegistered ? 'SN008' : 'SN027';
        if ($isRegistered) return 'SN001';
        return $isEndConsumer ? 'SN026' : 'SN002';
    }

    /**
     * Steel sector HS codes (Chapter 72: Iron & Steel - melted/re-rolled)
     */
    private function isSteelHsCode(string $hsCode): bool
    {
        if (empty($hsCode)) return false;
        $hs = preg_replace('/[^0-9]/', '', $hsCode);
        if (strlen($hs) < 4) return false;
        $chapter = substr($hs, 0, 2);
        $heading = substr($hs, 0, 4);
        if ($chapter !== '72') return false;
        return in_array($heading, ['7204', '7213', '7214', '7227', '7228'], true);
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
                $errors[] = ['code' => '0079', 'message' => "Item #{$sn}: Value exceeds PKR 20,000 - 5% rate is not allowed for values above this threshold."];
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

        $internalNumber = $invoice->internal_invoice_number ?? $invoice->invoice_number ?? (string) $invoice->id;
        $cleanNumber = preg_replace('/[^A-Za-z0-9]/', '', $internalNumber);

        if (str_starts_with($cleanNumber, $identifier)) {
            return $cleanNumber;
        }

        return $identifier . 'DI' . $cleanNumber;
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
            $plain = Crypt::decryptString($encryptedToken);
            if (strlen($plain) < 8 || strlen($plain) > 512) {
                \Log::error("FBR token decrypt produced suspicious length", [
                    'company_id' => $company->id ?? null,
                    'env' => $env,
                    'plain_length' => strlen($plain),
                ]);
            }
            return $plain;
        } catch (\Exception $e) {
            \Log::error("FBR token decrypt FAILED — APP_KEY mismatch or corrupted token. Refusing to send raw encrypted blob to FBR.", [
                'company_id' => $company->id ?? null,
                'env' => $env,
                'token_prefix' => substr($encryptedToken, 0, 12),
                'token_length' => strlen($encryptedToken),
                'error' => $e->getMessage(),
            ]);
            try {
                \DB::table('companies')->where('id', $company->id)->update(['fbr_connection_status' => 'red']);
            } catch (\Throwable $te) {}
            return '';
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
        $maxAttempts = 5;
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
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                    'Connection: keep-alive',
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
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_VERBOSE        => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_ENCODING       => '',
            ]);

            $responseBody = curl_exec($ch);
            $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError    = curl_error($ch);
            $curlInfo     = curl_getinfo($ch);
            curl_close($ch);

            \Log::info("FBR Direct Attempt {$attempt}/{$maxAttempts}", [
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
                $delay = $attempt * 1000000;
                \Log::info("FBR WAF challenge detected, retry #{$attempt} with {$delay}us delay for invoice #{$invoiceId}");
                usleep($delay);
                continue;
            }

            if ($httpCode === 0 && $attempt < $maxAttempts) {
                \Log::info("FBR connection failed, retry #{$attempt} for invoice #{$invoiceId}");
                usleep(2000000);
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

    private function sendToFbr(string $url, string $token, string $jsonBody, int $invoiceId, string $action = 'submit'): array
    {
        $result = $this->sendDirectToFbr($url, $token, $jsonBody, $invoiceId);

        $body = trim($result['body'] ?? '');
        if ($result['http_code'] === 200 && strlen($body) === 0) {
            \Log::warning("FBR returned empty response after all retries for invoice #{$invoiceId}. WAF may be blocking.");
        }

        return $result;
    }

    public function submitInvoice($invoice, int $retryCount = 0)
    {
        if (
            $invoice->fbr_invoice_number ||
            $invoice->status === 'locked' ||
            $invoice->status === 'pending_verification' ||
            $invoice->fbr_status === 'pending_verification' ||
            FbrLog::where('invoice_id', $invoice->id)->where('status', 'success')->exists()
        ) {
            $reason = $invoice->fbr_invoice_number
                ? "already has FBR number {$invoice->fbr_invoice_number}"
                : ($invoice->status === 'locked' ? 'invoice is locked'
                : ($invoice->status === 'pending_verification' || $invoice->fbr_status === 'pending_verification'
                    ? 'pending FBR verification' : 'previous success in fbr_logs'));

            \Log::critical("IDEMPOTENCY BLOCKED: Invoice #{$invoice->id} — {$reason}");
            throw new \Exception("FBR submission blocked: {$reason}. Invoice #{$invoice->id}");
        }

        if (!empty($invoice->fbr_submission_hash)) {
            \Log::critical("IDEMPOTENCY BLOCKED: Invoice #{$invoice->id} — submission hash already set: {$invoice->fbr_submission_hash}");
            throw new \Exception("FBR submission blocked: submission hash lock exists. Invoice #{$invoice->id}");
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($invoice, $retryCount) {
            $invoice = \App\Models\Invoice::where('id', $invoice->id)->lockForUpdate()->first();

            if (!$invoice) {
                throw new \Exception("FBR submission blocked: invoice not found during lock.");
            }

            if (
                $invoice->fbr_invoice_number ||
                $invoice->status === 'locked' ||
                $invoice->status === 'pending_verification' ||
                $invoice->fbr_submission_hash ||
                FbrLog::where('invoice_id', $invoice->id)->where('status', 'success')->exists()
            ) {
                \Log::critical("IDEMPOTENCY BLOCKED (inside transaction): Invoice #{$invoice->id}");
                throw new \Exception("FBR submission blocked: race condition guard triggered. Invoice #{$invoice->id}");
            }

            $invoiceRefNo = $this->resolveInvoiceRefNo($invoice);
            $submissionHash = hash('sha256', $invoice->id . '|' . $invoiceRefNo);

            $invoice->fbr_submission_hash = $submissionHash;
            $invoice->save();

            $payload = $this->buildPayload($invoice);
            $company = $invoice->company;

        $clearHashOnFailure = function () use ($invoice) {
            $invoice->fbr_submission_hash = null;
            $invoice->save();
        };

        if (empty($payload['items'])) {
            $clearHashOnFailure();
            \Log::info("FBR submission skipped: Invoice #{$invoice->id} — all items are tax-exempt. Locking internally.");
            $invoice->status = 'locked';
            $invoice->fbr_status = 'exempt_internal';
            $invoice->fbr_submission_hash = null;
            $invoice->save();
            return [
                'status' => 'success',
                'message' => 'Invoice locked internally — all items are tax-exempt, not reported to FBR.',
                'fbr_invoice_number' => null,
                'exempt_only' => true,
            ];
        }

        $payloadErrors = ScheduleEngine::validateFbrPayload($payload);
        if (!empty($payloadErrors)) {
            $clearHashOnFailure();
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

        $schemaErrors = [];
        foreach ($payload['items'] as $idx => $pItem) {
            $itemNum = $idx + 1;
            if (!is_string($pItem['rate'] ?? null) || (!str_ends_with($pItem['rate'], '%') && $pItem['rate'] !== '-' && $pItem['rate'] !== 'Exempt')) {
                $schemaErrors[] = "Item {$itemNum}: rate must be string ending with '%', '-', or 'Exempt', got: " . json_encode($pItem['rate'] ?? null);
            }
            if (!is_numeric($pItem['quantity'] ?? null)) {
                $schemaErrors[] = "Item {$itemNum}: quantity must be numeric";
            }
            if (!is_numeric($pItem['fixedNotifiedValueOrRetailPrice'] ?? null)) {
                $schemaErrors[] = "Item {$itemNum}: fixedNotifiedValueOrRetailPrice must be numeric";
            }
            if (!is_numeric($pItem['salesTaxApplicable'] ?? null)) {
                $schemaErrors[] = "Item {$itemNum}: salesTaxApplicable must be numeric";
            }
            if (!is_numeric($pItem['valueSalesExcludingST'] ?? null)) {
                $schemaErrors[] = "Item {$itemNum}: valueSalesExcludingST must be numeric";
            }
            if (!is_numeric($pItem['totalValues'] ?? null)) {
                $schemaErrors[] = "Item {$itemNum}: totalValues must be numeric";
            }
        }
        if (!empty($schemaErrors)) {
            $clearHashOnFailure();
            $log = FbrLog::create([
                'invoice_id' => $invoice->id,
                'request_payload' => json_encode($payload),
                'status' => 'failed',
                'response_payload' => json_encode(['schema_errors' => $schemaErrors]),
                'response_time_ms' => 0,
                'retry_count' => $retryCount,
            ]);
            $log->failure_type = 'schema_error';
            $log->save();

            return [
                'status' => 'failed',
                'failure_type' => 'schema_error',
                'errors' => $schemaErrors,
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
            $clearHashOnFailure();
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

            \Log::info("FBR Payload for Invoice #{$invoice->id}", [
                'payload_json' => $jsonBody,
                'url' => $url,
            ]);

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
                $clearHashOnFailure();
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
                $clearHashOnFailure();
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

                $clearHashOnFailure();
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

            $clearHashOnFailure();
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
            $clearHashOnFailure();
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
            $clearHashOnFailure();
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
        });
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

    /**
     * Build the FBR IMS POS Fiscalization payload (SRO 1279/2021).
     * This is the IMS invoice model, NOT the DI di_data/v1 model.
     */
    public function buildFbrPosPayload(\App\Models\FbrPosTransaction $transaction): array
    {
        $company = $transaction->company;

        // ── RETURN / CREDIT NOTE (Aug 2026 — Retail Core) ────────────────────────
        // FBR IMS spec (SRO 1279/2021): a refund invoice carries InvoiceType=3,
        // RefUSIN = the ORIGINAL bill's USIN, and NEGATIVE quantities/amounts.
        // transaction_type='return' rows reference their parent via parent_transaction_id.
        $isReturn = ($transaction->transaction_type ?? 'sale') === 'return';
        $sign = $isReturn ? -1 : 1;
        $refUsin = null;
        $invoiceType = 1;
        if ($isReturn) {
            $invoiceType = 3;
            $parent = $transaction->parent_transaction_id
                ? \App\Models\FbrPosTransaction::find($transaction->parent_transaction_id)
                : null;
            // RefUSIN must be the original USIN — without it FBR rejects the credit note.
            $refUsin = $parent?->invoice_number;
        }

        $items = [];
        $totalSaleValue = 0.0;
        $totalTaxCharged = 0.0;
        $totalQuantity = 0.0;
        $index = 0;

        foreach ($transaction->items as $item) {
            $index++;
            $quantity = $sign * round(floatval($item->quantity), 4);
            $isExempt = (bool) $item->is_tax_exempt;
            $taxRate = $isExempt ? 0.0 : floatval($item->tax_rate);

            // Use the STORED fiscal snapshots — these are already correct for BOTH tax-inclusive
            // and tax-exclusive cart modes (see FbrPosController::store). `subtotal` = net taxable
            // value (excl tax, after this line's item discount); `tax_amount` = tax on that value.
            // Do NOT re-derive from unit_price — that breaks tax-inclusive bills.
            $saleValue  = $sign * round(floatval($item->subtotal), 2);
            $taxCharged = $isExempt ? 0.00 : $sign * round(floatval($item->tax_amount), 2);
            $itemDiscount = $sign * round(floatval($item->item_discount ?? 0), 2);
            $totalAmount = round($saleValue + $taxCharged, 2); // = stored `total` (negated for returns)

            $items[] = [
                'ItemCode'    => (string) ($item->product_id ?: ('IT-' . $index)),
                'ItemName'    => $this->sanitizeForFbr($item->item_name),
                'Quantity'    => (float) $quantity,
                // PCTCode is COMPULSORY in the FBR IMS invoice model (FBR help article,
                // varchar(8)) — the local FBRIMS component rejects an empty string with
                // "Model validation failed." (proven live, X-WAY SHOES Aug 2026). Products
                // without an HS code fall back to the all-zeros code, exactly like the
                // proven-working PRA fiscal-device payload ('00000000').
                'PCTCode'     => $this->sanitizePctCode($item->hs_code ?? '') ?: '00000000',
                'TaxRate'     => (float) $taxRate,
                'SaleValue'   => (float) $saleValue,
                'TotalAmount' => (float) $totalAmount,
                'TaxCharged'  => (float) $taxCharged,
                'Discount'    => (float) $itemDiscount,
                'FurtherTax'  => 0.00,
                'InvoiceType' => $invoiceType,
                'RefUSIN'     => $refUsin,
            ];

            $totalSaleValue  += $saleValue;
            $totalTaxCharged += $taxCharged;
            $totalQuantity   += $quantity;
        }

        $totalSaleValue  = round($totalSaleValue, 2);
        $totalTaxCharged = round($totalTaxCharged, 2);
        $totalQuantity   = round($totalQuantity, 4);

        // Bill-level discount (manual + promotion) is applied POST-tax in store() and stored on the
        // transaction. Item SaleValues are already net of their own item discounts, so the header
        // Discount carries ONLY this bill-level amount (avoids double-subtraction). FBR IMS header rule:
        // TotalBillAmount = TotalSaleValue + TotalTaxCharged - Discount.
        $billDiscount = $sign * round(floatval($transaction->discount_amount ?? 0), 2);

        // Fiscal goods total = net sale + tax - bill discount. This equals exactly what the customer
        // pays for goods. The app-only Rs 1 FBR service fee and loyalty redemption are deliberately
        // EXCLUDED — they are not part of the fiscal goods total.
        $totalBillAmount = round($totalSaleValue + $totalTaxCharged - $billDiscount, 2);

        // Buyer identifier: 13 digits => CNIC, otherwise NTN. Both optional (walk-in = blank).
        $custDigits = preg_replace('/[^0-9]/', '', $transaction->customer_ntn ?? '');
        $buyerNtn = '';
        $buyerCnic = '';
        if (strlen($custDigits) === 13) {
            $buyerCnic = $custDigits;
        } elseif (strlen($custDigits) >= 7) {
            $buyerNtn = $custDigits;
        }

        return [
            'InvoiceNumber'    => '',
            'POSID'            => (int) preg_replace('/[^0-9]/', '', (string) ($company->fbr_pos_id ?? '')),
            'USIN'             => (string) $transaction->invoice_number,
            'DateTime'         => $transaction->created_at->format('Y-m-d H:i:s'),
            'BuyerNTN'         => $buyerNtn,
            'BuyerCNIC'        => $buyerCnic,
            'BuyerName'        => $this->sanitizeForFbr($transaction->customer_name ?? ''),
            'BuyerPhoneNumber' => $transaction->customer_phone ?? '',
            'TotalSaleValue'   => (float) $totalSaleValue,
            'TotalTaxCharged'  => (float) $totalTaxCharged,
            'TotalQuantity'    => (float) $totalQuantity,
            'Discount'         => (float) $billDiscount,
            'FurtherTax'       => 0.00,
            'TotalBillAmount'  => (float) $totalBillAmount,
            'PaymentMode'      => $this->mapPaymentModeToImsInt($transaction->payment_method),
            'RefUSIN'          => $refUsin,
            'InvoiceType'      => $invoiceType,
            'Items'            => $items,
        ];
    }

    /** Strip non-digits from an HS code and cap at 8 for the IMS PCTCode field. */
    private function sanitizePctCode(?string $hsCode): string
    {
        $digits = preg_replace('/[^0-9]/', '', $hsCode ?? '');
        return substr($digits, 0, 8);
    }

    /** Map a stored payment_method string to the FBR IMS PaymentMode integer. */
    private function mapPaymentModeToImsInt(?string $method): int
    {
        // IMS PaymentMode: 1=Cash, 2=Card, 3=Gift Voucher, 4=Loyalty Card, 5=Mixed, 6=Cheque.
        switch (strtolower(trim($method ?? 'cash'))) {
            case 'cash':          return 1;
            case 'card':          return 2;
            case 'bank_transfer': // electronic → Card (closest IMS slot)
            case 'online':        return 2;
            case 'cheque':
            case 'check':         return 6;
            case 'mixed':         return 5;
            // Udhaar/Khata (Aug 2026 — Retail Core): IMS has no credit-sale slot;
            // report as Cash (1) — the fiscal liability is identical, wasooli is
            // an internal ledger matter, not an FBR event.
            case 'credit':
            case 'udhaar':        return 1;
            // Return refunded into khata — same rationale.
            case 'khata':         return 1;
            case 'store_credit':  return 1;
            default:
                Log::warning("FBR IMS POS: unmapped payment_method '{$method}', defaulting to Cash (1).");
                return 1;
        }
    }

    /**
     * Parse an FBR IMS POS response. Success = Code "100" with an invoice number
     * in InvoiceNumber or FBRInvoiceNumber; otherwise collect Response/Errors.
     */
    private function parseFbrPosImsResponse(array $responseData): array
    {
        $code = (string) ($responseData['Code'] ?? $responseData['code'] ?? '');
        $invoiceNumber = $responseData['InvoiceNumber']
            ?? $responseData['FBRInvoiceNumber']
            ?? $responseData['invoiceNumber']
            ?? null;

        if ($code === '100' && !empty($invoiceNumber)) {
            return [
                'valid' => true,
                'invoiceNumber' => (string) $invoiceNumber,
                'errors' => [],
            ];
        }

        $errors = [];
        $msg = $responseData['Response'] ?? $responseData['response'] ?? null;
        if (!empty($msg)) {
            $errors[] = $code !== '' ? "[{$code}] {$msg}" : (string) $msg;
        }
        $errObj = $responseData['Errors'] ?? $responseData['errors'] ?? null;
        if (!empty($errObj)) {
            if (is_array($errObj)) {
                foreach ($errObj as $e) {
                    $errors[] = is_array($e) ? json_encode($e) : (string) $e;
                }
            } else {
                $errors[] = (string) $errObj;
            }
        }
        if (empty($errors)) {
            $errors[] = 'FBR IMS rejected the invoice (Code: ' . ($code ?: 'unknown') . '). ' . json_encode($responseData);
        }

        return [
            'valid' => false,
            'invoiceNumber' => null,
            'errors' => $errors,
        ];
    }

    private function getFbrPosToken($company): string
    {
        // FBR IMS POS uses its OWN dedicated token. There is NO fallback to DI tokens:
        // a DI token is not authorized on the IMS endpoint and would reproduce the
        // "900908 Resource forbidden" failure with a confusing, misleading error.
        if (empty($company->fbr_pos_token)) {
            Log::warning("FBR IMS POS: No dedicated POS token configured for company #{$company->id}");
            return '';
        }

        try {
            // trim() defends against copy-paste whitespace/newlines in the pasted token,
            // which would make the Bearer header malformed → FBR "900901 Invalid Credentials".
            return trim(Crypt::decryptString($company->fbr_pos_token));
        } catch (\Exception $e) {
            Log::error("FBR IMS POS token decrypt FAILED — APP_KEY mismatch. Refusing to send raw blob to FBR.", [
                'company_id' => $company->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    private function getFbrPosUrl($company): string
    {
        // FBR IMS POS Fiscalization endpoints (NOT the DI di_data/v1 API, and NOT the
        // DI-only fbr_production_url/fbr_sandbox_url company overrides).
        $env = $company->fbr_pos_environment ?? $company->fbr_environment ?? 'sandbox';
        return $env === 'production' ? self::IMS_POS_PRODUCTION_URL : self::IMS_POS_SANDBOX_URL;
    }

    public function submitFbrPosTransaction(\App\Models\FbrPosTransaction $transaction): array
    {
        $company = $transaction->company;

        if ($transaction->fbr_invoice_number || $transaction->fbr_status === 'submitted') {
            return [
                'status' => 'blocked',
                'errors' => ['Transaction already submitted to FBR: ' . ($transaction->fbr_invoice_number ?? 'submitted')],
            ];
        }

        // FISCAL DEVICE MODE (central guard) — FBR retired cloud bulk PostData (Code 112).
        // In fiscal_device mode the server NEVER direct-submits: the bill is queued 'pending' and
        // the Desktop Sync Agent POSTs it to the LOCAL FBR IMS component (localhost:8524) on the
        // shop PC. This guard sits BEFORE the hash-lock so a stale hash can never strand the bill,
        // and it covers EVERY call site (store, retryFbr, editRetry, retry/sync jobs).
        if ($company && $company->agentServesFbr() && $company->agent_enabled) {
            $transaction->update(['fbr_status' => 'pending']);
            return [
                'status' => 'queued_agent',
                'message' => 'Queued for Desktop Sync Agent (Fiscal Device mode).',
            ];
        }

        if (!empty($transaction->fbr_submission_hash)) {
            return [
                'status' => 'blocked',
                'errors' => ['Transaction submission already in progress (hash lock exists).'],
            ];
        }

        $submissionHash = hash('sha256', $transaction->id . '|' . $transaction->invoice_number . '|' . now()->timestamp);
        $transaction->fbr_submission_hash = $submissionHash;
        $transaction->save();

        $clearHashOnFailure = function () use ($transaction) {
            $transaction->fbr_submission_hash = null;
            $transaction->save();
        };

        $payload = $this->buildFbrPosPayload($transaction);

        // IMS mandatory-field guards — fail with a clear message instead of an opaque FBR rejection.
        if (empty($payload['POSID'])) {
            $clearHashOnFailure();
            \App\Models\FbrPosLog::create([
                'company_id' => $company->id,
                'transaction_id' => $transaction->id,
                'request_payload' => $payload,
                'status' => 'failed',
                'error_message' => 'FBR POS Registration ID (POSID) not configured. Set it in FBR Settings.',
            ]);
            $transaction->update(['fbr_status' => 'failed']);
            return [
                'status' => 'failed',
                'errors' => ['FBR POS Registration ID not set. Add it in FBR Settings before submitting.'],
            ];
        }

        // NOTE: PCTCode (HS code) is OPTIONAL for FBR IMS POS Fiscalization (SRO 1279/2021),
        // unlike Digital Invoicing where hsCode is mandatory. Retail POS items often have no
        // HS code, so we send PCTCode when available and blank otherwise — never block the bill.

        $posEnv = $company->fbr_pos_environment ?? $company->fbr_environment ?? 'sandbox';
        $token = $this->getFbrPosToken($company);
        $url = $this->getFbrPosUrl($company);
        $tokenSource = !empty($company->fbr_pos_token) ? 'dedicated_ims_pos_token' : 'none';

        Log::info("FBR IMS POS Auth: Company #{$company->id}", [
            'pos_environment' => $posEnv,
            'token_source' => $tokenSource,
            'token_present' => !empty($token),
            'token_preview' => !empty($token) ? substr($token, 0, 8) . '...' . substr($token, -4) : 'NONE',
            'api_url' => $url,
        ]);

        if (empty($token)) {
            $clearHashOnFailure();
            \App\Models\FbrPosLog::create([
                'company_id' => $company->id,
                'transaction_id' => $transaction->id,
                'request_payload' => $payload,
                'status' => 'failed',
                'error_message' => 'FBR token not configured. Set up FBR credentials in company settings.',
            ]);
            $transaction->update(['fbr_status' => 'failed']);
            return [
                'status' => 'failed',
                'errors' => ['FBR token not configured for this company.'],
            ];
        }

        $log = \App\Models\FbrPosLog::create([
            'company_id' => $company->id,
            'transaction_id' => $transaction->id,
            'request_payload' => $payload,
            'status' => 'pending',
        ]);

        $startTime = microtime(true);

        try {
            $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

            Log::info("FBR POS Payload for Transaction #{$transaction->id}", [
                'payload_json' => $jsonBody,
                'url' => $url,
            ]);

            $result = $this->sendDirectToFbr($url, $token, $jsonBody, $transaction->id);
            $responseBody = $result['body'];
            $httpCode = $result['http_code'];
            $curlError = $result['curl_error'];
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($curlError) {
                $clearHashOnFailure();
                $log->update([
                    'status' => 'failed',
                    'response_payload' => ['curl_error' => $curlError],
                    'error_message' => 'FBR connection failed: ' . $curlError,
                    'response_code' => '0',
                ]);
                $transaction->update(['fbr_status' => 'failed']);
                return [
                    'status' => 'failed',
                    'errors' => ['FBR connection failed: ' . $curlError],
                ];
            }

            $responseData = json_decode($responseBody, true);

            if ($httpCode < 200 || $httpCode >= 300) {
                $clearHashOnFailure();
                $errors = is_array($responseData) ? $this->extractErrorsFromResponse($responseBody) : [$responseBody];
                $log->update([
                    'status' => 'failed',
                    'response_payload' => $responseData ?? ['raw' => substr($responseBody, 0, 2000)],
                    'response_code' => (string) $httpCode,
                    'error_message' => implode('; ', $errors),
                ]);
                $transaction->update(['fbr_status' => 'failed']);
                return [
                    'status' => 'failed',
                    'errors' => $errors,
                    'http_status' => $httpCode,
                ];
            }

            if (!is_array($responseData)) {
                if ($httpCode === 200 && strlen(trim($responseBody ?? '')) === 0) {
                    $log->update([
                        'status' => 'failed',
                        'response_code' => '200',
                        'error_message' => 'FBR returned empty 200 response (WAF challenge). Retry later.',
                    ]);
                    $clearHashOnFailure();
                    $transaction->update(['fbr_status' => 'pending']);
                    return [
                        'status' => 'retry',
                        'errors' => ['FBR returned empty response. May need retry.'],
                    ];
                }
                $clearHashOnFailure();
                $log->update([
                    'status' => 'failed',
                    'response_code' => (string) $httpCode,
                    'error_message' => 'FBR returned non-JSON: ' . substr($responseBody, 0, 500),
                ]);
                $transaction->update(['fbr_status' => 'failed']);
                return [
                    'status' => 'failed',
                    'errors' => ['FBR returned unexpected response format.'],
                ];
            }

            $fbrResult = $this->parseFbrPosImsResponse($responseData);

            if ($fbrResult['valid']) {
                $fbrInvoiceNumber = $fbrResult['invoiceNumber'];
                $transaction->update([
                    'fbr_invoice_number' => $fbrInvoiceNumber,
                    'fbr_status' => 'submitted',
                    'fbr_response_code' => '100',
                    'fbr_response' => $responseData,
                ]);
                $log->update([
                    'status' => 'success',
                    'response_payload' => $responseData,
                    'response_code' => '100',
                ]);

                Log::info("FBR IMS POS Transaction #{$transaction->id} submitted successfully", [
                    'fbr_invoice_number' => $fbrInvoiceNumber,
                    'response_time_ms' => $responseTimeMs,
                ]);

                return [
                    'status' => 'success',
                    'fbr_invoice_number' => $fbrInvoiceNumber,
                    'fbr_response' => $responseData,
                ];
            }

            $clearHashOnFailure();
            $log->update([
                'status' => 'failed',
                'response_payload' => $responseData,
                'response_code' => (string) ($responseData['Code'] ?? 'unknown'),
                'error_message' => implode('; ', $fbrResult['errors']),
            ]);
            $transaction->update([
                'fbr_status' => 'failed',
                'fbr_response' => $responseData,
            ]);

            return [
                'status' => 'failed',
                'errors' => $fbrResult['errors'],
                'fbr_response' => $responseData,
            ];

        } catch (\Exception $e) {
            $clearHashOnFailure();
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $transaction->update(['fbr_status' => 'failed']);

            Log::error("FBR POS submission exception for Transaction #{$transaction->id}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'errors' => [$e->getMessage()],
            ];
        }
    }
}
