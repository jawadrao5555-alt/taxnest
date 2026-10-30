<?php

namespace App\Services;

use App\Models\PosDayCloseReport;
use App\Models\PosProduct;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Business-day accounting. Aggregate retention never creates a recoverable bill or opts into support copies. */
class PosFinancialHistory
{
    public static function stream(PosTransaction $bill): string
    {
        if ($bill->pra_status === 'exempt_internal') return 'exempt';
        if ($bill->invoice_mode === 'local' || $bill->pra_status === 'local') return 'local';
        return $bill->pra_status !== null || $bill->pra_invoice_number !== null ? 'pra' : 'local';
    }

    private static function zero(): array
    {
        return ['sales' => 0, 'returns' => 0, 'gross' => 0, 'returned' => 0, 'net' => 0, 'tax' => 0, 'discount' => 0, 'base' => 0];
    }

    private static function add(array &$into, array $values): void
    {
        foreach (self::zero() as $key => $_) $into[$key] = round(($into[$key] ?? 0) + ($values[$key] ?? 0), 2);
    }

    private static function money(mixed $v): int { return (int) round((float) $v * 100); }

    /** The query caller supplies already authorized rows. Never reads another company's product master. */
    public static function groups(Collection $bills): Collection
    {
        if ($bills->isEmpty()) return collect();
        $bills = new \Illuminate\Database\Eloquent\Collection($bills->all());
        $bills->loadMissing('items');
        $products = collect();
        if (Schema::hasTable('pos_products')) {
            foreach ($bills->groupBy('company_id') as $companyId => $companyBills) {
                $ids = $companyBills->flatMap(fn ($b) => $b->items->where('item_type', 'product')->pluck('item_id'))->filter()->unique();
                foreach (PosProduct::where('company_id', $companyId)->whereIn('id', $ids)->get() as $p) $products[$companyId . ':' . $p->id] = $p;
            }
        }
        $groups = [];
        foreach ($bills as $bill) {
            $stage = $bill->status !== 'completed' ? 'draft' : ($bill->invoice_mode === 'local' ? 'provisional' : 'final');
            $scope = ['company_id' => (int) $bill->company_id, 'branch_id' => $bill->branch_id,
                'business_date' => $bill->business_date ?: $bill->created_at?->toDateString(),
                'created_by' => $bill->created_by, 'stream' => self::stream($bill), 'stage' => $stage,
                'payment_method' => $bill->payment_method ?: 'other'];
            if (!$scope['business_date']) continue;
            $key = json_encode($scope);
            $groups[$key] ??= $scope + ['totals' => self::zero(), 'products' => []];
            $sign = $bill->transaction_type === 'return' ? -1 : 1;
            $amount = (float) $bill->total_amount;
            self::add($groups[$key]['totals'], ['sales' => $sign > 0 ? 1 : 0, 'returns' => $sign < 0 ? 1 : 0,
                'gross' => $sign > 0 ? $amount : 0, 'returned' => $sign < 0 ? $amount : 0,
                'net' => $sign * $amount, 'tax' => $sign * (float) $bill->tax_amount,
                'base' => $sign * ($amount - (float) $bill->tax_amount), 'discount' => $sign * ((float) $bill->discount_amount + (float) $bill->items->sum('item_discount_amount') + (float) $bill->items->sum('promotion_discount'))]);
            $weights = $bill->items->map(fn ($i) => max(0, self::money($i->subtotal)))->all();
            $discounts = PosDealAllocation::cents(self::money($bill->discount_amount), $weights);
            $lineTotal = 0; $lineTax = 0;
            foreach ($bill->items->values() as $index => $item) {
                $snapshot = $item->deal_snapshot;
                if (PosDealAllocation::supported($snapshot) && collect($snapshot)->every(fn ($c) => isset($c['allocation']))) {
                    foreach ($snapshot as $component) {
                        $a = $component['allocation'];
                        $base = (float) ($a['taxable_base'] ?? 0) + (float) ($a['exempt_base'] ?? 0);
                        $tax = (float) ($a['tax'] ?? 0);
                        $row = ['product_id' => $component['product_id'] ?? null, 'name' => $component['name'] ?? 'Deal component',
                            'category' => $component['category'] ?? 'Uncategorized', 'size' => $component['size'] ?? '',
                            'channel' => 'deal component', 'basis' => 'frozen',
                            'qty' => $sign * (float) $item->quantity * (float) ($component['qty'] ?? 1),
                            'base' => $sign * $base, 'tax' => $sign * $tax, 'net' => $sign * ($base + $tax)];
                        self::addProduct($groups[$key]['products'], $row);
                        $lineTotal += self::money($base + $tax); $lineTax += self::money($tax);
                    }
                    continue; // The bundle parent is never added as a second sale.
                }
                $master = $products[$bill->company_id . ':' . $item->item_id] ?? null;
                $facts = $item->reporting_snapshot ?? [];
                $tax = (float) $item->tax_amount;
                $base = $item->taxable_base !== null && $item->exempt_base !== null
                    ? (float) $item->taxable_base + (float) $item->exempt_base
                    : (float) $item->subtotal - ($discounts[$index] ?? 0) / 100 - ($bill->tax_inclusive ? $tax : 0);
                $row = ['product_id' => $item->item_id, 'name' => $item->item_name,
                    'category' => $facts['category'] ?? ($item->item_type === 'product' ? ($master?->category ?: 'Uncategorized') : ($item->item_type === 'deal' ? 'Legacy deals' : 'Other')),
                    'size' => $facts['size'] ?? ($master->size ?? ''), 'channel' => $item->item_type === 'deal' ? 'legacy bundle' : 'standalone',
                    'basis' => $facts ? 'frozen' : 'legacy labels', 'qty' => $sign * (float) $item->quantity,
                    'base' => $sign * $base, 'tax' => $sign * $tax, 'net' => $sign * ($base + $tax)];
                self::addProduct($groups[$key]['products'], $row);
                $lineTotal += self::money($base + $tax); $lineTax += self::money($tax);
            }
            // Header rounding, delivery charges and legacy unallocated differences stay explicit.
            $gap = self::money($amount) - $lineTotal;
            $taxGap = self::money($bill->tax_amount) - $lineTax;
            if ($gap || $taxGap) self::addProduct($groups[$key]['products'], [
                'product_id' => null, 'name' => 'Charges / rounding / legacy difference', 'category' => 'Reconciliation', 'size' => '',
                'channel' => 'adjustment', 'basis' => 'header difference', 'qty' => 0,
                'base' => $sign * ($gap - $taxGap) / 100, 'tax' => $sign * $taxGap / 100, 'net' => $sign * $gap / 100,
            ]);
        }
        return collect(array_values($groups))->map(function ($g) { $g['products'] = array_values($g['products']); return $g; });
    }

    private static function addProduct(array &$products, array $row): void
    {
        $key = json_encode(array_intersect_key($row, array_flip(['product_id', 'name', 'category', 'size', 'channel', 'basis'])));
        if (!isset($products[$key])) { $products[$key] = $row; return; }
        foreach (['qty', 'base', 'tax', 'net'] as $field) $products[$key][$field] = round($products[$key][$field] + $row[$field], $field === 'qty' ? 4 : 2);
    }

    /** Must be immediately followed by deletion of these rows in the SAME locked transaction. */
    public static function captureDeleted(Collection $bills, ?int $reportId, string $source): void
    {
        if (!Schema::hasTable('pos_deleted_history_totals') || $bills->isEmpty()) return;
        if (DB::transactionLevel() < 1) throw new \LogicException('History capture requires the deletion transaction.');
        foreach (self::groups($bills) as $group) {
            DB::table('pos_deleted_history_totals')->insert(array_merge($group, [
                'totals' => json_encode($group['totals'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'products' => json_encode($group['products'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'dayclose_report_id' => $reportId, 'source' => $source, 'captured_at' => now(),
            ]));
        }
    }

    public static function report(int $companyId, string $from, string $to, string $tab, mixed $cashier, ?User $user, callable $scopeLive): array
    {
        $live = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $companyId)->where('status', 'completed')
            ->whereBetween('business_date', [$from, $to])->tap($scopeLive)->with('items')->get();
        $groups = self::groups($live);
        $retained = collect();
        if (Schema::hasTable('pos_deleted_history_totals')) {
            $q = DB::table('pos_deleted_history_totals')->where('company_id', $companyId)->whereBetween('business_date', [$from, $to]);
            app(BranchContextService::class)->applyToQuery($q);
            if ($tab !== 'combined') $q->where(function ($w) use ($tab, $user) {
                $w->where('stream', $tab);
                if ($user && $user->posBillingScopeIsDerived() && in_array($tab, ['pra', 'local'], true)) $w->orWhere('created_by', $user->id);
            });
            if ($cashier && $cashier !== 'all') $q->where('created_by', $cashier);
            $retained = $q->get()->map(function ($r) {
                $row = (array) $r; $row['totals'] = json_decode($r->totals, true); $row['products'] = json_decode($r->products, true); return $row;
            });
        }
        $summary = self::zero(); $provisional = self::zero(); $daily = []; $payments = []; $cashiers = []; $products = [];
        foreach ($groups->concat($retained) as $g) {
            if ($g['stage'] !== 'final') { self::add($provisional, $g['totals']); continue; }
            self::add($summary, $g['totals']);
            $date = $g['business_date']; $payment = $g['payment_method']; $creator = $g['created_by'] ?? 0;
            $daily[$date] ??= self::zero(); $payments[$payment] ??= self::zero(); $cashiers[$creator] ??= self::zero();
            self::add($daily[$date], $g['totals']); self::add($payments[$payment], $g['totals']); self::add($cashiers[$creator], $g['totals']);
            foreach ($g['products'] as $row) self::addProduct($products, $row);
        }
        ksort($daily);
        // Older deletions cannot be reconstructed. Report their evidence separately,
        // never inject whole-company Z totals into a cashier/product-filtered total.
        $gaps = [];
        if ((in_array($tab, ['local', 'combined'], true) || ($user && $user->posBillingScopeIsDerived()))
            && Schema::hasTable('pos_day_close_reports') && Schema::hasColumn('pos_day_close_reports', 'deleted_final_count')) {
            $q = PosDayCloseReport::where('company_id', $companyId)->whereBetween('report_date', [$from, $to])
                ->where(fn ($w) => $w->where('deleted_final_count', '>', 0)->orWhere('deleted_provisional_count', '>', 0));
            $branch = app(BranchContextService::class)->getActiveBranchId();
            if ($branch !== null && Schema::hasColumn('pos_day_close_reports', 'branch_id')) $q->whereIn('branch_id', [$branch, 0]);
            foreach ($q->get() as $z) {
                $captured = Schema::hasTable('pos_deleted_history_totals') ? DB::table('pos_deleted_history_totals')
                    ->where('company_id', $companyId)->where('dayclose_report_id', $z->id)->get()->sum(fn ($r) => (int) (json_decode($r->totals, true)['sales'] ?? 0)) : 0;
                if ($captured < (int) $z->deleted_final_count + (int) $z->deleted_provisional_count) $gaps[] = [
                    'date' => $z->report_date->toDateString(), 'id' => $z->id, 'number' => $z->report_number,
                    'reason' => 'Older deleted detail / breakdown unavailable; retained Z report is separate evidence.',
                ];
            }
        }
        return ['from' => $from, 'to' => $to, 'tab' => $tab, 'summary' => $summary, 'provisional' => $provisional,
            'daily' => $daily, 'payments' => $payments, 'cashiers' => $cashiers, 'products' => array_values($products),
            'retained_count' => (int) $retained->sum(fn ($r) => $r['totals']['sales']), 'live_count' => $live->count(),
            'gaps' => $gaps, 'complete' => !$gaps];
    }
}
