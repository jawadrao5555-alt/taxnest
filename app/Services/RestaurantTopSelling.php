<?php

namespace App\Services;

use App\Models\RestaurantOrderItem;
use Illuminate\Database\Eloquent\Builder;

final class RestaurantTopSelling
{
    /** Same completed-order population for the dashboard preview and full list. */
    public static function query(int $companyId, $from, $until): Builder
    {
        return RestaurantOrderItem::query()
            ->whereHas('order', function ($query) use ($companyId, $from, $until) {
                $query->where('company_id', $companyId)->where('status', 'completed')
                    ->where('created_at', '>=', $from)->where('created_at', '<', $until);
            });
    }

    public static function summary(int $companyId, $from, $until): Builder
    {
        return self::query($companyId, $from, $until)
            ->selectRaw('item_name, SUM(quantity) as total_qty, SUM(subtotal) as total_revenue, COUNT(DISTINCT order_id) as total_orders')
            ->groupBy('item_name')->orderByDesc('total_qty')->orderBy('item_name');
    }
}
