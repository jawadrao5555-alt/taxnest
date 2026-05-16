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

        $orders = RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->with(['table', 'items', 'creator'])
            ->orderBy('created_at', 'asc')
            ->get();

        return view('pos.restaurant.kds', compact('orders'));
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
     * Scan-to-Ready endpoint — kitchen scanner reads "KOT-{id}" barcode from
     * the printed ticket. Bypasses normal transition validation (held→preparing→ready)
     * and jumps directly to "ready" regardless of current state. This is the kitchen
     * staff's "I'm done with this dish" signal.
     */
    public function scanComplete(Request $request)
    {
        $companyId = app('currentCompanyId');
        $code = trim((string) $request->input('code', ''));

        // Strict barcode contract — kitchen tickets always print "KOT-{id}". Reject anything
        // else (incl. bare digits) so a stray scan of an unrelated barcode on the floor
        // cannot accidentally mark a kitchen order ready.
        if (!preg_match('/^KOT-(\d+)$/', $code, $m)) {
            return response()->json(['success' => false, 'message' => 'Invalid KOT barcode'], 400);
        }
        $orderId = (int) $m[1];

        // Atomic conditional update — race-safe. If another process (payment flow,
        // cancel) flipped the order to completed/cancelled between our read and write,
        // the WHERE clause excludes it and affected-rows = 0 → we return the correct
        // "already X" response without overwriting a terminal state.
        $affected = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $orderId)
            ->whereIn('status', ['held', 'preparing'])
            ->update(['status' => 'ready']);

        $order = RestaurantOrder::where('company_id', $companyId)->find($orderId);
        if (!$order) {
            return response()->json(['success' => false, 'message' => "Order #{$orderId} not found"], 404);
        }

        if ($affected === 0) {
            if ($order->status === 'ready') {
                return response()->json([
                    'success' => true,
                    'already_ready' => true,
                    'order_id' => $order->id,
                    'message' => "Order {$order->order_number} already READY",
                ]);
            }
            return response()->json([
                'success' => false,
                'message' => "Order {$order->order_number} is already {$order->status}",
            ], 400);
        }

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'message' => "✓ {$order->order_number} → READY",
        ]);
    }

    public function liveOrders()
    {
        $companyId = app('currentCompanyId');

        $orders = RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->with(['table', 'items', 'creator'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($o) {
                $elapsed = now()->diffInMinutes($o->created_at);
                return [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'status' => $o->status,
                    'order_type' => $o->order_type,
                    'priority' => (bool)$o->priority,
                    'table' => $o->table ? $o->table->table_number : null,
                    'items' => $o->items->map(fn($i) => [
                        'name' => $i->item_name,
                        'qty' => $i->quantity,
                        'notes' => $i->special_notes,
                    ]),
                    'kitchen_notes' => $o->kitchen_notes,
                    'created_by' => $o->creator?->name ?? 'Unknown',
                    'elapsed_minutes' => $elapsed,
                    'is_urgent' => $elapsed > 15,
                    'created_at' => $o->created_at->format('H:i'),
                ];
            });

        return response()->json($orders);
    }
}
