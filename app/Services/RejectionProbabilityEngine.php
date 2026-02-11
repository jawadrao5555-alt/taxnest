<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Company;
use App\Models\FbrLog;
use Carbon\Carbon;

class RejectionProbabilityEngine
{
    public static function simulate(Invoice $invoice): array
    {
        $invoice->load('items', 'company');
        $company = $invoice->company;
        $checks = [];
        $totalPenalty = 0;
        $criticalViolation = false;
        $standardRate = $company ? $company->getStandardTaxRateValue() : 18.0;

        foreach ($invoice->items as $index => $item) {
            $scheduleType = $item->schedule_type ?? 'standard';
            $taxRate = $item->tax_rate ?? $standardRate;
            $rules = ScheduleEngine::resolveValidationRules($scheduleType, $taxRate, $standardRate);

            if ($rules['requires_sro'] && empty($item->sro_schedule_no)) {
                $checks[] = [
                    'type' => 'missing_sro',
                    'severity' => 'high',
                    'message' => "Item #{$index}: Missing SRO for {$scheduleType}",
                    'penalty' => 15,
                ];
                $totalPenalty += 15;
            }

            if ($rules['requires_serial'] && empty($item->serial_no)) {
                $checks[] = [
                    'type' => 'missing_serial',
                    'severity' => 'medium',
                    'message' => "Item #{$index}: Missing Serial No for {$scheduleType}",
                    'penalty' => 10,
                ];
                $totalPenalty += 10;
            }

            if ($rules['requires_mrp'] && (empty($item->mrp) || $item->mrp <= 0)) {
                $checks[] = [
                    'type' => 'missing_mrp',
                    'severity' => 'medium',
                    'message' => "Item #{$index}: Missing MRP for 3rd Schedule",
                    'penalty' => 10,
                ];
                $totalPenalty += 10;
            }

            if ($scheduleType === 'reduced' && $taxRate >= $standardRate) {
                $checks[] = [
                    'type' => 'invalid_reduced_rate',
                    'severity' => 'high',
                    'message' => "Item #{$index}: Reduced rate ({$taxRate}%) is not below standard ({$standardRate}%)",
                    'penalty' => 20,
                ];
                $totalPenalty += 20;
                $criticalViolation = true;
            }

            $hsLookup = ScheduleEngine::$hsLookupTable[$item->hs_code] ?? null;
            if ($hsLookup && $hsLookup['schedule_type'] !== $scheduleType) {
                $checks[] = [
                    'type' => 'hs_schedule_mismatch',
                    'severity' => 'medium',
                    'message' => "Item #{$index}: HS code suggests '{$hsLookup['schedule_type']}' but '{$scheduleType}' selected",
                    'penalty' => 12,
                ];
                $totalPenalty += 12;
            }
        }

        if (empty($invoice->supplier_province) && empty($company->province)) {
            $checks[] = [
                'type' => 'province_mismatch',
                'severity' => 'medium',
                'message' => 'Supplier province not configured',
                'penalty' => 10,
            ];
            $totalPenalty += 10;
        }

        if (empty($invoice->destination_province)) {
            $checks[] = [
                'type' => 'province_mismatch',
                'severity' => 'low',
                'message' => 'Destination province not specified',
                'penalty' => 5,
            ];
            $totalPenalty += 5;
        }

        if ($company) {
            $tokenExpiry = $company->token_expiry_date;
            if ($tokenExpiry && Carbon::parse($tokenExpiry)->isBefore(now()->addDays(7))) {
                $daysLeft = Carbon::parse($tokenExpiry)->diffInDays(now(), false);
                if ($daysLeft >= 0) {
                    $checks[] = [
                        'type' => 'token_expired',
                        'severity' => 'critical',
                        'message' => 'FBR token has expired',
                        'penalty' => 25,
                    ];
                    $totalPenalty += 25;
                    $criticalViolation = true;
                } else {
                    $checks[] = [
                        'type' => 'token_expiry_risk',
                        'severity' => 'medium',
                        'message' => "FBR token expires in " . abs($daysLeft) . " days",
                        'penalty' => 8,
                    ];
                    $totalPenalty += 8;
                }
            }
        }

        if (!empty($invoice->buyer_ntn)) {
            $buyerRegType = $invoice->buyer_registration_type;
            $ntnLength = strlen(preg_replace('/[^0-9]/', '', $invoice->buyer_ntn));
            $expectedType = $ntnLength >= 7 ? 'Registered' : 'Unregistered';
            if ($buyerRegType && $buyerRegType !== $expectedType) {
                $checks[] = [
                    'type' => 'buyer_registration_conflict',
                    'severity' => 'medium',
                    'message' => "Buyer registration type '{$buyerRegType}' conflicts with NTN format (expected '{$expectedType}')",
                    'penalty' => 10,
                ];
                $totalPenalty += 10;
            }
        }

        $invoiceIds = Invoice::where('company_id', $invoice->company_id)->pluck('id');
        $recentFailures = FbrLog::whereIn('invoice_id', $invoiceIds)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
        if ($recentFailures > 3) {
            $historicalPenalty = min(15, $recentFailures * 3);
            $checks[] = [
                'type' => 'historical_failures',
                'severity' => 'low',
                'message' => "{$recentFailures} FBR rejections in last 30 days",
                'penalty' => $historicalPenalty,
            ];
            $totalPenalty += $historicalPenalty;
        }

        $probability = min(100, $totalPenalty);

        if ($probability <= 25) {
            $level = 'low';
            $color = 'green';
            $label = 'Low Risk';
        } elseif ($probability <= 60) {
            $level = 'moderate';
            $color = 'yellow';
            $label = 'Moderate Risk';
        } else {
            $level = 'high';
            $color = 'red';
            $label = 'High Risk';
        }

        return [
            'probability' => $probability,
            'level' => $level,
            'color' => $color,
            'label' => $label,
            'critical_violation' => $criticalViolation,
            'should_block' => $criticalViolation,
            'checks' => $checks,
            'check_count' => count($checks),
            'pass_count' => count($checks) === 0 ? 7 : 7 - count(array_filter($checks, fn($c) => in_array($c['severity'], ['critical', 'high']))),
        ];
    }

    public static function simulateFromRequest(array $data, int $companyId): array
    {
        $invoice = new Invoice();
        $invoice->company_id = $companyId;
        $invoice->buyer_ntn = $data['buyer_ntn'] ?? '';
        $invoice->buyer_name = $data['buyer_name'] ?? '';
        $invoice->buyer_registration_type = $data['buyer_registration_type'] ?? null;
        $invoice->supplier_province = $data['supplier_province'] ?? null;
        $invoice->destination_province = $data['destination_province'] ?? null;
        $invoice->document_type = $data['document_type'] ?? 'Sale Invoice';

        $company = Company::find($companyId);
        $invoice->setRelation('company', $company);

        $items = collect();
        foreach ($data['items'] ?? [] as $itemData) {
            $item = new \App\Models\InvoiceItem();
            $item->hs_code = $itemData['hs_code'] ?? '';
            $item->schedule_type = $itemData['schedule_type'] ?? 'standard';
            $item->tax_rate = $itemData['tax_rate'] ?? null;
            $item->sro_schedule_no = $itemData['sro_schedule_no'] ?? null;
            $item->serial_no = $itemData['serial_no'] ?? null;
            $item->mrp = $itemData['mrp'] ?? null;
            $items->push($item);
        }
        $invoice->setRelation('items', $items);

        return self::simulate($invoice);
    }
}
