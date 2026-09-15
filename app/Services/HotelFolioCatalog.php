<?php

namespace App\Services;

use App\Models\PosProduct;
use App\Models\PosService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stay extra pickers: products and services visible for one stay's company
 * and branch. Company-wide untracked catalog items stay available; items that
 * only have inventory at another branch are hidden.
 */
class HotelFolioCatalog
{
    /**
     * @return Collection<int, PosProduct>
     */
    public static function products(int $companyId, ?int $branchId): Collection
    {
        if (!Schema::hasTable('pos_products')) {
            return collect();
        }
        $query = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name');
        $hidden = self::productIdsOnlyOnOtherBranches($companyId, $branchId);
        if ($hidden !== []) {
            $query->whereNotIn('id', $hidden);
        }

        return $query->limit(200)->get(['id', 'name', 'price', 'uom']);
    }

    /**
     * @return Collection<int, PosService>
     */
    public static function services(int $companyId, ?int $branchId): Collection
    {
        if (!Schema::hasTable('pos_services')) {
            return collect();
        }
        $query = PosService::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name');
        if ($branchId && Schema::hasColumn('pos_services', 'branch_id')) {
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        return $query->limit(200)->get(['id', 'name', 'price']);
    }

    public static function productAllowed(int $companyId, ?int $branchId, int $productId): bool
    {
        if ($productId <= 0 || !Schema::hasTable('pos_products')) {
            return false;
        }
        $exists = PosProduct::where('company_id', $companyId)
            ->where('id', $productId)
            ->where('is_active', true)
            ->exists();
        if (!$exists) {
            return false;
        }

        return !in_array($productId, self::productIdsOnlyOnOtherBranches($companyId, $branchId), true);
    }

    public static function serviceAllowed(int $companyId, ?int $branchId, int $serviceId): bool
    {
        if ($serviceId <= 0 || !Schema::hasTable('pos_services')) {
            return false;
        }
        $query = PosService::where('company_id', $companyId)
            ->where('id', $serviceId)
            ->where('is_active', true);
        if ($branchId && Schema::hasColumn('pos_services', 'branch_id')) {
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        return $query->exists();
    }

    /**
     * @return list<int>
     */
    private static function productIdsOnlyOnOtherBranches(int $companyId, ?int $branchId): array
    {
        if (!$branchId || !Schema::hasTable('inventory_stocks') || !Schema::hasColumn('inventory_stocks', 'branch_id')) {
            return [];
        }
        $here = DB::table('inventory_stocks')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $elsewhere = DB::table('inventory_stocks')
            ->where('company_id', $companyId)
            ->whereNotNull('branch_id')
            ->where('branch_id', '!=', $branchId)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_diff($elsewhere, $here));
    }
}
