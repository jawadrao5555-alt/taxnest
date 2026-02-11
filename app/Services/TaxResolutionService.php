<?php

namespace App\Services;

use App\Models\Company;
use App\Models\SectorTaxRule;
use App\Models\ProvinceTaxRule;
use App\Models\CustomerTaxRule;
use App\Models\OverrideUsageLog;

class TaxResolutionService
{
    public static function resolve(string $hsCode, Company $company, ?string $customerNtn = null, ?array $manualOverride = null, ?int $invoiceId = null): array
    {
        $normalized = preg_replace('/[^0-9]/', '', $hsCode);
        $standardTaxRate = $company->getStandardTaxRateValue();

        $globalResult = ScheduleEngine::lookupByHsCode($normalized, $standardTaxRate);

        $result = [
            'hs_code' => $normalized,
            'pct_code' => $globalResult['pct_code'] ?? null,
            'schedule_type' => $globalResult['schedule_type'] ?? 'standard',
            'tax_rate' => $globalResult['tax_rate'] ?? $standardTaxRate,
            'requires_sro' => $globalResult['requires_sro'] ?? false,
            'requires_mrp' => $globalResult['requires_mrp'] ?? false,
            'requires_serial' => $globalResult['requires_serial'] ?? false,
            'source_layer' => 'global',
            'override_applied' => false,
            'overrides_chain' => [],
        ];

        $originalValues = $result;

        $sectorOverride = self::getSectorOverride($company->sector_type ?? 'Retail', $normalized);
        if ($sectorOverride) {
            $result = self::applyOverride($result, $sectorOverride, 'sector', $standardTaxRate);
        }

        $provinceOverride = self::getProvinceOverride($company->province, $normalized);
        if ($provinceOverride) {
            $result = self::applyOverride($result, $provinceOverride, 'province', $standardTaxRate);
        }

        if ($customerNtn) {
            $customerOverride = self::getCustomerOverride($company->id, $customerNtn, $normalized);
            if ($customerOverride) {
                $result = self::applyOverride($result, $customerOverride, 'customer', $standardTaxRate);
            }
        }

        if ($manualOverride && !empty(array_filter($manualOverride))) {
            $result = self::applyManualOverride($result, $manualOverride, $standardTaxRate);
        }

        if ($result['override_applied'] && $invoiceId) {
            self::logOverrideUsage($company->id, $invoiceId, $normalized, $result['source_layer'], $originalValues, $result);
        }

        return $result;
    }

    public static function resolveForApi(string $hsCode, int $companyId, ?string $customerNtn = null): array
    {
        $company = Company::find($companyId);
        if (!$company) {
            return ['error' => 'Company not found'];
        }

        $result = self::resolve($hsCode, $company, $customerNtn);

        return [
            'hs_code' => $result['hs_code'],
            'pct_code' => $result['pct_code'],
            'schedule_type' => $result['schedule_type'],
            'tax_rate' => $result['tax_rate'],
            'requires_sro' => $result['requires_sro'],
            'requires_mrp' => $result['requires_mrp'],
            'requires_serial' => $result['requires_serial'],
            'source_layer' => $result['source_layer'],
            'override_applied' => $result['override_applied'],
            'overrides_chain' => $result['overrides_chain'],
            'standard_tax_rate' => $company->getStandardTaxRateValue(),
        ];
    }

    private static function getSectorOverride(string $sectorType, string $hsCode): ?SectorTaxRule
    {
        return SectorTaxRule::where('sector_type', $sectorType)
            ->where('hs_code', $hsCode)
            ->where('is_active', true)
            ->first();
    }

    private static function getProvinceOverride(?string $province, string $hsCode): ?ProvinceTaxRule
    {
        if (!$province) return null;

        return ProvinceTaxRule::where('province', $province)
            ->where('hs_code', $hsCode)
            ->where('is_active', true)
            ->first();
    }

    private static function getCustomerOverride(int $companyId, string $customerNtn, string $hsCode): ?CustomerTaxRule
    {
        return CustomerTaxRule::where('company_id', $companyId)
            ->where('customer_ntn', $customerNtn)
            ->where('hs_code', $hsCode)
            ->where('is_active', true)
            ->first();
    }

    private static function applyOverride(array $result, $override, string $layer, float $standardTaxRate): array
    {
        $changes = [];

        if ($override->override_tax_rate !== null) {
            $changes['tax_rate'] = ['from' => $result['tax_rate'], 'to' => $override->override_tax_rate];
            $result['tax_rate'] = $override->override_tax_rate;
        }

        if ($override->override_schedule_type !== null) {
            $changes['schedule_type'] = ['from' => $result['schedule_type'], 'to' => $override->override_schedule_type];
            $result['schedule_type'] = $override->override_schedule_type;
        }

        if ($override->override_sro_required !== null) {
            $changes['requires_sro'] = ['from' => $result['requires_sro'], 'to' => $override->override_sro_required];
            $result['requires_sro'] = $override->override_sro_required;
        }

        if ($override->override_mrp_required !== null) {
            $changes['requires_mrp'] = ['from' => $result['requires_mrp'], 'to' => $override->override_mrp_required];
            $result['requires_mrp'] = $override->override_mrp_required;
        }

        if (!empty($changes)) {
            $result['source_layer'] = $layer;
            $result['override_applied'] = true;
            $result['overrides_chain'][] = ['layer' => $layer, 'source_id' => $override->id, 'changes' => $changes];

            $rules = ScheduleEngine::resolveValidationRules($result['schedule_type'], $result['tax_rate'], $standardTaxRate);
            if ($override->override_sro_required === null) {
                $result['requires_sro'] = $rules['requires_sro'];
            }
            if ($override->override_mrp_required === null) {
                $result['requires_mrp'] = $rules['requires_mrp'];
            }
            $result['requires_serial'] = $rules['requires_serial'];
        }

        return $result;
    }

    private static function applyManualOverride(array $result, array $manual, float $standardTaxRate): array
    {
        $changes = [];

        if (isset($manual['tax_rate']) && $manual['tax_rate'] !== '' && $manual['tax_rate'] !== null) {
            $changes['tax_rate'] = ['from' => $result['tax_rate'], 'to' => floatval($manual['tax_rate'])];
            $result['tax_rate'] = floatval($manual['tax_rate']);
        }

        if (!empty($manual['schedule_type'])) {
            $changes['schedule_type'] = ['from' => $result['schedule_type'], 'to' => $manual['schedule_type']];
            $result['schedule_type'] = $manual['schedule_type'];
        }

        if (!empty($changes)) {
            $result['source_layer'] = 'manual';
            $result['override_applied'] = true;
            $result['overrides_chain'][] = ['layer' => 'manual', 'source_id' => null, 'changes' => $changes];

            $rules = ScheduleEngine::resolveValidationRules($result['schedule_type'], $result['tax_rate'], $standardTaxRate);
            $result['requires_sro'] = $rules['requires_sro'];
            $result['requires_mrp'] = $rules['requires_mrp'];
            $result['requires_serial'] = $rules['requires_serial'];
        }

        return $result;
    }

    private static function logOverrideUsage(int $companyId, int $invoiceId, string $hsCode, string $layer, array $original, array $overridden): void
    {
        OverrideUsageLog::create([
            'company_id' => $companyId,
            'invoice_id' => $invoiceId,
            'hs_code' => $hsCode,
            'override_layer' => $layer,
            'original_values' => [
                'tax_rate' => $original['tax_rate'],
                'schedule_type' => $original['schedule_type'],
                'requires_sro' => $original['requires_sro'],
                'requires_mrp' => $original['requires_mrp'],
            ],
            'overridden_values' => [
                'tax_rate' => $overridden['tax_rate'],
                'schedule_type' => $overridden['schedule_type'],
                'requires_sro' => $overridden['requires_sro'],
                'requires_mrp' => $overridden['requires_mrp'],
                'source_layer' => $overridden['source_layer'],
            ],
        ]);
    }

    public static function getOverrideAnalytics(int $companyId): array
    {
        $logs = OverrideUsageLog::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        $totalInvoices = \App\Models\Invoice::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $overrideCount = $logs->count();
        $overridePercentage = $totalInvoices > 0 ? round(($overrideCount / max($totalInvoices, 1)) * 100, 1) : 0;

        $byLayer = $logs->groupBy('override_layer')->map->count()->toArray();

        return [
            'total_overrides' => $overrideCount,
            'override_percentage' => $overridePercentage,
            'by_layer' => $byLayer,
            'total_invoices_30d' => $totalInvoices,
        ];
    }

    public static function getGlobalAnalytics(): array
    {
        $sectorDistribution = SectorTaxRule::where('is_active', true)
            ->selectRaw('sector_type, COUNT(*) as count')
            ->groupBy('sector_type')
            ->pluck('count', 'sector_type')
            ->toArray();

        $provinceDistribution = ProvinceTaxRule::where('is_active', true)
            ->selectRaw('province, COUNT(*) as count')
            ->groupBy('province')
            ->pluck('count', 'province')
            ->toArray();

        $layerUsage = OverrideUsageLog::where('created_at', '>=', now()->subDays(30))
            ->selectRaw('override_layer, COUNT(*) as count')
            ->groupBy('override_layer')
            ->pluck('count', 'override_layer')
            ->toArray();

        $sroUsage = \App\Models\Invoice::where('created_at', '>=', now()->subDays(30))
            ->whereHas('items', function ($q) {
                $q->whereNotNull('sro_schedule_no')->where('sro_schedule_no', '!=', '');
            })
            ->count();

        $totalInvoices = \App\Models\Invoice::where('created_at', '>=', now()->subDays(30))->count();

        return [
            'sector_distribution' => $sectorDistribution,
            'province_distribution' => $provinceDistribution,
            'override_layer_usage' => $layerUsage,
            'sro_usage_count' => $sroUsage,
            'sro_usage_percentage' => $totalInvoices > 0 ? round(($sroUsage / $totalInvoices) * 100, 1) : 0,
            'total_sector_rules' => SectorTaxRule::where('is_active', true)->count(),
            'total_province_rules' => ProvinceTaxRule::where('is_active', true)->count(),
            'total_customer_rules' => CustomerTaxRule::where('is_active', true)->count(),
            'total_sro_rules' => \App\Models\SpecialSroRule::where('is_active', true)->count(),
        ];
    }
}
