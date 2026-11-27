<?php

namespace App\Services;

use App\Models\HealthLabOrder;
use App\Models\HealthLabSpecimen;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HealthLabOperations
{
    public function byBranch(User $user, CarbonImmutable $from, CarbonImmutable $through, ?int $branchId): Collection
    {
        $rejections = DB::table('health_lab_specimens as specimen')
            ->join('health_lab_order_items as item', 'item.id', '=', 'specimen.health_lab_order_item_id')
            ->selectRaw('COUNT(*)')
            ->where('specimen.company_id', $user->company_id)
            ->where('item.company_id', $user->company_id)
            ->whereColumn('item.health_lab_order_id', 'health_lab_orders.id')
            ->where('specimen.status', HealthLabSpecimen::REJECTED);

        // The verified count has the same meaning as the existing patient report:
        // a published result remains valid until a replacement has been verified.
        $orders = $this->scopedOrders($user, $from, $through, $branchId)
            ->select('health_lab_orders.branch_id')
            ->selectSub($rejections, 'rejected_specimens_count')
            ->withCount([
                'items' => fn ($items) => $items->where('company_id', $user->company_id),
                'items as verified_items_count' => fn ($items) => $items->where('company_id', $user->company_id)
                    ->whereHas('results', fn ($results) => $results->where('company_id', $user->company_id)
                        ->whereNotNull('verified_at')),
            ]);

        return DB::query()->fromSub($orders->toBase(), 'scoped_orders')
            ->select('scoped_orders.branch_id')
            ->selectRaw('COUNT(*) AS orders, SUM(CASE WHEN items_count > 0 AND items_count = verified_items_count THEN 1 ELSE 0 END) AS ready_orders')
            ->selectRaw('SUM(items_count) AS ordered_tests, SUM(verified_items_count) AS verified_tests, SUM(rejected_specimens_count) AS rejected_specimens')
            ->groupBy('scoped_orders.branch_id')
            ->get()
            ->each(function (object $row) {
                $row->open_orders = (int) $row->orders - (int) $row->ready_orders;
            });
    }

    public function costsByBranch(User $user, CarbonImmutable $from, CarbonImmutable $through, ?int $branchId): Collection
    {
        $orders = $this->scopedOrders($user, $from, $through, $branchId)
            ->select('health_lab_orders.id', 'health_lab_orders.company_id', 'health_lab_orders.branch_id');

        return DB::query()->fromSub($orders->toBase(), 'scoped_orders')
            ->join('health_lab_order_items as item', function ($join) {
                $join->on('item.company_id', '=', 'scoped_orders.company_id')
                    ->on('item.health_lab_order_id', '=', 'scoped_orders.id');
            })
            ->select('scoped_orders.branch_id')
            ->selectRaw('SUM(item.unit_cost_paisa) AS known_cost_paisa, COUNT(item.unit_cost_paisa) AS costed_tests, COUNT(*) - COUNT(item.unit_cost_paisa) AS uncosted_tests')
            ->groupBy('scoped_orders.branch_id')
            ->get();
    }

    private function scopedOrders(User $user, CarbonImmutable $from, CarbonImmutable $through, ?int $branchId): Builder
    {
        return HealthLabOrder::visibleTo($user)
            ->where('health_lab_orders.created_at', '>=', $from)
            ->where('health_lab_orders.created_at', '<', $through->addDay())
            ->when($branchId !== null, fn ($query) => $query->where('health_lab_orders.branch_id', $branchId));
    }
}
