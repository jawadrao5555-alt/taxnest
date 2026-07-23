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
        if ($user && $user->isPosCashier()) {
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

        return view('pos.restaurant.tables', compact('floors'));
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

        return view('pos.restaurant.table-management', compact('floors'));
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

    public function tableStatus()
    {
        $companyId = app('currentCompanyId');
        RestaurantTable::releaseStaleReservations($companyId);

        $tables = RestaurantTable::where('company_id', $companyId)
            ->where('is_active', true)
            ->with(['floor', 'activeOrders'])
            ->get()
            ->map(function ($t) {
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
                ];
            });

        return response()->json($tables);
    }
}
