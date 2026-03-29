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
