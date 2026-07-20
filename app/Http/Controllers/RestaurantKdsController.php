<?php

namespace App\Http\Controllers;

use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use Illuminate\Http\Request;

class RestaurantKdsController extends Controller
{
    public function index()
    {
        $companyId = app('currentCompanyId');

        // P5 (F4): the KDS board is driven by the KITCHEN lifecycle, not the billing
        // one. Cleared orders (scan or manual Clear) disappear from the board even
        // though the cashier still has them held for payment.
        $orders = RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->whereNull('kitchen_cleared_at')
            ->with(['table', 'items', 'creator'])
            ->orderBy('created_at', 'asc')
            ->get();

        // P6 (F5): KDS auto-print flag — the KDS device prints new-order KOTs itself.
        $company = \App\Models\Company::find($companyId);
        $kdsAutoPrint = (bool) ($company->pos_kds_auto_print ?? false);

        // Silent printer routing: when the Desktop Agent has a KOT printer set,
        // KDS prints route through the print-jobs queue instead of the iframe.
        $ps = $company ? $company->printerSettings() : ['silent_print_enabled' => false, 'kot_printer' => null];
        $kdsSilentKot = (bool) ($ps['silent_print_enabled'] && $ps['kot_printer']);

        // Counter/Station routing (Jul 2026): this KDS device can pin itself to
        // one counter — cards/items/prints then cover ONLY that counter's dishes.
        $kdsStations = \App\Models\PosStation::activeFor($companyId);
        $stationItemMap = $kdsStations->isEmpty()
            ? []
            : \App\Models\PosStation::mapItems($companyId, $kdsStations, $orders->pluck('items')->flatten(1));

        return view('pos.restaurant.kds', compact('orders', 'kdsAutoPrint', 'kdsSilentKot', 'kdsStations', 'stationItemMap'));
    }

    /**
     * P5 (F4) — kitchen-side status change. NEVER touches restaurant_orders.status
     * (that drives tables + cashier billing). kitchen_status lifecycle:
     * NULL (new) → preparing → ready → cleared. Clear allowed from ANY state
     * (manual "Clear" button mirrors the scan-to-clear contract).
     */
    public function kitchenStatus(Request $request, $orderId)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'kitchen_status' => 'required|in:preparing,ready,cleared',
        ]);
        $new = $request->kitchen_status;

        $order = RestaurantOrder::where('company_id', $companyId)->findOrFail($orderId);

        if ($order->kitchen_cleared_at) {
            return response()->json([
                'success' => false,
                'message' => "Order {$order->order_number} already cleared",
            ], 400);
        }

        $updates = ['kitchen_status' => $new];
        if ($new === 'preparing' && !$order->kitchen_started_at) {
            $updates['kitchen_started_at'] = now();
        }
        if ($new === 'ready') {
            $updates['kitchen_ready_at'] = $order->kitchen_ready_at ?: now();
            $updates['kitchen_started_at'] = $order->kitchen_started_at ?: now();
        }
        if ($new === 'cleared') {
            $updates['kitchen_cleared_at'] = now();
        }

        // Race-safe: a concurrent scan may clear the order between our read and
        // write — the WHERE excludes it so we never overwrite cleared_at.
        $affected = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $order->id)
            ->whereNull('kitchen_cleared_at')
            ->update($updates);

        if ($affected === 0) {
            return response()->json([
                'success' => false,
                'message' => "Order {$order->order_number} already cleared",
            ], 400);
        }

        $label = $new === 'cleared' ? 'CLEARED' : ucfirst($new);
        return response()->json([
            'success' => true,
            'kitchen_status' => $new,
            'message' => "Order {$order->order_number} → {$label}",
        ]);
    }

    public function updateStatus(Request $request, $orderId)
    {
        $companyId = app('currentCompanyId');

        $order = RestaurantOrder::where('company_id', $companyId)->findOrFail($orderId);

        $request->validate([
            'status' => 'required|in:held,preparing,ready,completed,cancelled',
        ]);

        $newStatus = $request->status;

        $validTransitions = [
            'held' => ['preparing', 'cancelled'],
            'preparing' => ['ready', 'cancelled'],
            'ready' => ['cancelled'],
        ];

        $allowed = $validTransitions[$order->status] ?? [];
        if (!in_array($newStatus, $allowed)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot change from {$order->status} to {$newStatus}",
            ], 400);
        }

        $order->update(['status' => $newStatus]);

        return response()->json([
            'success' => true,
            'message' => "Order {$order->order_number} → " . ucfirst($newStatus),
        ]);
    }

    /**
     * Scan-to-Clear endpoint (P5, F4 — owner rule Jul 2026) — kitchen scanner or
     * camera reads "KOT-{id}" from the printed ticket. Scan = CLEAR from ANY
     * kitchen state: the dish is done and handed off, remove it from the board.
     * NEVER touches restaurant_orders.status — the cashier's held bill survives.
     */
    public function scanComplete(Request $request)
    {
        $companyId = app('currentCompanyId');
        $code = trim((string) $request->input('code', ''));

        // Strict barcode contract — kitchen tickets always print "KOT-{id}". Reject anything
        // else (incl. bare digits) so a stray scan of an unrelated barcode on the floor
        // cannot accidentally clear a kitchen order.
        if (!preg_match('/^KOT-(\d+)$/', $code, $m)) {
            return response()->json(['success' => false, 'message' => 'Invalid KOT barcode'], 400);
        }
        $orderId = (int) $m[1];

        // Atomic conditional update — race-safe. If another scan/manual clear
        // landed between our read and write, affected-rows = 0 → correct
        // "already cleared" response without double-stamping cleared_at.
        $affected = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $orderId)
            ->whereNull('kitchen_cleared_at')
            ->update([
                'kitchen_status' => 'cleared',
                'kitchen_cleared_at' => now(),
            ]);

        $order = RestaurantOrder::where('company_id', $companyId)->find($orderId);
        if (!$order) {
            return response()->json(['success' => false, 'message' => "Order #{$orderId} not found"], 404);
        }

        if ($affected === 0) {
            return response()->json([
                'success' => true,
                'already_cleared' => true,
                'order_id' => $order->id,
                'message' => "Order {$order->order_number} already CLEARED",
            ]);
        }

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'message' => "✓ {$order->order_number} CLEARED",
        ]);
    }

    /**
     * Clear All (owner, 20 Jul 2026) — bulk manual clear of the kitchen board.
     * Client sends the EXPLICIT ids currently visible on ITS board (a station-pinned
     * display must never clear other counters' orders). Company-scoped, race-safe:
     * already-cleared rows are excluded by the WHERE, never double-stamped.
     * NEVER touches restaurant_orders.status — cashiers' held bills survive.
     */
    public function clearAll(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
        ]);

        $cleared = RestaurantOrder::where('company_id', $companyId)
            ->whereIn('id', $request->ids)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->whereNull('kitchen_cleared_at')
            ->update([
                'kitchen_status' => 'cleared',
                'kitchen_cleared_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'cleared' => $cleared,
            'message' => $cleared > 0 ? "{$cleared} order(s) cleared from the board" : 'Nothing to clear',
        ]);
    }

    public function liveOrders()
    {
        $companyId = app('currentCompanyId');

        $orders = RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->whereNull('kitchen_cleared_at')
            ->with(['table', 'items', 'creator'])
            ->orderBy('created_at', 'asc')
            ->get();

        // Counter/Station routing: resolve station per item ONCE for the whole
        // payload (2 bulk queries total — stations + product categories).
        $stations = \App\Models\PosStation::activeFor($companyId);
        $stationItemMap = $stations->isEmpty()
            ? []
            : \App\Models\PosStation::mapItems($companyId, $stations, $orders->pluck('items')->flatten(1));

        $orders = $orders->map(function ($o) use ($stationItemMap) {
                // Carbon 3 signed diff — measure FROM created_at TO now so elapsed is positive.
                $elapsed = (int) $o->created_at->diffInMinutes(now());
                return [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'status' => $o->status,
                    'kitchen_status' => $o->kitchen_status ?: 'new',
                    'order_type' => $o->order_type,
                    'priority' => (bool)$o->priority,
                    'table' => $o->table ? $o->table->table_number : null,
                    'items' => $o->items->map(fn($i) => [
                        'name' => $i->item_name,
                        'qty' => $i->quantity,
                        'notes' => $i->special_notes,
                        'station_id' => $stationItemMap[$i->id] ?? 0,
                    ]),
                    'kitchen_notes' => $o->kitchen_notes,
                    // P7 delta-KOT: appended (not-yet-printed) rows — KDS auto-print
                    // fires a delta ticket when this is > 0 on an already-printed order.
                    'unprinted_count' => $o->items->whereNull('kot_printed_at')->count(),
                    // Per-station unprinted counts — a pinned counter's KDS fires its
                    // delta ONLY when ITS bucket grew (order-wide count would fire
                    // blank tickets on other counters). Keys are station-id strings.
                    'unprinted_by_station' => (object) $o->items->whereNull('kot_printed_at')
                        ->groupBy(fn ($i) => (string) ($stationItemMap[$i->id] ?? 0))
                        ->map->count()->toArray(),
                    'source' => $o->source ?? 'pos',
                    'created_by' => $o->creator?->name ?? 'Unknown',
                    'elapsed_minutes' => $elapsed,
                    'is_urgent' => $elapsed > 15,
                    'created_at' => $o->created_at->format('H:i'),
                ];
            });

        return response()->json($orders);
    }
}
