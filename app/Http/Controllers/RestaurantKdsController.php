<?php

namespace App\Http\Controllers;

use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use Illuminate\Http\Request;

class RestaurantKdsController extends Controller
{
    /**
     * Task 1356 — KDS rescue window. A bill can be finalised without the kitchen
     * ever seeing it (cashier hits CASH on a dine-in cart, or a takeaway counter
     * sale on a KDS-only shop). Those orders go straight to 'completed', so the
     * board's held/preparing/ready filter hid them and the food was never made.
     *
     * Rescue rule: a COMPLETED order that still has never-printed lines and was
     * never cleared stays on the board for the CURRENT business day only.
     * Clock-based on purpose (restaurant_orders has no business_date column and
     * this runs on every 15s poll): the window opens at the company's business-day
     * cutoff and spans at most 24h, so the board can never fill up with history.
     */
    private function rescueWindowStart(int $companyId): \Carbon\Carbon
    {
        $now = now();
        try {
            $cutoff = \App\Services\PosBusinessDay::cutoffFor($companyId);
            $start = $now->copy()->setTimeFromTimeString($cutoff)->startOfMinute();
            if ($start->greaterThan($now)) {
                $start->subDay(); // still trading yesterday's day (post-midnight)
            }
            return $start;
        } catch (\Throwable $e) {
            return $now->copy()->subDay();
        }
    }

    /**
     * Task 1356 — shared board filter for BOTH payload builders (index + the
     * liveOrders poll). They MUST stay in sync: the board renders the first from
     * Blade and then replaces it with the second every 15s.
     */
    private function boardOrders(int $companyId)
    {
        $rescueSince = $this->rescueWindowStart($companyId);

        return RestaurantOrder::where('company_id', $companyId)
            ->whereNull('kitchen_cleared_at')
            ->where(function ($q) use ($rescueSince) {
                $q->whereIn('status', ['held', 'preparing', 'ready'])
                    ->orWhere(function ($paid) use ($rescueSince) {
                        $paid->where('status', 'completed')
                            ->where('created_at', '>=', $rescueSince)
                            ->whereHas('items', fn ($i) => $i->whereNull('kot_printed_at'));
                    });
            })
            ->with(['table', 'items', 'creator'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function index()
    {
        $companyId = app('currentCompanyId');

        // KDS liveness heartbeat — see liveOrders().
        \Illuminate\Support\Facades\Cache::put('kds_seen_' . $companyId, time(), 300);

        // P5 (F4): the KDS board is driven by the KITCHEN lifecycle, not the billing
        // one. Cleared orders (scan or manual Clear) disappear from the board even
        // though the cashier still has them held for payment.
        // Task 1356: + paid-but-never-seen orders (see boardOrders()).
        $orders = $this->boardOrders((int) $companyId);

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

        // Waiter phone push (Task #1142): kitchen marked READY → tell the
        // creating waiter. Queued fire-and-forget (runs after the response
        // flushes) — a push problem can never fail the status change.
        if ($new === 'ready') {
            try {
                \App\Services\PosPushService::queueOrderReadyPush((int) $order->id);
            } catch (\Throwable $e) {
                // push is additive — status is already saved
            }
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

    /**
     * Task 855: server-side void acknowledgement — clears the cancelled-dish badge
     * for ALL KDS screens on this shop. Sets void_items = NULL so the next poll
     * returns an empty array and hides the badge everywhere.
     * NEVER touches kitchen_status, status, or any billing column.
     *
     * Race safety: the client sends the exact void_items array it observed
     * (expected_void). The UPDATE is conditioned on the stored value matching —
     * if a newer cancellation has replaced it before "Got it" was tapped, we
     * return 409 so the UI refreshes and the cook sees the new list instead.
     */
    public function ackVoid(Request $request, $orderId)
    {
        $companyId = app('currentCompanyId');

        $order = RestaurantOrder::where('company_id', $companyId)->find($orderId);
        if (!$order) {
            return response()->json(['success' => false, 'message' => "Order #{$orderId} not found"], 404);
        }

        // Already null — idempotent: another screen ack'd it first, all good.
        if ($order->void_items === null) {
            return response()->json(['success' => true, 'message' => "Already acknowledged"]);
        }

        // Encode what the client observed so we can compare with the stored value.
        $expectedRaw = $request->input('expected_void');
        $expectedJson = is_array($expectedRaw) ? json_encode($expectedRaw) : null;

        // Only clear when the stored JSON matches what the cook saw.
        // If a newer cancellation has since updated void_items, affected = 0 → 409.
        $query = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $order->id);

        if ($expectedJson !== null) {
            $query->where('void_items', $expectedJson);
        } else {
            $query->whereNotNull('void_items');
        }

        $affected = $query->update(['void_items' => null]);

        if ($affected === 0) {
            // A newer void list replaced the one this cook saw — send 409 so
            // the client refreshes and the cook can acknowledge the new list.
            return response()->json([
                'success' => false,
                'conflict' => true,
                'message' => "A newer cancellation has been added — please review and acknowledge again",
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => "Void acknowledged for {$order->order_number}",
        ]);
    }

    public function liveOrders()
    {
        $companyId = app('currentCompanyId');

        // KDS liveness heartbeat (Jul 2026, Pizza Master incident): the KDS board
        // polls this every 15s. Sale screens use this timestamp to decide whether
        // "KDS Auto Print" may suppress cashier-side auto-KOT — a CLOSED KDS must
        // never swallow kitchen tickets.
        \Illuminate\Support\Facades\Cache::put('kds_seen_' . $companyId, time(), 300);

        // Task 1356: same filter as index() — held/preparing/ready PLUS paid
        // orders the kitchen never saw and never cleared (see boardOrders()).
        $orders = $this->boardOrders((int) $companyId);

        // Counter/Station routing: resolve station per item ONCE for the whole
        // payload (2 bulk queries total — stations + product categories).
        $stations = \App\Models\PosStation::activeFor($companyId);
        $stationItemMap = $stations->isEmpty()
            ? []
            : \App\Models\PosStation::mapItems($companyId, $stations, $orders->pluck('items')->flatten(1));

        $orders = $orders->map(function ($o) use ($stationItemMap) {
                // Kitchen timer starts at KOT time (owner, Jul 2026): the kitchen's
                // clock runs from when the ticket was SENT (kot_sent_at), not when
                // the order row was created — fallback for legacy rows without it.
                // Carbon 3 signed diff — measure FROM start TO now so elapsed is positive.
                $kdsStart = $o->kot_sent_at ?: $o->created_at;
                $elapsed = (int) $kdsStart->diffInMinutes(now());
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
                    // Task 841: cancelled items for KDS badge (null or []).
                    'void_items' => $o->void_items ? json_decode($o->void_items, true) : [],
                    'source' => $o->source ?? 'pos',
                    'created_by' => $o->creator?->name ?? 'Unknown',
                    'elapsed_minutes' => $elapsed,
                    'is_urgent' => $elapsed > 15,
                    'created_at' => $kdsStart->format('H:i'),
                ];
            });

        return response()->json($orders);
    }
}
