<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosRider;
use App\Models\PosRiderSettlement;
use App\Models\PosTransaction;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Riders (PRA POS restaurant module, Jul 2026).
 *
 * Rider records + optional confined `pos_rider` login, delivery-bill
 * assignment/lifecycle, cash khata (CASH bills owe; card/digital tracked
 * only), settlement events, and the rider's own portal.
 *
 * INVARIANTS:
 * - Khata queries bypass hide_archived — day-close archives bills while the
 *   rider still owes the cash.
 * - "Returned" only clears the khata/tracking — it NEVER voids a PRA bill.
 * - Rider columns are purely additive; the three-branch invoice_mode logic
 *   in the billing controllers is untouched.
 */
class PosRiderController extends Controller
{
    /** Delivery-feature gate — disabled pages redirect to POS Features, not 403. */
    private function deliveryGate()
    {
        $company = Company::find(app('currentCompanyId'));
        // Plan gate (Aug 2026 package matrix): Riders is a Pro+ feature.
        if (!PosFeatureService::planAllows($company, 'riders_enabled')) {
            if (request()->expectsJson()) {
                abort(403, __('pos.plan_locked_feature'));
            }
            return redirect()->route('pos.billing')
                ->with('error', __('pos.plan_locked_feature'));
        }
        $features = PosFeatureService::forCompany($company);
        if (empty($features->delivery)) {
            return redirect()->route('pos.features')
                ->with('error', 'Enable the Delivery feature to use Riders.');
        }
        return null;
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('pos_riders') && Schema::hasColumn('pos_transactions', 'rider_id');
    }

    // ─── Riders CRUD (PosAdminOnly-wrapped routes) ─────────────────────────

    public function index()
    {
        if ($gate = $this->deliveryGate()) return $gate;
        if (!$this->schemaReady()) {
            return redirect()->route('pos.dashboard')->with('error', 'Riders module is not available yet (database update pending).');
        }
        $companyId = app('currentCompanyId');

        $riders = PosRider::where('company_id', $companyId)->orderBy('name')->get();

        // Per-rider khata summary (open CASH bills) — one aggregate query.
        $khata = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereNotNull('rider_id')
            ->where('payment_method', 'cash')
            ->whereNull('rider_settlement_id')
            ->where(function ($q) {
                $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
            })
            ->select('rider_id', DB::raw('COUNT(*) as bills'), DB::raw('COALESCE(SUM(total_amount),0) as owed'))
            ->groupBy('rider_id')
            ->get()
            ->keyBy('rider_id');

        // Admin-viewable login passwords (same pattern as /pos/team).
        $riderPasswords = [];
        $riderUsers = User::where('company_id', $companyId)
            ->where('pos_role', 'pos_rider')
            ->whereIn('id', $riders->pluck('user_id')->filter())
            ->get()
            ->keyBy('id');
        foreach ($riders as $r) {
            $u = $r->user_id ? ($riderUsers[$r->user_id] ?? null) : null;
            if ($u && !empty($u->pos_team_password_enc)) {
                try {
                    $riderPasswords[$r->id] = Crypt::decryptString($u->pos_team_password_enc);
                } catch (\Throwable $e) {
                    // APP_KEY rotated — treat as not stored.
                }
            }
        }

        $settlements = PosRiderSettlement::where('company_id', $companyId)
            ->with('rider', 'settledBy')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('pos.riders', compact('riders', 'khata', 'riderUsers', 'riderPasswords', 'settlements'));
    }

    public function store(Request $request)
    {
        if ($gate = $this->deliveryGate()) return $gate;
        $companyId = app('currentCompanyId');

        $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'cnic' => 'nullable|string|max:20',
            'vehicle_no' => 'nullable|string|max:30',
        ]);

        PosRider::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'phone' => $request->phone,
            'cnic' => $request->cnic,
            'vehicle_no' => $request->vehicle_no,
            'is_active' => true,
        ]);

        return back()->with('success', 'Rider added.');
    }

    public function update(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $rider = PosRider::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'cnic' => 'nullable|string|max:20',
            'vehicle_no' => 'nullable|string|max:30',
            'is_active' => 'nullable|boolean',
        ]);

        $isActive = $request->boolean('is_active', $rider->is_active);
        $rider->update([
            'name' => $request->name,
            'phone' => $request->phone,
            'cnic' => $request->cnic,
            'vehicle_no' => $request->vehicle_no,
            'is_active' => $isActive,
        ]);

        // Keep the linked login in lockstep — a deactivated rider must not log in.
        if ($rider->user_id) {
            User::where('company_id', $companyId)->where('id', $rider->user_id)
                ->where('pos_role', 'pos_rider')
                ->update(['is_active' => $isActive]);
        }

        return back()->with('success', 'Rider updated.');
    }

    /**
     * Create (or reset the password of) the rider's confined login.
     * pos_rider accounts are limit-EXEMPT — confined to /pos/rider by PosAuth.
     */
    public function saveLogin(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $rider = PosRider::where('company_id', $companyId)->findOrFail($id);

        $existing = $rider->user_id
            ? User::where('company_id', $companyId)->where('id', $rider->user_id)->where('pos_role', 'pos_rider')->first()
            : null;

        $request->validate([
            'email' => $existing ? 'nullable|email' : 'required|email|unique:users,email',
            'password' => 'required|string|min:6|max:100',
        ]);

        if ($existing) {
            $pw = ['password' => bcrypt($request->password)];
            if (Schema::hasColumn('users', 'pos_team_password_enc')) {
                $pw['pos_team_password_enc'] = Crypt::encryptString($request->password);
            }
            $existing->update($pw);
            return back()->with('success', 'Rider login password reset.');
        }

        $data = [
            'name' => $rider->name,
            'email' => $request->email,
            'phone' => $rider->phone,
            'password' => bcrypt($request->password),
            'company_id' => $companyId,
            'role' => 'employee',
            'pos_role' => 'pos_rider',
            'is_active' => (bool) $rider->is_active,
        ];
        if (Schema::hasColumn('users', 'pos_team_password_enc')) {
            $data['pos_team_password_enc'] = Crypt::encryptString($request->password);
        }
        $user = User::create($data);
        $rider->update(['user_id' => $user->id]);

        return back()->with('success', 'Rider login created.');
    }

    // ─── Deliveries board (admins + cashiers) ──────────────────────────────

    public function deliveries(Request $request)
    {
        // Delivery Manager (pos_delivery) is CONFINED to this board by PosAuth —
        // redirecting them to pos.features/pos.dashboard would bounce straight
        // back here (infinite redirect loop). They always see the board (same
        // rationale as assign/settle staying open: in-flight rider cash must
        // never be stranded behind a feature toggle).
        $isDeliveryMgr = ((auth('pos')->user()->pos_role ?? null) === 'pos_delivery');
        if (!$isDeliveryMgr && ($gate = $this->deliveryGate())) return $gate;
        if (!$this->schemaReady()) {
            if ($isDeliveryMgr) {
                return response('Riders module is not available yet (database update pending). Please contact your admin.', 503);
            }
            return redirect()->route('pos.dashboard')->with('error', 'Riders module is not available yet (database update pending).');
        }
        $companyId = app('currentCompanyId');

        $date = $request->input('date');
        try {
            $day = $date ? \Carbon\Carbon::parse($date)->startOfDay() : now()->startOfDay();
        } catch (\Throwable $e) {
            $day = now()->startOfDay();
        }

        // Delivery bills for the chosen day — archived included (day-close
        // archives bills that may still be out with a rider).
        $bills = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [$day, $day->copy()->endOfDay()])
            ->where(function ($q) {
                $q->where('order_type', 'delivery')->orWhereNotNull('rider_id');
            })
            ->whereIn('status', ['completed'])
            ->with('rider')
            ->orderByDesc('id')
            ->get();

        // Active riders + ANY inactive rider still holding open cash khata —
        // otherwise deactivating a rider strands his outstanding cash with no
        // settle button anywhere (assign dropdown stays active-only in the view).
        $riders = PosRider::where('company_id', $companyId)
            ->where(function ($q) use ($companyId) {
                $q->where('is_active', true)
                    ->orWhereIn('id', function ($sub) use ($companyId) {
                        $sub->select('rider_id')->from('pos_transactions')
                            ->where('company_id', $companyId)
                            ->whereNotNull('rider_id')
                            ->where('payment_method', 'cash')
                            ->whereNull('rider_settlement_id')
                            ->where(function ($q2) {
                                $q2->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
                            });
                    });
            })
            ->orderBy('name')->get();

        // Khata per rider — open cash bills (ALL dates, not just the picked day).
        $khataBills = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereIn('rider_id', $riders->pluck('id'))
            ->where('payment_method', 'cash')
            ->whereNull('rider_settlement_id')
            ->where(function ($q) {
                $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
            })
            ->orderBy('created_at')
            ->get()
            ->groupBy('rider_id');

        // Open (assigned/dispatched, unsettled) delivery counts per rider — ALL
        // dates, any payment method — powers the bulk "All Delivered / All
        // Returned" buttons on rider cards (customer request Jul 2026).
        $openDeliveryCounts = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereIn('rider_id', $riders->pluck('id'))
            ->whereNull('rider_settlement_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->selectRaw('rider_id, COUNT(*) as c')
            ->groupBy('rider_id')
            ->pluck('c', 'rider_id');

        return view('pos.deliveries', compact('bills', 'riders', 'khataBills', 'day', 'openDeliveryCounts'));
    }

    /** Assign / reassign / unassign a rider on a delivery bill. */
    public function assign(Request $request, $txnId)
    {
        $companyId = app('currentCompanyId');
        $txn = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)->findOrFail($txnId);

        if ($txn->rider_settlement_id) {
            return back()->with('error', 'This bill is already settled — rider cannot be changed.');
        }
        // Rider is LOCKED once the delivery reached a terminal state (owner,
        // Jul 2026): delivered/returned bills keep the rider who actually ran
        // them — reassigning would silently move the cash khata to someone who
        // never carried the order. Reassign stays open while assigned/dispatched
        // (rider suddenly unavailable → pick another; khata follows rider_id).
        if (in_array($txn->delivery_status, ['delivered', 'returned'], true)) {
            return back()->with('error', 'This delivery is already ' . $txn->delivery_status . ' — rider can no longer be changed.');
        }
        // Only delivery-shaped bills can carry a rider.
        if ($txn->order_type !== 'delivery' && !$txn->rider_id && !$txn->delivery_address) {
            return back()->with('error', 'Only delivery bills can be assigned to a rider.');
        }

        $riderId = null;
        if ($request->filled('rider_id')) {
            $riderId = PosRider::where('company_id', $companyId)
                ->where('id', (int) $request->input('rider_id'))
                ->where('is_active', true)
                ->value('id');
            if (!$riderId) {
                return back()->with('error', 'Invalid rider.');
            }
        }

        $txn->update([
            'rider_id' => $riderId,
            'delivery_status' => $riderId ? ($txn->delivery_status && $txn->delivery_status !== 'returned' ? $txn->delivery_status : 'assigned') : null,
        ]);

        return back()->with('success', $riderId ? 'Rider assigned.' : 'Rider removed.');
    }

    /** Dispatch / delivered / returned lifecycle. Returned = khata drop ONLY (never voids the bill). */
    public function updateStatus(Request $request, $txnId)
    {
        $companyId = app('currentCompanyId');
        $request->validate(['delivery_status' => 'required|in:assigned,dispatched,delivered,returned']);

        $txn = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)->whereNotNull('rider_id')->findOrFail($txnId);

        if ($txn->rider_settlement_id) {
            return back()->with('error', 'This bill is already settled — status is locked.');
        }

        // Terminal-state guard (owner, Jul 2026): delivered/returned lock the
        // rider — re-opening the status would silently unlock reassignment too.
        // Only forward move allowed from delivered is → returned (matches the
        // UI's Returned button); returned is fully final.
        $newStatus = $request->input('delivery_status');
        if ($txn->delivery_status === 'returned') {
            return back()->with('error', 'This delivery is already returned — status is final.');
        }
        if ($txn->delivery_status === 'delivered' && $newStatus !== 'returned') {
            return back()->with('error', 'This delivery is already delivered — it can only be marked returned.');
        }

        $txn->update(['delivery_status' => $newStatus]);

        return back()->with('success', 'Delivery status updated.');
    }

    /** Bulk mark ALL of one rider's OPEN (assigned/dispatched) deliveries as
     *  delivered or returned in one go (customer request Jul 2026 — flow:
     *  Deliveries → Rider → orders → All → Mark Delivered/Returned).
     *  Scope deliberately mirrors updateStatus guards: settled bills are locked
     *  out by the whereNull, terminal delivered/returned rows are untouched
     *  (bulk-returned must never silently flip already-delivered bills), and
     *  'returned' stays a khata drop ONLY — it never voids a PRA bill. */
    public function bulkStatus(Request $request, $riderId)
    {
        $companyId = app('currentCompanyId');
        $request->validate(['delivery_status' => 'required|in:delivered,returned']);
        $rider = PosRider::where('company_id', $companyId)->findOrFail($riderId);
        $newStatus = $request->input('delivery_status');

        $count = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('rider_id', $rider->id)
            ->whereNull('rider_settlement_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->update(['delivery_status' => $newStatus]);

        if ($count === 0) {
            return back()->with('error', 'No open deliveries for ' . $rider->name . '.');
        }

        return back()->with('success', $count . ' ' . ($count === 1 ? 'delivery' : 'deliveries') . ' marked ' . $newStatus . ' for ' . $rider->name . '.');
    }

    /** Settle selected open CASH bills for one rider (partial = per-bill selection).
     *  settle_all=1 (Pending Deliveries panel, Task 123): settle EVERY open cash
     *  bill on the rider's khata in one click — no bill_ids needed. JSON clients
     *  (the sale-screen panel) get JSON back instead of a redirect. */
    public function settle(Request $request, $riderId)
    {
        $companyId = app('currentCompanyId');
        $rider = PosRider::where('company_id', $companyId)->findOrFail($riderId);

        $settleAll = $request->boolean('settle_all');
        $request->validate([
            'bill_ids' => ($settleAll ? 'nullable' : 'required') . '|array|min:1',
            'bill_ids.*' => 'integer',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $rider, $companyId, $settleAll) {
            // Lock + re-verify each bill is genuinely open rider-cash for THIS rider.
            $query = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('rider_id', $rider->id)
                ->where('payment_method', 'cash')
                ->whereNull('rider_settlement_id')
                ->where(function ($q) {
                    $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
                });
            if (!$settleAll) {
                $query->whereIn('id', array_map('intval', $request->input('bill_ids')));
            }
            $bills = $query->lockForUpdate()->get();

            if ($bills->isEmpty()) {
                $msg = $settleAll ? 'No open cash bills on this rider\'s khata.' : 'No open cash bills matched the selection.';
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }

            $settlement = PosRiderSettlement::create([
                'company_id' => $companyId,
                'rider_id' => $rider->id,
                'settled_by' => auth('pos')->id(),
                'total_amount' => $bills->sum('total_amount'),
                'bill_count' => $bills->count(),
                'notes' => $request->input('notes'),
            ]);

            PosTransaction::withoutGlobalScope('hide_archived')
                ->whereIn('id', $bills->pluck('id'))
                ->update([
                    'rider_settlement_id' => $settlement->id,
                    'rider_settled_at' => now(),
                ]);

            $msg = 'Settled Rs. ' . number_format((float) $settlement->total_amount) . ' (' . $settlement->bill_count . ' bills) from ' . $rider->name . '.';
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'total_amount' => (float) $settlement->total_amount,
                    'bill_count' => (int) $settlement->bill_count,
                ]);
            }
            return back()->with('success', $msg);
        });
    }

    // ─── Rider portal (pos_rider role, confined by PosAuth) ────────────────

    public function portal()
    {
        $user = auth('pos')->user();
        $companyId = app('currentCompanyId');

        // Plan gate: downgraded shop → rider logins see a clear message, not data.
        if (!PosFeatureService::planAllows(Company::find($companyId), 'riders_enabled')) {
            abort(403, __('pos.plan_locked_feature'));
        }

        $rider = PosRider::where('company_id', $companyId)->where('user_id', $user->id)->first();
        if (!$rider) {
            // Login exists but the rider record was deleted — nothing to show.
            return view('pos.rider-portal', ['rider' => null, 'bills' => collect(), 'owed' => 0]);
        }

        $bills = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('rider_id', $rider->id)
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->orderByDesc('id')
            ->get();

        $owed = (float) $rider->openCashBills()->sum('total_amount');

        return view('pos.rider-portal', compact('rider', 'bills', 'owed'));
    }

    /** Rider marks his OWN bill delivered — the only write he can do. */
    public function portalMarkDelivered($txnId)
    {
        $user = auth('pos')->user();
        $companyId = app('currentCompanyId');

        if (!PosFeatureService::planAllows(Company::find($companyId), 'riders_enabled')) {
            abort(403, __('pos.plan_locked_feature'));
        }

        $rider = PosRider::where('company_id', $companyId)->where('user_id', $user->id)->firstOrFail();

        $txn = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('rider_id', $rider->id)
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->findOrFail($txnId);

        $txn->update(['delivery_status' => 'delivered']);

        return back()->with('success', 'Marked delivered.');
    }
}
