<?php

namespace App\Services;

use App\Models\RestaurantOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Called inside the order lock. Evidence does not post stock, revenue or a refund. */
class PosCancellationEvidence
{
    public static function summary($query): array
    {
        $orders = (clone $query)->reorder();
        $items = \App\Models\RestaurantOrderItem::query()->whereIn('order_id', (clone $orders)->select('id'));
        $hasPrepared = Schema::hasColumn('restaurant_order_items', 'prepared_quantity');
        $hasMade = Schema::hasColumn('restaurant_order_items', 'was_made');
        $knownValue = $hasMade ? 'CASE WHEN was_made = 1 THEN subtotal ELSE 0 END' : '0';
        $unknown = $hasMade ? 'was_made IS NULL' : '1 = 1';
        if ($hasPrepared) {
            $knownValue = "CASE WHEN prepared_quantity IS NOT NULL THEN subtotal * prepared_quantity / NULLIF(quantity, 0) ELSE {$knownValue} END";
            $unknown = "prepared_quantity IS NULL AND ({$unknown})";
        }
        $aggregate = (clone $items)->selectRaw("COALESCE(SUM({$knownValue}), 0) AS value, SUM(CASE WHEN {$unknown} THEN 1 ELSE 0 END) AS unknown_count")->first();

        return [
            'count' => (clone $orders)->count(),
            'value' => (float) (clone $orders)->sum('total_amount'),
            'waste' => round((float) $aggregate->value, 2),
            'unknown_item_count' => (int) $aggregate->unknown_count,
            'made_item_count' => $hasMade ? (clone $items)->where('was_made', true)->count() : 0,
        ];
    }

    public static function record(RestaurantOrder $order, Request $request): void
    {
        $input = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
            'prepared_quantities' => ['sometimes', 'array', 'max:1000'],
            'prepared_quantities.*' => ['nullable', 'numeric', 'min:0'],
            'made_item_ids' => ['sometimes', 'array', 'max:1000'],
            'made_item_ids.*' => ['integer', 'min:1'],
        ]);
        $items = $order->items()->lockForUpdate()->get()->keyBy('id');
        $reason = trim((string) ($input['cancellation_reason'] ?? ''));
        if ($reason !== '') {
            if (!Schema::hasColumn('restaurant_orders', 'cancellation_reason')) {
                throw ValidationException::withMessages(['cancellation_reason' => 'Cancellation evidence update is not available yet.']);
            }
            $order->cancellation_reason = $reason;
        }
        if (array_key_exists('prepared_quantities', $input)) {
            if (!Schema::hasColumn('restaurant_order_items', 'prepared_quantity')) {
                throw ValidationException::withMessages(['prepared_quantities' => 'Preparation evidence update is not available yet.']);
            }
            foreach ($input['prepared_quantities'] as $id => $quantity) {
                $item = $items->get($id);
                if (!$item || ($quantity !== null && $quantity !== '' && (float) $quantity > (float) $item->quantity)) {
                    throw ValidationException::withMessages(['prepared_quantities' => 'Use this order’s item IDs and quantities no greater than ordered.']);
                }
                // Omitted/blank means unknown, including when a KOT was sent.
                if ($quantity === null || $quantity === '') continue;
                $item->prepared_quantity = round((float) $quantity, 3);
                $item->was_made = (float) $quantity > 0;
                $item->save();
            }
        } elseif (array_key_exists('made_item_ids', $input) && Schema::hasColumn('restaurant_order_items', 'was_made')) {
            // Preserve the explicit yes/no contract of supported older clients.
            $ids = array_map('intval', $input['made_item_ids']);
            if (array_diff($ids, $items->keys()->all())) {
                throw ValidationException::withMessages(['made_item_ids' => 'An item does not belong to this order.']);
            }
            foreach ($items as $item) {
                $item->was_made = in_array((int) $item->id, $ids, true);
                $item->save();
            }
        }
    }
}
