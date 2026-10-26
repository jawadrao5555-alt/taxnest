<?php

namespace App\Support;

use App\Models\Company;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Per-line service is independent of the bill/table's fiscal order type. */
final class PosFulfillment
{
    public const MODES = ['dine_in', 'takeaway', 'delivery'];

    public static function label(?string $mode): string
    {
        return match ($mode) {
            'dine_in' => 'SERVE HERE', 'takeaway' => 'PACK / TAKEAWAY',
            'delivery' => 'PACK / DELIVERY', default => '',
        };
    }

    public static function hasDelivery(iterable $items, ?string $orderType = null): bool
    {
        if ($orderType === 'delivery') return true;
        foreach ($items as $line) {
            $mode = is_array($line) ? ($line['fulfillment'] ?? null) : ($line->fulfillment ?? null);
            // Input skip_kitchen is untrusted until the fee resolver verifies it.
            if ($mode === 'delivery') return true;
        }
        return false;
    }

    public static function validate(Request $request, Company $company, ?\App\Models\PosTransaction $existingBill = null): void
    {
        $request->validate([
            'items.*.fulfillment' => 'nullable|in:dine_in,takeaway,delivery',
            'items.*.line_uid' => 'nullable|string|max:100|regex:/^[A-Za-z0-9_-]+$/',
            'items.*.cart_uid' => 'nullable|string|max:100|regex:/^[A-Za-z0-9_-]+$/',
            'delivery_collection_mode' => 'nullable|in:counter,rider',
        ]);
        // New bills have one order type. Item notes carry kitchen instructions.
        // Persisted pre-simplification mixed orders retain their exact snapshots;
        // a client flag or foreign recall ID can never opt a new bill into mixing.
        $orderType = $request->input('order_type') ?? $existingBill?->order_type;
        if (self::isMixed($request->input('items', []), $orderType)) {
            $existing = $existingBill;
            if (!$existing && ($request->filled('recalled_order_id') || $request->filled('hold_uuid'))) {
                $query = \App\Models\RestaurantOrder::where('company_id', $company->id);
                $existing = $request->filled('recalled_order_id')
                    ? $query->whereIn('status', ['held', 'preparing', 'ready'])->find($request->recalled_order_id)
                    : (Schema::hasColumn('restaurant_orders', 'hold_uuid') ? $query->where('hold_uuid', $request->hold_uuid)->first() : null);
            }
            $oldLines = $existing && (int) $existing->company_id === (int) $company->id
                ? $existing->items->keyBy('line_uid') : collect();
            foreach ($request->input('items', []) as $line) {
                $mode = $line['fulfillment'] ?? null;
                if (!$mode || $mode === $orderType) continue;
                $old = $oldLines->get($line['line_uid'] ?? $line['cart_uid'] ?? '');
                if (!$old || $old->fulfillment !== $mode || $existing->order_type !== $orderType) {
                    throw ValidationException::withMessages(['items' => 'Use one order type per bill. Write Parcel in the item note; make a separate bill for Delivery.']);
                }
            }
        }
        $uids = [];
        foreach ($request->input('items', []) as $line) {
            if (empty($line['fulfillment'])) continue;
            if (!Schema::hasColumn('restaurant_order_items', 'fulfillment')) {
                throw ValidationException::withMessages(['items' => 'Mixed service is unavailable until the update finishes. Your order has not been changed.']);
            }
            $uid = $line['line_uid'] ?? $line['cart_uid'] ?? null;
            if ($uid && isset($uids[$uid])) throw ValidationException::withMessages(['items' => 'Each service portion must have its own line identity.']);
            if ($uid) $uids[$uid] = true;
        }
        if ($request->filled('offline_uuid') && self::isMixed($request->input('items', []), $request->input('order_type'))) {
            throw ValidationException::withMessages(['items' => 'Mixed service needs an online order. Keep the cart and reconnect before saving.']);
        }
        if (collect($request->input('items', []))->contains(fn ($line) => ($line['fulfillment'] ?? null) === 'delivery') && collect($request->input('items', []))->contains(fn ($line) => !empty($line['fulfillment']) && $line['fulfillment'] !== $request->input('order_type'))) {
            $request->validate(['delivery_address' => 'required|string|max:500', 'customer_phone' => 'required|string|max:50', 'customer_name' => 'nullable|string|max:255']);
        }
        if (collect($request->input('items', []))->contains(fn ($line) => ($line['fulfillment'] ?? null) === 'delivery') && !(PosFeatureService::forCompany($company)->delivery ?? false)) {
            throw ValidationException::withMessages(['items' => 'Delivery is not enabled for this company.']);
        }
    }

    public static function fields(array $line): array
    {
        if (empty($line['fulfillment'])) return []; // Preserve legacy records/clients.
        return [
            'fulfillment' => $line['fulfillment'],
            'line_uid' => $line['line_uid'] ?? $line['cart_uid'] ?? (string) Str::uuid(),
        ];
    }

    public static function isMixed(iterable $items, ?string $orderType): bool
    {
        $explicit = false;
        $modes = [];
        foreach ($items as $line) {
            $mode = is_array($line) ? ($line['fulfillment'] ?? null) : ($line->fulfillment ?? null);
            $explicit = $explicit || !empty($mode);
            $modes[$mode ?: ($orderType ?? '')] = true;
        }
        return $explicit && (count($modes) > 1 || !isset($modes[$orderType ?? '']));
    }

    /** Partition the existing invoice total using its final line tax/discount weights. */
    public static function collectionAmount(float $total, iterable $items, string $mode, float $discountRatio = 1, float $taxRate = 0, bool $inclusive = false): float
    {
        if ($mode === 'counter') return 0.0;
        $all = 0; $delivery = 0;
        foreach ($items as $line) {
            $net = max(0, round((float) $line->subtotal * $discountRatio, 2));
            $tax = ($inclusive || $line->is_tax_exempt) ? 0 : round($net * $taxRate / 100, 2);
            $weight = (int) round(($net + $tax) * 100);
            $all += $weight;
            if ($line->fulfillment === 'delivery' || $line->skip_kitchen) $delivery += $weight;
        }
        // Invoice-level whole-rupee rounding is shared proportionally. The
        // counter receives the exact residual, so both portions sum to the bill.
        return $all > 0 ? min($total, round($total * $delivery / $all, 2)) : 0.0;
    }

    public static function isExplicit(iterable $items): bool
    {
        foreach ($items as $line) if (!empty(is_array($line) ? ($line['fulfillment'] ?? null) : $line->fulfillment)) return true;
        return false;
    }
    public static function applyCollection(\App\Models\PosTransaction $bill, Request $request, float $discountRatio, float $taxRate, bool $inclusive): void
    {
        if (!Schema::hasColumn('pos_transactions', 'has_delivery_items')) return;
        $items = $bill->items;
        $hasDelivery = self::hasDelivery($items, $bill->order_type);
        $fields = ['has_delivery_items' => $hasDelivery];
        if ($hasDelivery && self::isExplicit($items)) {
            $mode = $request->input('delivery_collection_mode') ?? ($bill->order_type === 'delivery' ? 'rider' : 'counter');
            if ($bill->payment_method !== 'cash') $mode = 'counter';
            $due = self::collectionAmount((float) $bill->total_amount, $items, $mode, $discountRatio, $taxRate, $inclusive);
            $fields['rider_collection_amount'] = $due;
            if ($bill->payment_method === 'cash') {
                $counterDue = round((float) $bill->total_amount - $due, 2);
                $fields['cash_received'] = $request->filled('cash_received') ? max(0, (float) $request->cash_received) : $counterDue;
                $fields['change_due'] = max(0, round($fields['cash_received'] - $counterDue, 2));
            }
        }
        $bill->update($fields);
    }

    public static function paymentResponse($bill): array
    {
        if ($bill->rider_collection_amount === null) return [];
        return ['rider_collection_amount' => (float) $bill->rider_collection_amount,
            'counter_amount' => round((float) $bill->total_amount - (float) $bill->rider_collection_amount, 2),
            'cash_received' => (float) $bill->cash_received, 'change_due' => (float) $bill->change_due];
    }

}
