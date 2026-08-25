<?php

namespace App\Http\Controllers;

use App\Models\RestaurantFloor;
use App\Models\RestaurantTable;
use App\Models\RestaurantOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RestaurantTableController extends Controller
{
    /**
     * Floors/tables setup is an admin surface (owner rule: every POS
     * settings-style write path must 403 cashiers). pos_manager passes —
     * only pos_cashier is blocked. Lock/reserve/release stay open to
     * cashiers (they legitimately use those during service).
     */
    private function denyCashier(): void
    {
        $user = auth('pos')->user();
        if ($user && $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can manage floors and tables.');
        }
    }

    public function index()
    {
        $companyId = app('currentCompanyId');
        RestaurantTable::releaseStaleReservations($companyId);

        $floors = RestaurantFloor::where('company_id', $companyId)
            ->where('is_active', true)
            ->with(['tables' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order');
            }])
            ->orderBy('sort_order')
            ->get();

        // ZFC (11 Aug 2026): dashboard "Open orders" tile links HERE, but a held
        // order WITHOUT a table (e.g. a held delivery) was invisible on this page
        // — shop clicked the "1 pending" tile and found nothing. Same statuses as
        // the dashboard counter so the numbers always reconcile.
        $openOrders = RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->with('table')
            ->orderBy('created_at')
            ->get();

        // Task 976: Takeaway/Delivery quick-start buttons on the board.
        // Pass a simple boolean so the blade doesn't need to call PosFeatureService itself.
        $tvDeliveryEnabled = false;
        try {
            $tvCompany = \App\Models\Company::find($companyId);
            if ($tvCompany) {
                $tvDeliveryEnabled = (bool) (\App\Services\PosFeatureService::forCompany($tvCompany)->delivery ?? false);
            }
        } catch (\Throwable $e) { /* silently disable if service unavailable */ }

        return view('pos.restaurant.tables', compact('floors', 'openOrders', 'tvDeliveryEnabled'));
    }

    public function manage()
    {
        $this->denyCashier();
        $companyId = app('currentCompanyId');

        $floors = RestaurantFloor::where('company_id', $companyId)
            ->with(['tables' => function ($q) {
                $q->orderBy('sort_order');
            }])
            ->orderBy('sort_order')
            ->get();

        $company = \App\Models\Company::find($companyId);

        return view('pos.restaurant.table-management', compact('floors', 'company'));
    }

    /**
     * Task 779 — Tables-first flow (opt-in, default OFF). ON = after a dine-in
     * KOT send / after the receipt popup closes, the cashier returns to the
     * full-screen Tables page instead of the small table-picker popup.
     * Settings write path → cashier 403 (owner rule). hasColumn guard = prod
     * self-heal parity (code may land before migrate --force).
     */
    public function updateTablesFirstFlow(Request $request)
    {
        $this->denyCashier();
        $companyId = app('currentCompanyId');

        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'tables_first_flow')) {
            \App\Models\Company::where('id', $companyId)
                ->update(['tables_first_flow' => $request->boolean('tables_first_flow'), 'updated_at' => now()]);
        }

        return back()->with('success', __('pos.tables_first_flow_saved'));
    }

    /**
     * Task 781 — Table click = seedha bill kholo (opt-in, default OFF). ON =
     * clicking an occupied table loads its order straight into the cart in
     * edit mode (no action popup); the popup's actions move into the payment
     * panel. Settings write path → cashier 403 (owner rule). hasColumn guard
     * = prod self-heal parity (code may land before migrate --force).
     */
    public function updateTableClickDirectOpen(Request $request)
    {
        $this->denyCashier();
        $companyId = app('currentCompanyId');

        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'table_click_direct_open')) {
            \App\Models\Company::where('id', $companyId)
                ->update(['table_click_direct_open' => $request->boolean('table_click_direct_open'), 'updated_at' => now()]);
        }

        return back()->with('success', __('pos.table_direct_open_saved'));
    }

    public function storeFloor(Request $request)
    {
        $this->denyCashier();
        $companyId = app('currentCompanyId');

        $request->validate(['name' => 'required|string|max:100']);

        $maxSort = RestaurantFloor::where('company_id', $companyId)->max('sort_order') ?? 0;

        $floor = RestaurantFloor::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'sort_order' => $maxSort + 1,
        ]);

        return back()->with('success', "Floor \"{$floor->name}\" created.");
    }

    public function updateFloor(Request $request, $floorId)
    {
        $this->denyCashier();
        $companyId = app('currentCompanyId');
        $request->validate(['name' => 'required|string|max:100']);

        $floor = RestaurantFloor::where('company_id', $companyId)->findOrFail($floorId);
        $floor->update(['name' => $request->name, 'is_active' => $request->boolean('is_active', true)]);

        return back()->with('success', "Floor updated.");
    }

    public function deleteFloor($floorId)
    {
        $this->denyCashier();
        $companyId = app('currentCompanyId');
        $floor = RestaurantFloor::where('company_id', $companyId)->findOrFail($floorId);

        $activeTables = $floor->tables()->where('is_active', true)->count();
        if ($activeTables > 0) {
            return back()->with('error', "Cannot delete floor with active tables. Deactivate tables first.");
        }

        $floor->delete();
        return back()->with('success', "Floor deleted.");
    }

    public function storeTable(Request $request)
    {
        $this->denyCashier();
        $companyId = app('currentCompanyId');

        $request->validate([
            'floor_id' => 'required|exists:restaurant_floors,id',
            'table_number' => 'required|string|max:20',
            'seats' => 'required|integer|min:1|max:50',
        ]);

        $floor = RestaurantFloor::where('company_id', $companyId)->findOrFail($request->floor_id);

        $exists = RestaurantTable::where('company_id', $companyId)
            ->where('table_number', $request->table_number)
            ->exists();

        if ($exists) {
            return back()->with('error', "Table \"{$request->table_number}\" already exists.");
        }

        $maxSort = RestaurantTable::where('company_id', $companyId)->where('floor_id', $floor->id)->max('sort_order') ?? 0;

        RestaurantTable::create([
            'company_id' => $companyId,
            'floor_id' => $floor->id,
            'table_number' => $request->table_number,
            'seats' => $request->seats,
            'sort_order' => $maxSort + 1,
        ]);

        return back()->with('success', "Table \"{$request->table_number}\" added to {$floor->name}.");
    }

    public function updateTable(Request $request, $tableId)
    {
        $this->denyCashier();
        $companyId = app('currentCompanyId');

        $request->validate([
            'table_number' => 'required|string|max:20',
            'seats' => 'required|integer|min:1|max:50',
        ]);

        $table = RestaurantTable::where('company_id', $companyId)->findOrFail($tableId);

        $dup = RestaurantTable::where('company_id', $companyId)
            ->where('table_number', $request->table_number)
            ->where('id', '!=', $tableId)
            ->exists();

        if ($dup) {
            return back()->with('error', "Table number already exists.");
        }

        $table->update([
            'table_number' => $request->table_number,
            'seats' => $request->seats,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', "Table updated.");
    }

    public function deleteTable($tableId)
    {
        $this->denyCashier();
        $companyId = app('currentCompanyId');
        $table = RestaurantTable::where('company_id', $companyId)->findOrFail($tableId);

        $activeOrders = RestaurantOrder::where('table_id', $tableId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->exists();

        if ($activeOrders) {
            return back()->with('error', "Cannot delete table with active orders.");
        }

        $table->delete();
        return back()->with('success', "Table deleted.");
    }

    public function lockTable(Request $request, $tableId)
    {
        $companyId = app('currentCompanyId');
        $user = Auth::guard('pos')->user();
        $table = RestaurantTable::where('company_id', $companyId)->findOrFail($tableId);

        if ($table->isLockedByOther($user->id)) {
            return response()->json(['success' => false, 'message' => 'Table locked by another user'], 423);
        }

        $table->update([
            'locked_by_user_id' => $user->id,
            'locked_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function unlockTable($tableId)
    {
        $companyId = app('currentCompanyId');
        $table = RestaurantTable::where('company_id', $companyId)->findOrFail($tableId);

        $table->update(['locked_by_user_id' => null, 'locked_at' => null]);

        return response()->json(['success' => true]);
    }

    /**
     * F3 Dine-In (Jul 2026) — reserve a table from the universal sale screen.
     * Race-safe conditional claim: available tables, your own reservation, or a
     * stale (30min+) reservation may be claimed; occupied tables never.
     */
    public function reserveTable($tableId)
    {
        $companyId = app('currentCompanyId');
        $user = Auth::guard('pos')->user();

        $table = RestaurantTable::where('company_id', $companyId)
            ->where('is_active', true)
            ->find($tableId);
        if (!$table) {
            return response()->json(['success' => false, 'message' => 'Table not found'], 404);
        }
        if ($table->status === 'occupied') {
            return response()->json(['success' => false, 'message' => "Table T-{$table->table_number} is occupied"], 409);
        }

        $claimed = RestaurantTable::where('company_id', $companyId)
            ->where('id', $table->id)
            ->where('status', '!=', 'occupied')
            ->where(function ($q) use ($user) {
                $q->where('status', 'available')
                    ->orWhereNull('locked_by_user_id')
                    ->orWhere('locked_by_user_id', $user->id)
                    ->orWhere('locked_at', '<', now()->subMinutes(30));
            })
            ->update([
                'status' => 'reserved',
                'locked_by_user_id' => $user->id,
                'locked_at' => now(),
            ]);

        if (!$claimed) {
            return response()->json(['success' => false, 'message' => "Table T-{$table->table_number} is reserved by another cashier"], 409);
        }

        return response()->json(['success' => true, 'table' => ['id' => $table->id, 'table_number' => $table->table_number, 'seats' => $table->seats]]);
    }

    /**
     * Release a RESERVED table back to available. Deliberately never touches
     * 'occupied' — that status belongs to the held-order lifecycle
     * (holdOrder sets it, payOrder/deleteOrder free it). Idempotent.
     */
    public function releaseTable($tableId)
    {
        $companyId = app('currentCompanyId');

        RestaurantTable::where('company_id', $companyId)
            ->where('id', $tableId)
            ->where('status', 'reserved')
            ->update(['status' => 'available', 'locked_by_user_id' => null, 'locked_at' => null, 'occupied_since' => null]);

        return response()->json(['success' => true]);
    }

    public function tableStatus(\Illuminate\Http\Request $request)
    {
        $companyId = app('currentCompanyId');
        RestaurantTable::releaseStaleReservations($companyId);

        // ── Fast-path: If-None-Match ETag (Task 1109) ────────────────────────
        // Fingerprint covers every state change visible on the Board tile:
        //   • Table status / lock changes  → tables.updated_at / status changes.
        //   • New order on a table         → new row in restaurant_orders.
        //   • Order status change          → restaurant_orders.updated_at.
        //   • Order removed / cancelled    → different COUNT + id set.
        // Two lightweight scalar queries replace the full eager-load on a hit.
        $etag = $this->tableStatusEtag($companyId);
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        $tables = RestaurantTable::where('company_id', $companyId)
            ->where('is_active', true)
            // Perf (Pizza Master feedback, Jul 2026): picker felt slow on live —
            // constrain eager-load columns so the polling endpoint stays light.
            ->with([
                'floor:id,name',
                'activeOrders' => fn ($q) => $q->select('id', 'table_id', 'order_number', 'total_amount', 'created_by', 'source', 'order_type', 'kot_sent_at', 'status'),
                'activeOrders.creator:id,name',
            ])
            ->get()
            ->map(function ($t) {
                // Table Board (Jul 2026): the sale-screen board needs the active
                // held order's summary (who placed it + amount) right on the tile.
                // Oldest active order = the one the table is "running" on.
                $active = $t->activeOrders->sortBy('id')->first();
                return [
                    'id' => $t->id,
                    'table_number' => $t->table_number,
                    'floor' => $t->floor->name,
                    'seats' => $t->seats,
                    'status' => $t->status,
                    'active_orders' => $t->activeOrders->count(),
                    'locked_by' => $t->locked_by_user_id,
                    'locked_at' => $t->locked_at,
                    'occupied_since' => $t->occupied_since,
                    'order' => $active ? [
                        'id' => $active->id,
                        'order_number' => $active->order_number,
                        'total_amount' => (float) $active->total_amount,
                        'staff_name' => $active->creator?->name,
                        'source' => $active->source,
                        'order_type' => $active->order_type,
                        'kot_sent_at' => $active->kot_sent_at,
                        'status' => $active->status,
                        // Owner batch 26 Aug 2026: table waiting on an online transfer —
                        // the tile menu shows/toggles it and the proof bill says so.
                        'online_payment_awaited_at' => $active->online_payment_awaited_at ?? null,
                    ] : null,
                ];
            });

        return response()->json($tables)->header('ETag', $etag);
    }

    /**
     * Task 1109 — collision-resistant ETag for the table-status poll.
     *
     * Covers every state change a Board tile can reflect:
     *  • Table status / lock flip           → tables row updated_at changes.
     *  • New order arrives on a table       → new restaurant_orders row.
     *  • Order status changes (held→ready)  → restaurant_orders updated_at.
     *  • Order paid / cancelled             → different id set + COUNT.
     *
     * Two tiny scalar queries (no relations) replace the full eager-load
     * when nothing has changed — negligible DB cost on a quiet floor.
     */
    private function tableStatusEtag(int $companyId): string
    {
        $DB = \Illuminate\Support\Facades\DB::class;

        $tableRows = $DB::table('restaurant_tables')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'status', 'updated_at', 'locked_by_user_id', 'occupied_since']);

        $orderRows = $DB::table('restaurant_orders')
            ->where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->whereNotNull('table_id')
            ->orderBy('id')
            ->get(['id', 'table_id', 'status', 'updated_at']);

        $payload =
            $tableRows->map(fn ($r) => $r->id . ':' . $r->status . ':' . $r->updated_at . ':' . $r->locked_by_user_id . ':' . $r->occupied_since)->join(',')
            . '|'
            . $orderRows->map(fn ($r) => $r->id . ':' . $r->table_id . ':' . $r->status . ':' . $r->updated_at)->join(',');

        return '"tbl-' . $companyId . '-' . md5($payload) . '"';
    }
}
