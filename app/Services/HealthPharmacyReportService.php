<?php

namespace App\Services;

use App\Models\HealthMedicine;
use App\Models\HealthMedicineBatch;
use App\Models\HealthPharmacySale;
use App\Models\HealthPharmacySaleItem;
use App\Models\HealthPrescription;
use App\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every pharmacy number a screen shows, computed in ONE place (Task 1549).
 *
 * The hub tiles, the reports page and the alert badges all read from here, so
 * "kitna maal expire hone wala hai" can never answer differently depending on
 * which screen the pharmacist happened to open.
 *
 * Branch rule throughout: a NULL `$branchId` with `$allBranches = false` means
 * a single-shop company (the platform's own NULL-branch convention). Set
 * `$allBranches` to drop the filter for an owner looking at the whole
 * organisation.
 */
class HealthPharmacyReportService
{
    /** Hub tiles. Deliberately count-only — the detail lives on the reports. */
    public static function summary(int $companyId, ?int $branchId, bool $allBranches = false): array
    {
        $settings = HealthPharmacyService::settings($companyId);
        $today = now()->toDateString();

        $sales = HealthPharmacySale::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('business_date', $today)
            ->where('status', '!=', HealthPharmacySale::STATUS_VOID)
            ->selectRaw('COUNT(*) as bills, COALESCE(SUM(total_amount - refunded_amount), 0) as net')
            ->first();

        return [
            'medicines' => HealthMedicine::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('is_active', true)->count(),
            'low_stock' => count(self::lowStock($companyId, $branchId, $allBranches)),
            'near_expiry' => self::nearExpiryQuery($companyId, $branchId, $allBranches, (int) $settings->near_expiry_days)->count(),
            'expired' => self::expiredQuery($companyId, $branchId, $allBranches)->count(),
            'quarantined' => HealthMedicineBatch::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
                ->where('status', HealthMedicineBatch::STATUS_QUARANTINED)->count(),
            'open_prescriptions' => HealthPrescription::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
                ->where('status', '!=', HealthPrescription::STATUS_DRAFT)
                ->whereIn('dispense_status', [HealthPrescription::DISPENSE_PENDING, HealthPrescription::DISPENSE_PARTIAL])
                ->count(),
            'today_bills' => (int) ($sales->bills ?? 0),
            'today_sales' => round((float) ($sales->net ?? 0), 2),
            'stock_value' => self::valuation($companyId, $branchId, $allBranches)['cost_value'],
            'near_expiry_days' => (int) $settings->near_expiry_days,
        ];
    }

    /**
     * Medicines whose sellable quantity has fallen to the reorder level.
     *
     * A medicine with no reorder level of its own falls back to the company
     * threshold, so a pharmacy that never filled the field still gets warned.
     */
    public static function lowStock(int $companyId, ?int $branchId, bool $allBranches = false): array
    {
        $settings = HealthPharmacyService::settings($companyId);
        $fallback = (float) $settings->low_stock_threshold;

        $medicines = HealthMedicine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($medicines->isEmpty()) {
            return [];
        }

        $available = self::availableByMedicine($companyId, $branchId, $allBranches, $medicines->pluck('id')->all());

        $out = [];
        foreach ($medicines as $medicine) {
            $level = (float) $medicine->reorder_level > 0 ? (float) $medicine->reorder_level : $fallback;
            $qty = (float) ($available[$medicine->id] ?? 0);

            if ($qty <= $level) {
                $out[] = [
                    'medicine' => $medicine,
                    'available' => round($qty, 3),
                    'reorder_level' => round($level, 3),
                    'shortfall' => round(max(0, $level - $qty), 3),
                ];
            }
        }

        return $out;
    }

    /** Sellable (active, unexpired) quantity per medicine id. */
    public static function availableByMedicine(int $companyId, ?int $branchId, bool $allBranches, array $medicineIds): array
    {
        if (!$medicineIds) {
            return [];
        }

        return HealthMedicineBatch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('medicine_id', $medicineIds)
            ->where('status', HealthMedicineBatch::STATUS_ACTIVE)
            ->where('quantity', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString());
            })
            ->selectRaw('medicine_id, SUM(quantity) as qty')
            ->groupBy('medicine_id')
            ->pluck('qty', 'medicine_id')
            ->map(fn ($qty) => round((float) $qty, 3))
            ->all();
    }

    /** Lots that die inside the warning window — still sellable, but urgent. */
    public static function nearExpiryQuery(int $companyId, ?int $branchId, bool $allBranches, int $days)
    {
        return HealthMedicineBatch::withoutGlobalScopes()
            ->with('medicine')
            ->where('company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', '!=', HealthMedicineBatch::STATUS_WRITTEN_OFF)
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', now()->addDays(max(0, $days))->toDateString())
            ->orderBy('expiry_date');
    }

    /** Lots already dead. These must never reach a patient. */
    public static function expiredQuery(int $companyId, ?int $branchId, bool $allBranches)
    {
        return HealthMedicineBatch::withoutGlobalScopes()
            ->with('medicine')
            ->where('company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', '!=', HealthMedicineBatch::STATUS_WRITTEN_OFF)
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->orderBy('expiry_date');
    }

    /**
     * What the shelf is worth. Cost value is the one that matters for accounts;
     * the retail value is shown beside it so the owner sees the spread.
     */
    public static function valuation(int $companyId, ?int $branchId, bool $allBranches = false): array
    {
        $row = HealthMedicineBatch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', '!=', HealthMedicineBatch::STATUS_WRITTEN_OFF)
            ->where('quantity', '>', 0)
            ->selectRaw('COALESCE(SUM(quantity * cost_price), 0) as cost_value')
            ->selectRaw('COALESCE(SUM(quantity * sale_price), 0) as retail_value')
            ->selectRaw('COALESCE(SUM(quantity), 0) as units')
            ->first();

        return [
            'cost_value' => round((float) ($row->cost_value ?? 0), 2),
            'retail_value' => round((float) ($row->retail_value ?? 0), 2),
            'units' => round((float) ($row->units ?? 0), 3),
        ];
    }

    /** Per-medicine valuation rows for the stock-valuation report. */
    public static function valuationRows(int $companyId, ?int $branchId, bool $allBranches = false)
    {
        return HealthMedicineBatch::withoutGlobalScopes()
            ->where('health_medicine_batches.company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('health_medicine_batches.branch_id', $branchId))
            ->where('health_medicine_batches.status', '!=', HealthMedicineBatch::STATUS_WRITTEN_OFF)
            ->where('health_medicine_batches.quantity', '>', 0)
            ->join('health_medicines', 'health_medicines.id', '=', 'health_medicine_batches.medicine_id')
            ->groupBy('health_medicine_batches.medicine_id', 'health_medicines.name', 'health_medicines.strength', 'health_medicines.unit_uom')
            ->orderBy('health_medicines.name')
            ->selectRaw('health_medicine_batches.medicine_id as medicine_id')
            ->selectRaw('health_medicines.name as name')
            ->selectRaw('health_medicines.strength as strength')
            ->selectRaw('health_medicines.unit_uom as unit_uom')
            ->selectRaw('SUM(health_medicine_batches.quantity) as units')
            ->selectRaw('SUM(health_medicine_batches.quantity * health_medicine_batches.cost_price) as cost_value')
            ->selectRaw('SUM(health_medicine_batches.quantity * health_medicine_batches.sale_price) as retail_value')
            ->get();
    }

    /**
     * Margin per medicine over a period, from the SOLD lines' own cost snapshot.
     * Returned quantity is netted off both sides so a refunded strip stops
     * counting as profit.
     */
    public static function margin(int $companyId, ?int $branchId, bool $allBranches, string $from, string $to)
    {
        return HealthPharmacySaleItem::withoutGlobalScopes()
            ->join('health_pharmacy_sales', 'health_pharmacy_sales.id', '=', 'health_pharmacy_sale_items.sale_id')
            ->where('health_pharmacy_sale_items.company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('health_pharmacy_sales.branch_id', $branchId))
            ->where('health_pharmacy_sales.status', '!=', HealthPharmacySale::STATUS_VOID)
            ->whereBetween('health_pharmacy_sales.business_date', [$from, $to])
            ->groupBy('health_pharmacy_sale_items.item_name')
            ->orderByDesc(DB::raw('SUM((health_pharmacy_sale_items.quantity - health_pharmacy_sale_items.returned_quantity) * (health_pharmacy_sale_items.unit_price - health_pharmacy_sale_items.unit_cost))'))
            ->selectRaw('health_pharmacy_sale_items.item_name as item_name')
            ->selectRaw('SUM(health_pharmacy_sale_items.quantity - health_pharmacy_sale_items.returned_quantity) as units')
            ->selectRaw('SUM((health_pharmacy_sale_items.quantity - health_pharmacy_sale_items.returned_quantity) * health_pharmacy_sale_items.unit_price) as revenue')
            ->selectRaw('SUM((health_pharmacy_sale_items.quantity - health_pharmacy_sale_items.returned_quantity) * health_pharmacy_sale_items.unit_cost) as cost')
            ->get()
            ->map(function ($row) {
                $revenue = round((float) $row->revenue, 2);
                $cost = round((float) $row->cost, 2);
                $row->revenue = $revenue;
                $row->cost = $cost;
                $row->profit = round($revenue - $cost, 2);
                $row->margin_pct = $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : 0.0;
                $row->units = round((float) $row->units, 3);

                return $row;
            });
    }

    /**
     * Purchases received in a period. Branch lives on the BATCH rows (the shared
     * purchase_orders table is branchless by design), so the branch filter is a
     * whereExists on this company's own batches.
     */
    public static function purchases(int $companyId, ?int $branchId, bool $allBranches, string $from, string $to)
    {
        return PurchaseOrder::query()
            ->withoutGlobalScopes()
            ->with('supplier')
            ->where('purchase_orders.company_id', $companyId)
            ->whereBetween('purchase_orders.order_date', [$from, $to])
            ->whereExists(function ($query) use ($companyId, $branchId, $allBranches) {
                $query->select(DB::raw(1))
                    ->from('health_medicine_batches')
                    ->whereColumn('health_medicine_batches.purchase_order_id', 'purchase_orders.id')
                    ->where('health_medicine_batches.company_id', $companyId);

                if (!$allBranches) {
                    $branchId === null
                        ? $query->whereNull('health_medicine_batches.branch_id')
                        : $query->where('health_medicine_batches.branch_id', $branchId);
                }
            })
            ->orderByDesc('purchase_orders.id')
            ->get();
    }

    /**
     * Supplier balances: what this company was billed for medicine minus what it
     * paid. Only purchases that actually created pharmacy batches are counted,
     * so a POS-side purchase never lands on a pharmacy supplier statement.
     */
    public static function supplierBalances(int $companyId)
    {
        $billed = DB::table('purchase_orders')
            ->where('purchase_orders.company_id', $companyId)
            ->where('purchase_orders.status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->whereNotNull('purchase_orders.supplier_id')
            ->whereExists(function ($query) use ($companyId) {
                $query->select(DB::raw(1))
                    ->from('health_medicine_batches')
                    ->whereColumn('health_medicine_batches.purchase_order_id', 'purchase_orders.id')
                    ->where('health_medicine_batches.company_id', $companyId);
            })
            ->groupBy('purchase_orders.supplier_id')
            ->selectRaw('purchase_orders.supplier_id as supplier_id, COALESCE(SUM(purchase_orders.total_amount), 0) as billed')
            ->pluck('billed', 'supplier_id');

        $paid = DB::table('health_supplier_payments')
            ->where('company_id', $companyId)
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COALESCE(SUM(amount), 0) as paid')
            ->pluck('paid', 'supplier_id');

        /*
         * Day-one payables carried in at onboarding (Task 1555). Without them a
         * hospital that moved onto the panel owing its distributor 400,000 sees
         * a zero balance on the payables screen, and the first payment it
         * records against that distributor drives the balance negative.
         *
         * Schema-guarded: the column arrives in its own migration, and the
         * report must not 500 on a box that has deployed the code but not yet
         * run it.
         */
        $opening = collect();
        if (Schema::hasColumn('suppliers', 'opening_balance')) {
            $opening = DB::table('suppliers')
                ->where('company_id', $companyId)
                ->where('opening_balance', '!=', 0)
                ->pluck('opening_balance', 'id');
        }

        $supplierIds = collect($billed->keys())
            ->merge($paid->keys())
            ->merge($opening->keys())
            ->unique()->filter()->values();
        if ($supplierIds->isEmpty()) {
            return collect();
        }

        $suppliers = DB::table('suppliers')
            ->where('company_id', $companyId)
            ->whereIn('id', $supplierIds)
            ->get()
            ->keyBy('id');

        return $supplierIds->map(function ($id) use ($billed, $paid, $opening, $suppliers) {
            $openingAmount = round((float) ($opening[$id] ?? 0), 2);
            $billedAmount = round((float) ($billed[$id] ?? 0), 2);
            $paidAmount = round((float) ($paid[$id] ?? 0), 2);

            return (object) [
                'supplier_id' => (int) $id,
                'name' => $suppliers[$id]->name ?? '—',
                'phone' => $suppliers[$id]->phone ?? null,
                'opening' => $openingAmount,
                'billed' => $billedAmount,
                'paid' => $paidAmount,
                'balance' => round($openingAmount + $billedAmount - $paidAmount, 2),
            ];
        })->sortByDesc('balance')->values();
    }

    /** Normalise a report date range; defaults to the current month. */
    public static function range(?string $from, ?string $to): array
    {
        try {
            $start = $from ? Carbon::parse($from)->toDateString() : now()->startOfMonth()->toDateString();
        } catch (\Throwable $e) {
            $start = now()->startOfMonth()->toDateString();
        }

        try {
            $end = $to ? Carbon::parse($to)->toDateString() : now()->toDateString();
        } catch (\Throwable $e) {
            $end = now()->toDateString();
        }

        return $start <= $end ? [$start, $end] : [$end, $start];
    }
}
