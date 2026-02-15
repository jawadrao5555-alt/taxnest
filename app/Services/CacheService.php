<?php

namespace App\Services;

use App\Models\PricingPlan;
use App\Models\GlobalHsMaster;
use Illuminate\Support\Facades\Cache;

class CacheService
{
    public static function pricingPlans()
    {
        return Cache::remember('pricing_plans', 300, function () {
            return PricingPlan::where('is_trial', false)->orderBy('price')->get();
        });
    }

    public static function allPricingPlans()
    {
        return Cache::remember('pricing_plans_all', 300, function () {
            return PricingPlan::all();
        });
    }

    public static function hsLookup(string $hsCode): ?array
    {
        $normalized = preg_replace('/[^0-9]/', '', $hsCode);
        if (empty($normalized)) return null;

        return Cache::remember("hs_master:{$normalized}", 600, function () use ($normalized) {
            $master = GlobalHsMaster::where('hs_code', $normalized)->first();
            if (!$master) return null;

            return [
                'found' => true,
                'hs_code' => $master->hs_code,
                'pct_code' => $master->pct_code,
                'schedule_type' => $master->schedule_type,
                'tax_rate' => $master->tax_rate,
                'default_uom' => $master->default_uom,
                'sro_required' => $master->sro_required,
                'sro_number' => $master->sro_number,
                'sro_item_serial_no' => $master->sro_item_serial_no,
                'mrp_required' => $master->mrp_required,
                'sector_tag' => $master->sector_tag,
                'risk_weight' => $master->risk_weight,
                'mapping_status' => $master->mapping_status,
            ];
        });
    }

    public static function provinces(): array
    {
        return Cache::remember('pakistan_provinces', 3600, function () {
            return [
                'Punjab', 'Sindh', 'Khyber Pakhtunkhwa', 'Balochistan',
                'Islamabad', 'Azad Kashmir', 'Gilgit-Baltistan', 'FATA',
            ];
        });
    }

    public static function dashboardCounters(int $companyId): array
    {
        return Cache::remember("dashboard_counters:{$companyId}", 60, function () use ($companyId) {
            return \App\Models\Invoice::where('company_id', $companyId)
                ->select(
                    \Illuminate\Support\Facades\DB::raw('COUNT(*) as total'),
                    \Illuminate\Support\Facades\DB::raw("SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count"),
                    \Illuminate\Support\Facades\DB::raw("SUM(CASE WHEN status = 'locked' THEN 1 ELSE 0 END) as locked_count"),
                    \Illuminate\Support\Facades\DB::raw('COALESCE(SUM(total_amount), 0) as total_revenue')
                )
                ->first()
                ->toArray();
        });
    }

    public static function clearCompanyCache(int $companyId): void
    {
        Cache::forget("dashboard_counters:{$companyId}");
    }

    public static function clearPricingCache(): void
    {
        Cache::forget('pricing_plans');
        Cache::forget('pricing_plans_all');
    }

    public static function clearHsCache(string $hsCode): void
    {
        $normalized = preg_replace('/[^0-9]/', '', $hsCode);
        Cache::forget("hs_master:{$normalized}");
    }
}
