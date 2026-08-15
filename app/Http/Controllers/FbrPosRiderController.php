<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\PosRider;
use App\Models\PosRiderSettlement;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Riders for FBR POS (port of PosRiderController, Aug 2026).
 *
 * Shares pos_riders and pos_rider_settlements tables with PRA POS —
 * both are company-scoped so rider records are the same across panels.
 * Transactions come from fbr_pos_transactions (not pos_transactions).
 *
 * Key differences from PRA:
 * - Auth guard: fbrpos
 * - No stream scope (FBR has no local/pra split)
 * - Admin role: company_admin (FBR) instead of pos_admin (PRA)
 * - No business_date column on fbr_pos_transactions — use created_at
 * - No pos_delivery confined role in FBR
 */
class FbrPosRiderController extends Controller
{
    /** Delivery-feature gate — redirects to FBR dashboard, not 403. */
    private function deliveryGate()
    {
        $company = Company::find(app('currentCompanyId'));
        if (!PosFeatureService::planAllows($company, 'riders_enabled')) {
            return redirect()->route('fbrpos.dashboard')
                ->with('error', __('pos.plan_locked_feature'));
        }
        $features = PosFeatureService::forCompany($company);
        if (empty($features->delivery)) {
            return redirect()->route('fbrpos.dashboard')
                ->with('error', 'Enable the Delivery feature to use Riders.');
        }
        return null;
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('pos_riders')
            && Schema::hasColumn('fbr_pos_transactions', 'rider_id');
    }

    /** FBR admin check: company_admin role = admin. */
    private function isAdmin(): bool
    {
        $user = auth('fbrpos')->user();
        return ($user->role ?? '') === 'company_admin';
    }

    // ─── Riders CRUD (admin-only) ──────────────────────────────────────────

    public function index()
    {
        if (!$this->isAdmin()) {
            return redirect()->route('fbrpos.dashboard')->with('error', __('pos.admin_only_action'));
        }
        if ($gate = $this->deliveryGate()) return $gate;
        if (!$this->schemaReady()) {
            return redirect()->route('fbrpos.dashboard')
                ->with('error', 'Riders module is not available yet (database update pending).');
        }

        $companyId = app('currentCompanyId');
        $riders = PosRider::where('company_id', $companyId)->orderBy('name')->get();

        // Per-rider khata summary (open CASH bills).
        $khata = FbrPosTransaction::where('company_id', $companyId)
            ->whereNotNull('rider_id')
            ->where('payment_method', 'cash')
            ->whereNull('rider_settlement_id')
            ->where(function ($q) {
                $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
            })
            ->select('rider_id', DB::raw('COUNT(*) as bills'), DB::raw('COALESCE(SUM(' . PosRider::remainingExpr('fbr_pos_transactions') . '),0) as owed'))
            ->groupBy('rider_id')
            ->get()
            ->keyBy('rider_id');

        // Admin-viewable passwords (same pattern as PRA pos/team).
        $riderPasswords = [];
        $riderUsers = User::where('company_id', $companyId)
            ->where('pos_role', 'pos_rider')
            ->whereIn('id', $riders->pluck('user_id')->filter())
            ->get()->keyBy('id');
        foreach ($riders as $r) {
            $u = $r->user_id ? ($riderUsers[$r->user_id] ?? null) : null;
            if ($u && !empty($u->pos_team_password_enc)) {
                try {
                    $riderPasswords[$r->id] = Crypt::decryptString($u->pos_team_password_enc);
                } catch (\Throwable $e) {}
            }
        }

        $settlements = PosRiderSettlement::where('company_id', $companyId)
            ->with('rider', 'settledBy')
            ->orderByDesc('id')->limit(20)->get();

        return view('fbr-pos.riders', compact('riders', 'khata', 'riderUsers', 'riderPasswords', 'settlements'));
    }

    public function store(Request $request)
    {
        if (!$this->isAdmin()) abort(403);
        if ($gate = $this->deliveryGate()) return $gate;
        $companyId = app('currentCompanyId');

        $request->validate([
            'name'       => 'required|string|max:120',
            'phone'      => 'nullable|string|max:30',
            'cnic'       => 'nullable|string|max:20',
            'vehicle_no' => 'nullable|string|max:30',
        ]);

        PosRider::create([
            'company_id' => $companyId,
            'name'       => $request->name,
            'phone'      => $request->phone,
            'cnic'       => $request->cnic,
            'vehicle_no' => $request->vehicle_no,
            'is_active'  => true,
        ]);

        return back()->with('success', 'Rider added.');
    }

    public function update(Request $request, $id)
    {
        if (!$this->isAdmin()) abort(403);
        $companyId = app('currentCompanyId');
        $rider = PosRider::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name'       => 'required|string|max:120',
            'phone'      => 'nullable|string|max:30',
            'cnic'       => 'nullable|string|max:20',
            'vehicle_no' => 'nullable|string|max:30',
            'is_active'  => 'nullable|boolean',
        ]);

        $isActive = $request->boolean('is_active', $rider->is_active);
        $rider->update([
            'name'       => $request->name,
            'phone'      => $request->phone,
            'cnic'       => $request->cnic,
            'vehicle_no' => $request->vehicle_no,
            'is_active'  => $isActive,
        ]);

        if ($rider->user_id) {
            User::where('company_id', $companyId)->where('id', $rider->user_id)
                ->where('pos_role', 'pos_rider')
                ->update(['is_active' => $isActive]);
        }

        return back()->with('success', 'Rider updated.');
    }

    public function saveLogin(Request $request, $id)
    {
        if (!$this->isAdmin()) abort(403);
        $companyId = app('currentCompanyId');
        $rider = PosRider::where('company_id', $companyId)->findOrFail($id);

        $existing = $rider->user_id
            ? User::where('company_id', $companyId)->where('id', $rider->user_id)->where('pos_role', 'pos_rider')->first()
            : null;

        $request->validate([
            'email'    => $existing ? 'nullable|email' : 'required|email|unique:users,email',
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
            'name'       => $rider->name,
            'email'      => $request->email,
            'phone'      => $rider->phone,
            'password'   => bcrypt($request->password),
            'company_id' => $companyId,
            'role'       => 'employee',
            'pos_role'   => 'pos_rider',
            'is_active'  => (bool) $rider->is_active,
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
        if ($gate = $this->deliveryGate()) return $gate;
        if (!$this->schemaReady()) {
            return redirect()->route('fbrpos.dashboard')
                ->with('error', 'Riders module is not available yet (database update pending).');
        }

        $companyId = app('currentCompanyId');

        // FBR transactions have no business_date — filter by created_at.
        $date = $request->input('date');
        if ($date) {
            try {
                $day = \Carbon\Carbon::parse($date)->startOfDay();
            } catch (\Throwable $e) {
                $day = now()->startOfDay();
            }
        } else {
            $day = now()->startOfDay();
        }
        $dayEnd = $day->copy()->endOfDay();

        // Delivery bills for the chosen day — completed orders with order_type=delivery or rider_id set.
        $allBills = FbrPosTransaction::where('company_id', $companyId)
            ->where(function ($q) {
                $q->where('order_type', 'delivery')->orWhereNotNull('rider_id');
            })
            ->whereIn('status', ['completed'])
            ->whereBetween('created_at', [$day, $dayEnd])
            ->with('rider')
            ->orderByDesc('id')
            ->get();

        // Open (assigned/dispatched) bills — ALL dates, oldest first.
        $assignedTsExpr = Schema::hasColumn('fbr_pos_transactions', 'rider_assigned_at')
            ? 'COALESCE(rider_assigned_at, created_at)' : 'created_at';

        $openBillsAll = FbrPosTransaction::where('company_id', $companyId)
            ->where(function ($q) {
                $q->where('order_type', 'delivery')->orWhereNotNull('rider_id');
            })
            ->whereIn('status', ['completed'])
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->with('rider')
            ->orderBy(DB::raw($assignedTsExpr))
            ->get();

        $tabCounts = [
            'pending'   => $openBillsAll->count(),
            'delivered' => $allBills->where('delivery_status', 'delivered')->count(),
            'returned'  => $allBills->where('delivery_status', 'returned')->count(),
        ];

        $activeTab = $request->input('tab', 'pending');
        if (!in_array($activeTab, ['pending', 'delivered', 'returned'], true)) {
            $activeTab = 'pending';
        }
        $bills = match ($activeTab) {
            'pending'   => $openBillsAll->values(),
            'delivered' => $allBills->where('delivery_status', 'delivered')->values(),
            default     => $allBills->where('delivery_status', 'returned')->values(),
        };

        // Active riders + any inactive with open cash khata.
        $riders = PosRider::where('company_id', $companyId)
            ->where(function ($q) use ($companyId) {
                $q->where('is_active', true)
                    ->orWhereIn('id', function ($sub) use ($companyId) {
                        $sub->select('rider_id')->from('fbr_pos_transactions')
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

        // Khata: open cash bills per rider (ALL dates).
        $khataBills = FbrPosTransaction::where('company_id', $companyId)
            ->whereIn('rider_id', $riders->pluck('id'))
            ->where('payment_method', 'cash')
            ->whereNull('rider_settlement_id')
            ->where(function ($q) {
                $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
            })
            ->orderBy('created_at')
            ->get()
            ->groupBy('rider_id');

        // Open delivery counts per rider (ALL dates, any payment).
        $openDeliveryCounts = FbrPosTransaction::where('company_id', $companyId)
            ->whereIn('rider_id', $riders->pluck('id'))
            ->whereNull('rider_settlement_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->selectRaw('rider_id, COUNT(*) as c')
            ->groupBy('rider_id')
            ->pluck('c', 'rider_id');

        // Oldest open delivery per rider (for red pill).
        $openDeliveryOldest = FbrPosTransaction::where('company_id', $companyId)
            ->whereIn('rider_id', $riders->pluck('id'))
            ->whereNull('rider_settlement_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->selectRaw("rider_id, MIN({$assignedTsExpr}) as oldest")
            ->groupBy('rider_id')
            ->pluck('oldest', 'rider_id')
            ->map(function ($ts) {
                return (int) floor(abs(now()->diffInHours(\Carbon\Carbon::parse($ts))) / 24);
            });

        // Per-rider day summary (zero extra queries — derived from $allBills).
        $riderDaySummary = $allBills->whereNotNull('rider_id')
            ->groupBy('rider_id')
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'name'      => optional($first->rider)->name ?? ('Rider #' . $first->rider_id),
                    'pending'   => $group->whereIn('delivery_status', ['assigned', 'dispatched'])->count(),
                    'delivered' => $group->where('delivery_status', 'delivered')->count(),
                    'returned'  => $group->where('delivery_status', 'returned')->count(),
                ];
            })
            ->sortBy('name')->values();

        $isAdminOrManager = $this->isAdmin();

        return view('fbr-pos.deliveries', compact(
            'bills', 'riders', 'khataBills', 'day', 'openDeliveryCounts',
            'openDeliveryOldest', 'tabCounts', 'activeTab', 'riderDaySummary', 'isAdminOrManager'
        ));
    }

    /** Assign / reassign / unassign a rider on a delivery bill. */
    public function assign(Request $request, $txnId)
    {
        $companyId = app('currentCompanyId');
        $txn = FbrPosTransaction::where('company_id', $companyId)->findOrFail($txnId);

        if ($txn->rider_settlement_id) {
            return $this->statusError($request, 'This bill is already settled — rider cannot be changed.');
        }
        if (in_array($txn->delivery_status, ['delivered', 'returned'], true)) {
            return $this->statusError($request, 'This delivery is already ' . $txn->delivery_status . ' — rider can no longer be changed.');
        }
        if ($txn->order_type !== 'delivery' && !$txn->rider_id && !$txn->delivery_address) {
            return $this->statusError($request, 'Only delivery bills can be assigned to a rider.');
        }

        $riderId = null;
        if ($request->filled('rider_id')) {
            $riderId = PosRider::where('company_id', $companyId)
                ->where('id', (int) $request->input('rider_id'))
                ->where('is_active', true)
                ->value('id');
            if (!$riderId) {
                return $this->statusError($request, 'Invalid rider.');
            }
        }

        $upd = [
            'rider_id'        => $riderId,
            'delivery_status' => $riderId
                ? ($txn->delivery_status && $txn->delivery_status !== 'returned' ? $txn->delivery_status : 'assigned')
                : null,
        ];
        if (Schema::hasColumn('fbr_pos_transactions', 'rider_assigned_at')) {
            if (!$riderId) {
                $upd['rider_assigned_at'] = null;
            } elseif ((int) $txn->rider_id !== (int) $riderId || !$txn->rider_assigned_at) {
                $upd['rider_assigned_at'] = now();
            }
        }
        $txn->update($upd);

        // Sale-screen Pending Deliveries popup (Task 517) assigns via fetch —
        // JSON clients get JSON; the Deliveries board form keeps back().
        if ($request->expectsJson()) {
            return response()->json([
                'success'         => true,
                'rider_id'        => $riderId,
                'delivery_status' => $upd['delivery_status'],
            ]);
        }

        return back()->with('success', $riderId ? 'Rider assigned.' : 'Rider removed.');
    }

    /** Dispatch / delivered / returned lifecycle. */
    public function updateStatus(Request $request, $txnId)
    {
        $companyId = app('currentCompanyId');
        $request->validate(['delivery_status' => 'required|in:assigned,dispatched,delivered,returned']);

        $txn = FbrPosTransaction::where('company_id', $companyId)
            ->whereNotNull('rider_id')->findOrFail($txnId);

        if ($txn->rider_settlement_id) {
            // Task 773 safety net: settled bill stuck at assigned/dispatched may
            // move FORWARD to delivered only (no delivered_at stamp). Returned
            // and reassign stay locked — they would reverse settled cash.
            if ($request->input('delivery_status') === 'delivered'
                && in_array($txn->delivery_status, ['assigned', 'dispatched'], true)) {
                $txn->update(['delivery_status' => 'delivered']);
                if ($request->expectsJson()) {
                    return response()->json(['success' => true, 'delivery_status' => 'delivered']);
                }
                return back()->with('success', 'Delivery status updated.');
            }
            return $this->statusError($request, 'This bill is already settled — status is locked.');
        }

        $newStatus = $request->input('delivery_status');
        if ($txn->delivery_status === 'returned') {
            return $this->statusError($request, 'This delivery is already returned — status is final.');
        }
        if ($txn->delivery_status === 'delivered' && $newStatus !== 'returned') {
            return $this->statusError($request, 'This delivery is already delivered — it can only be marked returned.');
        }

        $upd = ['delivery_status' => $newStatus];
        if ($newStatus === 'delivered' && !$txn->delivered_at
            && Schema::hasColumn('fbr_pos_transactions', 'delivered_at')) {
            $upd['delivered_at'] = now();
        }
        $txn->update($upd);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'delivery_status' => $newStatus]);
        }
        return back()->with('success', 'Delivery status updated.');
    }

    private function statusError(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }
        return back()->with('error', $message);
    }

    /** Bulk mark all of one rider's open deliveries as delivered or returned. */
    public function bulkStatus(Request $request, $riderId)
    {
        $companyId = app('currentCompanyId');
        $request->validate(['delivery_status' => 'required|in:delivered,returned']);
        $rider     = PosRider::where('company_id', $companyId)->findOrFail($riderId);
        $newStatus = $request->input('delivery_status');

        $count = FbrPosTransaction::where('company_id', $companyId)
            ->where('rider_id', $rider->id)
            ->whereNull('rider_settlement_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->update(array_merge(
                ['delivery_status' => $newStatus],
                ($newStatus === 'delivered' && Schema::hasColumn('fbr_pos_transactions', 'delivered_at'))
                    ? ['delivered_at' => now()] : []
            ));

        if ($count === 0) {
            return back()->with('error', 'No open deliveries for ' . $rider->name . '.');
        }
        return back()->with('success', $count . ' ' . ($count === 1 ? 'delivery' : 'deliveries') . ' marked ' . $newStatus . ' for ' . $rider->name . '.');
    }

    /** Settle selected open CASH bills for one rider.
     *  received_amount (Task 525, "aadha cash abhi, baqi baad"): optional — the
     *  cash actually handed over. Applies oldest-first: fully covered bills
     *  settle, the remainder lands on the next bill's rider_partial_paid and the
     *  rest of the khata stays outstanding. Omitted = full settle (unchanged). */
    public function settle(Request $request, $riderId)
    {
        $companyId = app('currentCompanyId');
        $rider     = PosRider::where('company_id', $companyId)->findOrFail($riderId);

        $settleAll = $request->boolean('settle_all');
        $request->validate([
            'bill_ids'        => ($settleAll ? 'nullable' : 'required') . '|array|min:1',
            'bill_ids.*'      => 'integer',
            'received_amount' => 'nullable|numeric',
            'notes'           => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $rider, $companyId, $settleAll) {
            // Oldest first — partial receipts must clear the oldest khata first.
            $query = FbrPosTransaction::where('company_id', $companyId)
                ->where('rider_id', $rider->id)
                ->where('payment_method', 'cash')
                ->whereNull('rider_settlement_id')
                ->where(function ($q) {
                    $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
                });
            if (!$settleAll) {
                $query->whereIn('id', array_map('intval', $request->input('bill_ids')));
            }
            $bills = $query->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();

            if ($bills->isEmpty()) {
                $msg = $settleAll ? 'No open cash bills on this rider\'s khata.' : 'No open cash bills matched the selection.';
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }

            $hasPartialCol = Schema::hasColumn('fbr_pos_transactions', 'rider_partial_paid');
            $remainingOf = fn ($b) => round((float) $b->total_amount - ($hasPartialCol ? (float) ($b->rider_partial_paid ?? 0) : 0), 2);
            $outstanding = round((float) $bills->sum($remainingOf), 2);

            $receivedRaw = $request->input('received_amount');
            $received = $receivedRaw === null || $receivedRaw === '' ? $outstanding : round((float) $receivedRaw, 2);
            if ($received <= 0) {
                $msg = __('pos.settle_amount_min_err');
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }
            if ($received > $outstanding + 0.009) {
                $msg = __('pos.settle_amount_over_err', ['max' => number_format($outstanding)]);
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }
            if (!$hasPartialCol && $received < $outstanding) {
                $msg = 'Partial settlement is not available yet (database update pending).';
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }

            // Allocate oldest-first: settle every fully covered bill, drop the
            // remainder on the next bill's rider_partial_paid.
            $left = $received;
            $settledIds = [];
            $allocation = [];
            $partialBill = null;   // [$id, $newPartialPaid]
            foreach ($bills as $b) {
                if ($left <= 0.009) {
                    break;
                }
                $rem = $remainingOf($b);
                if ($rem <= 0.009) {
                    $settledIds[] = $b->id;
                    continue;
                }
                $applied = min($left, $rem);
                $allocation[] = [
                    'bill_id'       => (int) $b->id,
                    'amount'        => round($applied, 2),
                    'business_date' => (string) ($b->business_date ?: $b->created_at?->toDateString()),
                ];
                if ($applied >= $rem - 0.009) {
                    $settledIds[] = $b->id;
                } else {
                    $partialBill = [$b->id, round((float) ($b->rider_partial_paid ?? 0) + $applied, 2)];
                }
                $left = round($left - $applied, 2);
            }

            $settlementData = [
                'company_id'   => $companyId,
                'rider_id'     => $rider->id,
                'settled_by'   => auth('fbrpos')->id(),
                // Cash actually received NOW (earlier partials sit on their own rows).
                'total_amount' => $received,
                'bill_count'   => count($settledIds),
                'notes'        => $request->input('notes'),
            ];
            if (Schema::hasColumn('pos_rider_settlements', 'allocation')) {
                $settlementData['allocation'] = $allocation;
                $settlementData['panel'] = 'fbr';
            }
            $settlement = PosRiderSettlement::create($settlementData);

            if ($settledIds) {
                FbrPosTransaction::whereIn('id', $settledIds)
                    ->update([
                        'rider_settlement_id' => $settlement->id,
                        'rider_settled_at'    => now(),
                    ]);
                // Task 773: cash settle = delivery done. Fully settled bills still
                // at assigned/dispatched auto-advance to delivered so they leave
                // the Pending tab. delivered_at deliberately NOT stamped (settle
                // time ≠ actual delivery time — duration chip skips NULL).
                FbrPosTransaction::whereIn('id', $settledIds)
                    ->whereIn('delivery_status', ['assigned', 'dispatched'])
                    ->update(['delivery_status' => 'delivered']);
            }
            if ($partialBill) {
                FbrPosTransaction::where('id', $partialBill[0])
                    ->update(['rider_partial_paid' => $partialBill[1]]);
            }

            // Whole-khata remaining AFTER this receipt (FBR bills only).
            $khataLeft = (float) FbrPosTransaction::where('company_id', $companyId)
                ->where('rider_id', $rider->id)
                ->where('payment_method', 'cash')
                ->whereNull('rider_settlement_id')
                ->where(function ($q) {
                    $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
                })
                ->selectRaw('COALESCE(SUM(' . PosRider::remainingExpr('fbr_pos_transactions') . '), 0) as rem')
                ->value('rem');
            if (Schema::hasColumn('pos_rider_settlements', 'outstanding_after')) {
                $settlement->forceFill(['outstanding_after' => $khataLeft])->save();
            }

            $msg = $khataLeft > 0.009
                ? __('pos.partial_settled_msg', [
                    'amount' => number_format($received),
                    'name'   => $rider->name,
                    'left'   => number_format($khataLeft),
                ])
                : 'Settled Rs. ' . number_format($received) . ' (' . count($settledIds) . ' bills) from ' . $rider->name . '.';
            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'message' => $msg, 'total_amount' => (float) $received, 'bill_count' => count($settledIds), 'outstanding_after' => (float) $khataLeft]);
            }
            return back()->with('success', $msg);
        });
    }

    // ─── Prepaid conversion (admin only in FBR) ────────────────────────────

    public function markPrepaid(Request $request, $txnId)
    {
        if (!$this->isAdmin()) {
            return back()->with('error', __('pos.admin_only_action'));
        }

        $companyId = app('currentCompanyId');
        $txn = FbrPosTransaction::where('company_id', $companyId)->findOrFail($txnId);

        if ($txn->order_type !== 'delivery' || empty($txn->rider_id)) {
            return back()->with('error', __('pos.mark_prepaid_err_not_delivery'));
        }
        if ($txn->payment_method !== 'cash') {
            return back()->with('error', __('pos.mark_prepaid_err_not_cash'));
        }
        if ($txn->rider_settlement_id) {
            return back()->with('error', __('pos.mark_prepaid_err_settled'));
        }
        if ($txn->delivery_status === 'returned') {
            return back()->with('error', __('pos.mark_prepaid_err_returned'));
        }

        $upd = ['payment_method' => 'qr_payment', 'cash_received' => null, 'change_due' => null];
        if (Schema::hasColumn('fbr_pos_transactions', 'prepaid_converted_at')) {
            $upd['prepaid_converted_at'] = now();
        }
        if (Schema::hasColumn('fbr_pos_transactions', 'prepaid_converted_by')) {
            $upd['prepaid_converted_by'] = auth('fbrpos')->id();
        }
        $txn->update($upd);

        return back()->with('success', __('pos.mark_prepaid_success'));
    }

    public function unmarkPrepaid(Request $request, $txnId)
    {
        if (!$this->isAdmin()) {
            return back()->with('error', __('pos.admin_only_action'));
        }

        $companyId = app('currentCompanyId');
        $txn = FbrPosTransaction::where('company_id', $companyId)->findOrFail($txnId);

        if (empty($txn->prepaid_converted_at)) {
            return back()->with('error', __('pos.unmark_prepaid_err_not_converted'));
        }
        if ($txn->rider_settlement_id) {
            return back()->with('error', __('pos.unmark_prepaid_err_settled'));
        }
        if (empty($txn->rider_id)) {
            return back()->with('error', __('pos.unmark_prepaid_err_no_rider'));
        }
        if ($txn->delivery_status === 'returned') {
            return back()->with('error', __('pos.mark_prepaid_err_returned'));
        }

        $upd = ['payment_method' => 'cash'];
        if (Schema::hasColumn('fbr_pos_transactions', 'prepaid_converted_at')) {
            $upd['prepaid_converted_at'] = null;
        }
        if (Schema::hasColumn('fbr_pos_transactions', 'prepaid_converted_by')) {
            $upd['prepaid_converted_by'] = null;
        }
        $txn->update($upd);

        return back()->with('success', __('pos.unmark_prepaid_success'));
    }
}
