<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosProduct;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * P7 (F6) — Waiter Tablets.
 *
 * Waiters compose orders on a tablet (customer, table, items) and SEND them to a
 * chosen cashier. Orders are plain RestaurantOrder rows (status 'held',
 * source='waiter', assigned_cashier_id set) so KDS, tables, and day-close all
 * see them with ZERO new lifecycle. The cashier finalizes payment through the
 * normal storeInvoice path — the monthly bill quota applies THERE only; waiter
 * sends are free. Delta-KOT: restaurant_order_items.kot_printed_at is stamped
 * when a ticket actually prints, so appended items print alone.
 */
class RestaurantWaiterController extends Controller
{
    /** Restaurant surfaces are Pro/Unlimited only (P2) — same flags the sale screen uses. */
    private function restaurantOn(Company $company): bool
    {
        $features = PosFeatureService::forCompany($company);
        return (bool) (($features->tables ?? false) || ($features->kot ?? false) || ($features->kitchen ?? false) || ($features->restaurant_mode ?? false));
    }

    public function index()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = auth('pos')->user();

        // Waiters, admins and managers may open the tablet; cashiers have their own screen.
        if ($user->isPosCashier() || $user->isPosKitchen()) {
            return redirect('/pos/invoice/create');
        }
        if (!$this->restaurantOn($company)) {
            abort(403, 'Restaurant features are not enabled for this company.');
        }

        $products = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) ($p->price ?? 0),
                'category' => $p->category ?: 'General',
                'barcode' => $p->barcode ?: null,
                'show_on_sale' => (bool) ($p->show_on_sale ?? true),
                'is_tax_exempt' => (bool) ($p->is_tax_exempt ?? false),
            ])
            ->filter(fn($p) => $p['show_on_sale'])
            ->values();

        $cashiers = User::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereIn('pos_role', ['pos_admin', 'pos_manager', 'pos_cashier'])
                  ->orWhere('role', 'company_admin');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'pos_role']);

        // Item #5 (owner, Jul 2026): running total shows an "≈ incl. tax" ESTIMATE.
        // Cash rate only — the waiter never knows the final payment method; the REAL
        // tax is computed by the cashier's settle path (storeInvoice), never here.
        $cashTaxRate = \App\Models\PosTaxRule::getRateForMethod('cash', $company);

        return view('pos.waiter', compact('company', 'products', 'cashiers', 'cashTaxRate'));
    }

    /** Live floors + tables — waiter-scoped twin of the sale screen's table-status API. */
    public function tables()
    {
        $companyId = app('currentCompanyId');
        RestaurantTable::releaseStaleReservations($companyId);

        $tables = RestaurantTable::where('company_id', $companyId)
            ->where('is_active', true)
            ->with(['floor', 'activeOrders'])
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'table_number' => $t->table_number,
                'floor' => $t->floor->name,
                'seats' => $t->seats,
                'status' => $t->status,
                'active_orders' => $t->activeOrders->count(),
            ]);

        return response()->json($tables);
    }

    /** Waiter's own open (still-held) sent orders — for append + status view. */
    public function myOrders()
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        $orders = RestaurantOrder::where('company_id', $companyId)
            ->where('source', 'waiter')
            ->where('status', 'held')
            ->where('created_by', $user->id)
            ->with(['items', 'table', 'assignedCashier'])
            ->orderByDesc('id')
            ->get()
            ->map(fn($o) => $this->orderJson($o));

        return response()->json($orders);
    }

    public function storeOrder(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = auth('pos')->user();

        if ($user->isPosCashier() || $user->isPosKitchen()) {
            return response()->json(['success' => false, 'message' => 'Not allowed.'], 403);
        }
        if (!$this->restaurantOn($company)) {
            return response()->json(['success' => false, 'message' => 'Restaurant features are not enabled.'], 403);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01|max:9999',
            'items.*.unit_price' => 'required|numeric|min:0|max:99999999',
            'items.*.item_id' => 'nullable|integer',
            'items.*.special_notes' => 'nullable|string|max:500',
            'cashier_id' => 'nullable|integer',
            'table_id' => 'nullable|integer',
            'order_type' => 'nullable|in:dine_in,takeaway,delivery',
            'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'nullable|string|max:30',
            'kitchen_notes' => 'nullable|string|max:500',
        ]);

        // Cashier pick is OPTIONAL (customer feedback, 23 Jul 2026). No pick =
        // unassigned order → EVERY cashier's incoming list shows it (incomingOrders
        // already treats NULL assigned_cashier_id as "for anyone"). When a cashier
        // IS chosen, they must be a real, active billing account of THIS company.
        $cashier = null;
        if (!empty($validated['cashier_id'])) {
            $cashier = User::where('company_id', $companyId)
                ->where('id', $validated['cashier_id'])
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereIn('pos_role', ['pos_admin', 'pos_manager', 'pos_cashier'])
                      ->orWhere('role', 'company_admin');
                })
                ->first();
            if (!$cashier) {
                return response()->json(['success' => false, 'message' => 'Selected cashier not found.'], 422);
            }
        }

        $orderType = $validated['order_type'] ?? 'dine_in';
        $tableId = $orderType === 'dine_in' ? ($validated['table_id'] ?? null) : null;

        return DB::transaction(function () use ($companyId, $validated, $cashier, $orderType, $tableId, $user) {
            if ($tableId) {
                $table = RestaurantTable::where('company_id', $companyId)
                    ->where('id', $tableId)->where('is_active', true)
                    ->lockForUpdate()->first();
                if (!$table) {
                    return response()->json(['success' => false, 'message' => 'Table not found.'], 422);
                }
                if ($table->status === 'occupied') {
                    return response()->json(['success' => false, 'message' => 'Table T-' . $table->table_number . ' is occupied.'], 409);
                }
                $table->update(['status' => 'occupied', 'occupied_since' => $table->occupied_since ?: now()]);
            }

            $subtotal = 0;
            foreach ($validated['items'] as $it) {
                $subtotal += round((float) $it['quantity'] * (float) $it['unit_price'], 2);
            }
            $subtotal = round($subtotal, 2);
            // Indicative totals only — the REAL bill (tax, discount, PRA) is
            // computed by storeInvoice when the cashier takes payment.
            // Whole-rupee total per POS rounding convention.
            $total = round($subtotal);

            $order = RestaurantOrder::create([
                'company_id' => $companyId,
                'order_number' => 'ORD-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)),
                'table_id' => $tableId,
                'order_type' => $orderType,
                'status' => 'held',
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => 0,
                'total_amount' => $total,
                'kitchen_notes' => $validated['kitchen_notes'] ?? null,
                'created_by' => $user->id,
                'assigned_cashier_id' => $cashier?->id,
                'source' => 'waiter',
                'kot_sent_at' => now(),
                'kot_print_count' => 0,
            ]);

            foreach ($validated['items'] as $it) {
                RestaurantOrderItem::create([
                    'order_id' => $order->id,
                    'item_type' => !empty($it['item_id']) ? 'product' : 'manual',
                    'item_id' => $it['item_id'] ?? null,
                    'item_name' => $it['name'],
                    'quantity' => (float) $it['quantity'],
                    'unit_price' => round((float) $it['unit_price'], 2),
                    'subtotal' => round((float) $it['quantity'] * (float) $it['unit_price'], 2),
                    'special_notes' => $it['special_notes'] ?? null,
                    // kot_printed_at stays NULL — stamped when a ticket actually prints.
                ]);
            }

            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'message' => $cashier ? 'Order sent to ' . $cashier->name . '.' : 'Order sent to counter.',
            ]);
        });
    }

    /** Append items to an already-sent held order — the delta prints alone (kot_printed_at NULL rows). */
    public function appendItems(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01|max:9999',
            'items.*.unit_price' => 'required|numeric|min:0|max:99999999',
            'items.*.item_id' => 'nullable|integer',
            'items.*.special_notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($companyId, $validated, $user, $id) {
            $order = RestaurantOrder::where('company_id', $companyId)
                ->where('id', $id)
                ->where('source', 'waiter')
                ->where('status', 'held')
                ->lockForUpdate()
                ->first();
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found or already settled.'], 404);
            }
            // Only the sending waiter (or an admin/manager) may append.
            // Int-cast both sides — some live PDO setups return int columns as strings,
            // which made strict !== flag the waiter's OWN order as "Not your order."
            if ((int) $order->created_by !== (int) $user->id && !$user->isPosAdmin()) {
                return response()->json(['success' => false, 'message' => 'Not your order.'], 403);
            }

            $added = 0;
            foreach ($validated['items'] as $it) {
                RestaurantOrderItem::create([
                    'order_id' => $order->id,
                    'item_type' => !empty($it['item_id']) ? 'product' : 'manual',
                    'item_id' => $it['item_id'] ?? null,
                    'item_name' => $it['name'],
                    'quantity' => (float) $it['quantity'],
                    'unit_price' => round((float) $it['unit_price'], 2),
                    'subtotal' => round((float) $it['quantity'] * (float) $it['unit_price'], 2),
                    'special_notes' => $it['special_notes'] ?? null,
                ]);
                $added += round((float) $it['quantity'] * (float) $it['unit_price'], 2);
            }

            $newSubtotal = round((float) $order->subtotal + $added, 2);
            $order->update([
                'subtotal' => $newSubtotal,
                'total_amount' => round($newSubtotal),
                'kot_sent_at' => now(),
            ]);

            return response()->json(['success' => true, 'message' => 'Items added — kitchen gets a delta ticket.']);
        });
    }

    /** Cashier side — waiter orders waiting for payment (mine or unassigned; admins see all). */
    public function incomingOrders()
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        $q = RestaurantOrder::where('company_id', $companyId)
            ->where('source', 'waiter')
            ->where('status', 'held')
            ->with(['items', 'table', 'creator', 'assignedCashier']);

        // Cashiers see orders sent to THEM (or unassigned); admins/managers see all.
        if ($user->isPosCashier()) {
            $q->where(function ($w) use ($user) {
                $w->where('assigned_cashier_id', $user->id)->orWhereNull('assigned_cashier_id');
            });
        }

        $orders = $q->orderBy('id')->get()->map(fn($o) => $this->orderJson($o));

        return response()->json($orders);
    }

    /**
     * Atomic claim for the sale screen's AUTO-LOAD (waiter easy-pickup, Jul 2026).
     * Two idle terminals polling the same unassigned order must never BOTH load
     * it (payment runs before settlement's atomic claim → duplicate final bill).
     * Conditional UPDATE = single-winner: sets assigned_cashier_id to the caller
     * only while the order is still held and unassigned (or already theirs).
     */
    public function claimIncoming(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        $claimQuery = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $id)
            ->where('source', 'waiter')
            ->where('status', 'held');
        // Admin/manager override (Jul 2026): admins see ALL held waiter orders in
        // the table picker — without an override, an order assigned to an
        // off-shift cashier stays stuck for everyone else. Admin claim simply
        // re-assigns it (single-winner UPDATE still holds per request).
        if (!$user->isPosAdmin()) {
            $claimQuery->where(function ($w) use ($user) {
                $w->whereNull('assigned_cashier_id')->orWhere('assigned_cashier_id', $user->id);
            });
        }
        $claimed = $claimQuery->update(['assigned_cashier_id' => $user->id]);

        if (!$claimed) {
            // MySQL reports 0 affected rows when the value is unchanged (order
            // already assigned to this same cashier) — re-check before failing.
            $mine = RestaurantOrder::where('company_id', $companyId)
                ->where('id', $id)->where('source', 'waiter')->where('status', 'held')
                ->where('assigned_cashier_id', $user->id)->exists();
            if (!$mine) {
                return response()->json(['success' => false, 'message' => 'Order already taken by another cashier.'], 409);
            }
        }

        // Return the FRESH order snapshot (table-se-bill flow, Jul 2026): the
        // cashier's polled copy can be stale — a waiter may have appended items
        // between the poll and the claim. Cart must build from THIS, not the
        // stale client object. (Post-claim appends remain a known limitation —
        // same as the old drawer flow.)
        $order = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $id)
            ->with(['items', 'table', 'creator', 'assignedCashier'])
            ->first();

        return response()->json(['success' => true, 'order' => $order ? $this->orderJson($order) : null]);
    }

    /** Link the paid PosTransaction to the waiter order, mark completed, free the table. */
    public function completeIncoming(Request $request, $id)
    {
        $companyId = app('currentCompanyId');

        $validated = $request->validate([
            'transaction_id' => 'required|integer',
        ]);

        $txn = \App\Models\PosTransaction::where('company_id', $companyId)
            ->where('id', $validated['transaction_id'])
            ->first();
        if (!$txn) {
            return response()->json(['success' => false, 'message' => 'Transaction not found.'], 422);
        }

        // Atomic claim — double-click / two cashiers can't settle the same order twice.
        $claimed = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $id)
            ->where('source', 'waiter')
            ->where('status', 'held')
            ->update([
                'status' => 'completed',
                'pos_transaction_id' => $txn->id,
                'payment_method' => $txn->payment_method,
                'updated_at' => now(),
            ]);
        if (!$claimed) {
            return response()->json(['success' => false, 'message' => 'Order already settled.'], 409);
        }

        $order = RestaurantOrder::where('company_id', $companyId)->with('table')->find($id);

        // Free the table if no other live order still sits on it (P4 pattern).
        if ($order && $order->table_id) {
            $stillActive = RestaurantOrder::where('company_id', $companyId)
                ->where('table_id', $order->table_id)
                ->where('id', '!=', $order->id)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->exists();
            if (!$stillActive) {
                RestaurantTable::where('id', $order->table_id)
                    ->update(['status' => 'available', 'locked_by_user_id' => null, 'locked_at' => null, 'occupied_since' => null]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Waiter order settled.']);
    }

    private function orderJson(RestaurantOrder $o): array
    {
        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'order_type' => $o->order_type,
            'table_id' => $o->table_id,
            'table' => $o->table ? $o->table->table_number : null,
            'customer_name' => $o->customer_name,
            'customer_phone' => $o->customer_phone,
            'kitchen_notes' => $o->kitchen_notes,
            'waiter' => $o->creator?->name ?? 'Unknown',
            'assigned_cashier' => $o->assignedCashier?->name,
            'assigned_cashier_id' => $o->assigned_cashier_id,
            'subtotal' => (float) $o->subtotal,
            'total_amount' => (float) $o->total_amount,
            'unprinted_count' => $o->items->whereNull('kot_printed_at')->count(),
            'items' => $o->items->map(fn($i) => [
                'item_id' => $i->item_id,
                'item_type' => $i->item_type,
                'name' => $i->item_name,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'special_notes' => $i->special_notes,
                'is_tax_exempt' => (bool) $i->is_tax_exempt,
                'printed' => $i->kot_printed_at !== null,
            ])->values(),
            'created_at' => $o->created_at->format('H:i'),
        ];
    }
}
