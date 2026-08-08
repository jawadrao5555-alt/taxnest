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

    // ─── Billing Scope stream lock (Task 353) ──────────────────────────────
    // Stream-locked staff (pos_cashier/pos_manager with pos_billing_scope
    // 'local' or 'pra') may see and act on ONLY their own stream's delivery
    // bills. Predicate mirrors PosController::applyReportFilters /
    // billingScopeAllowsRow exactly: LOCAL = invoice_mode='local' OR
    // (NULL pra_status AND NULL pra_invoice_number); everything else = PRA.
    // 'both' (default, owner/admin, pos_delivery) stays stream-agnostic.

    private function billingScope(): string
    {
        return auth('pos')->user()?->posBillingScope() ?? 'both';
    }

    /** Constrain a pos_transactions query to the current user's stream. */
    private function applyStreamScope($q)
    {
        $scope = $this->billingScope();
        if ($scope === 'local') {
            $q->where(function ($s) {
                $s->where('invoice_mode', 'local')
                  ->orWhere(function ($s2) {
                      $s2->whereNull('pra_status')->whereNull('pra_invoice_number');
                  });
            });
        } elseif ($scope === 'pra') {
            $q->where(function ($s) {
                $s->where(function ($s2) {
                    $s2->where('invoice_mode', '!=', 'local')->orWhereNull('invoice_mode');
                })->where(function ($s2) {
                    $s2->whereNotNull('pra_status')->orWhereNotNull('pra_invoice_number');
                });
            });
        }
        return $q;
    }

    /** Row-level guard for single-bill mutations — mirrors billingScopeAllowsRow. */
    private function streamScopeAllowsTxn(PosTransaction $txn): bool
    {
        $scope = $this->billingScope();
        if ($scope === 'both') {
            return true;
        }
        $isLocal = $txn->invoice_mode === 'local'
            || ($txn->pra_status === null && $txn->pra_invoice_number === null);
        return $scope === 'local' ? $isLocal : !$isLocal;
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
        $hasBizDate = Schema::hasColumn('pos_transactions', 'business_date');

        // Default to today's business date (respects company cutoff — post-midnight
        // sales count in yesterday until the day is closed).
        if ($date) {
            try {
                $businessDate = \Carbon\Carbon::parse($date)->format('Y-m-d');
            } catch (\Throwable $e) {
                $businessDate = \App\Services\PosBusinessDay::current($companyId);
            }
        } else {
            $businessDate = \App\Services\PosBusinessDay::current($companyId);
        }
        $day = \Carbon\Carbon::parse($businessDate)->startOfDay();

        // Delivery bills for the chosen business day — archived included (day-close
        // archives bills that may still be out with a rider).
        // business_date is a DATE column; compare with = on the string, NEVER
        // whereDate() (kills the index — pos-business-day.md).
        $billQuery = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where(function ($q) {
                $q->where('order_type', 'delivery')->orWhereNotNull('rider_id');
            })
            ->whereIn('status', ['completed'])
            ->with('rider')
            ->orderByDesc('id');
        // Task 353: stream-locked staff see only their own stream's bills.
        $this->applyStreamScope($billQuery);

        if ($hasBizDate) {
            $billQuery->where('business_date', $businessDate);
        } else {
            // Pre-migration fallback — safe during the schema-drift window.
            $billQuery->whereBetween('created_at', [$day, $day->copy()->endOfDay()]);
        }

        $allBills = $billQuery->get();

        // Owner (7 Aug 2026, Touseef case): purane atke bills GHAYAB thay — pending
        // tab date-filtered thi, 3-4 din pehle ke assigned/dispatched bills default
        // view par nazar hi nahi aate thay. Pending ab HAR tareekh ke khule bills
        // dikhata hai (oldest first — sab se purana sab se upar). Delivered/Returned
        // tabs din ke hisaab se hi rehte hain (read-only history).
        // rider_assigned_at is an incremental column — schema-guard (PROD drift).
        $assignedTsExpr = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_assigned_at')
            ? 'COALESCE(rider_assigned_at, created_at)' : 'created_at';
        $openBillsAll = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where(function ($q) {
                $q->where('order_type', 'delivery')->orWhereNotNull('rider_id');
            })
            ->whereIn('status', ['completed'])
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->with('rider')
            ->orderBy(DB::raw($assignedTsExpr));
        $this->applyStreamScope($openBillsAll);
        $openBillsAll = $openBillsAll->get();

        // Tab counts (computed on the collections — single DB round-trip each).
        $tabCounts = [
            'pending'   => $openBillsAll->count(),
            'delivered' => $allBills->where('delivery_status', 'delivered')->count(),
            'returned'  => $allBills->where('delivery_status', 'returned')->count(),
        ];

        // Filter bills by active tab; Pending = open (assigned/dispatched only).
        // Delivered/Returned also include settled bills — read-only view.
        $activeTab = $request->input('tab', 'pending');
        if (!in_array($activeTab, ['pending', 'delivered', 'returned'], true)) {
            $activeTab = 'pending';
        }
        if ($activeTab === 'pending') {
            $bills = $openBillsAll->values();
        } elseif ($activeTab === 'delivered') {
            $bills = $allBills->where('delivery_status', 'delivered')->values();
        } else {
            $bills = $allBills->where('delivery_status', 'returned')->values();
        }

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
        // Task 353: stream-scoped so a stream-locked manager never sees (or
        // settles) the other stream's cash; owner/admin ('both') sees all —
        // no rider cash is ever stranded.
        $khataBills = $this->applyStreamScope(PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereIn('rider_id', $riders->pluck('id'))
            ->where('payment_method', 'cash')
            ->whereNull('rider_settlement_id')
            ->where(function ($q) {
                $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
            }))
            ->orderBy('created_at')
            ->get()
            ->groupBy('rider_id');

        // Open (assigned/dispatched, unsettled) delivery counts per rider — ALL
        // dates, any payment method — powers the bulk "All Delivered / All
        // Returned" buttons on rider cards (customer request Jul 2026).
        $openDeliveryCounts = $this->applyStreamScope(PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereIn('rider_id', $riders->pluck('id'))
            ->whereNull('rider_settlement_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched']))
            ->selectRaw('rider_id, COUNT(*) as c')
            ->groupBy('rider_id')
            ->pluck('c', 'rider_id');

        // Oldest open delivery per rider (owner, 7 Aug 2026): card par numayan ho
        // ke kis rider ka bill kitne DIN se latka hua hai. COALESCE: pre-migration
        // rows may lack rider_assigned_at.
        $openDeliveryOldest = $this->applyStreamScope(PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereIn('rider_id', $riders->pluck('id'))
            ->whereNull('rider_settlement_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched']))
            ->selectRaw("rider_id, MIN({$assignedTsExpr}) as oldest")
            ->groupBy('rider_id')
            ->pluck('oldest', 'rider_id')
            ->map(function ($ts) {
                // Carbon 3 signed diffs — abs() or a past timestamp goes negative.
                return (int) floor(abs(now()->diffInHours(\Carbon\Carbon::parse($ts))) / 24);
            });

        // Per-rider day summary — derived from the already-loaded $allBills collection
        // (zero extra DB queries). Groups by rider_id and counts by delivery_status bucket.
        // Bills with no rider_id are skipped (unassigned deliveries go into the total only).
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
            ->sortBy('name')
            ->values();

        // Prepaid button visibility (Task 285): admin + manager only.
        $currentRole = auth('pos')->user()->pos_role ?? null;
        $isAdminOrManager = in_array($currentRole, ['pos_admin', 'pos_manager'], true);

        return view('pos.deliveries', compact('bills', 'riders', 'khataBills', 'day', 'openDeliveryCounts', 'openDeliveryOldest', 'tabCounts', 'activeTab', 'riderDaySummary', 'isAdminOrManager'));
    }

    /** Assign / reassign / unassign a rider on a delivery bill. */
    public function assign(Request $request, $txnId)
    {
        $companyId = app('currentCompanyId');
        $txn = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)->findOrFail($txnId);

        // Task 353: stream-locked staff cannot touch the other stream's bills.
        if (!$this->streamScopeAllowsTxn($txn)) {
            abort(403);
        }

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

        $upd = [
            'rider_id' => $riderId,
            'delivery_status' => $riderId ? ($txn->delivery_status && $txn->delivery_status !== 'returned' ? $txn->delivery_status : 'assigned') : null,
        ];
        // Delivery-duration stamp (owner, 3 Aug 2026): rider lagte hi ghari
        // shuru. DIFFERENT rider par re-assign = clock restart; unassign = clear.
        // hasColumn guard — cPanel PROD schema-drift self-heal convention.
        if (Schema::hasColumn('pos_transactions', 'rider_assigned_at')) {
            if (!$riderId) {
                $upd['rider_assigned_at'] = null;
            } elseif ((int) $txn->rider_id !== (int) $riderId || !$txn->rider_assigned_at) {
                $upd['rider_assigned_at'] = now();
            }
        }
        $txn->update($upd);

        return back()->with('success', $riderId ? 'Rider assigned.' : 'Rider removed.');
    }

    /** Dispatch / delivered / returned lifecycle. Returned = khata drop ONLY (never voids the bill). */
    public function updateStatus(Request $request, $txnId)
    {
        $companyId = app('currentCompanyId');
        $request->validate(['delivery_status' => 'required|in:assigned,dispatched,delivered,returned']);

        $txn = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)->whereNotNull('rider_id')->findOrFail($txnId);

        // Task 353: stream-locked staff cannot touch the other stream's bills.
        if (!$this->streamScopeAllowsTxn($txn)) {
            abort(403);
        }

        if ($txn->rider_settlement_id) {
            return $this->statusError($request, 'This bill is already settled — status is locked.');
        }

        // Terminal-state guard (owner, Jul 2026): delivered/returned lock the
        // rider — re-opening the status would silently unlock reassignment too.
        // Only forward move allowed from delivered is → returned (matches the
        // UI's Returned button); returned is fully final.
        $newStatus = $request->input('delivery_status');
        if ($txn->delivery_status === 'returned') {
            return $this->statusError($request, 'This delivery is already returned — status is final.');
        }
        if ($txn->delivery_status === 'delivered' && $newStatus !== 'returned') {
            return $this->statusError($request, 'This delivery is already delivered — it can only be marked returned.');
        }

        $upd = ['delivery_status' => $newStatus];
        if ($newStatus === 'delivered' && !$txn->delivered_at
            && Schema::hasColumn('pos_transactions', 'delivered_at')) {
            $upd['delivered_at'] = now();
        }
        $txn->update($upd);

        // Sale-screen Pending Deliveries panel (3 Aug 2026) marks FINAL bills
        // delivered via fetch — JSON clients get JSON; page forms keep back().
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'delivery_status' => $newStatus]);
        }

        return back()->with('success', 'Delivery status updated.');
    }

    /** Guard-failure reply: JSON clients (sale-screen popup fetch) get JSON 422,
     *  page forms keep the flash-redirect (3 Aug 2026). */
    private function statusError(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }
        return back()->with('error', $message);
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

        // Task 353: bulk action only touches the current user's own stream.
        $count = $this->applyStreamScope(PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('rider_id', $rider->id)
            ->whereNull('rider_settlement_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched']))
            ->update(array_merge(
                ['delivery_status' => $newStatus],
                // Bulk "All Delivered" bhi duration stamp kare (3 Aug 2026).
                ($newStatus === 'delivered' && Schema::hasColumn('pos_transactions', 'delivered_at'))
                    ? ['delivered_at' => now()] : []
            ));

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
            // Task 353: stream-scoped — a stream-locked manager can settle only
            // his own stream's cash (cross-stream bill_ids silently drop out and
            // the empty-set guard below rejects the request).
            $query = $this->applyStreamScope(PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('rider_id', $rider->id)
                ->where('payment_method', 'cash')
                ->whereNull('rider_settlement_id')
                ->where(function ($q) {
                    $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
                }));
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

    // ─── Prepaid conversion (admin/manager only) ───────────────────────────

    /**
     * Mark a delivery bill as Prepaid: flip payment_method cash → qr_payment so the
     * bill drops out of the rider's cash khata. Purely a LOCAL accounting correction:
     * PRA already has an immutable record with its original PayMode — we never
     * re-submit or modify the fiscal record.
     *
     * Guards (in order):
     *  1. Admin or manager only (pos_cashier / pos_delivery blocked).
     *  2. Bill must belong to this company.
     *  3. payment_method must be 'cash' (idempotent — already non-cash = no-op).
     *  4. Must be unsettled (rider_settlement_id IS NULL).
     *  5. Not 'returned' (returned bills are already off the khata).
     *  6. PRA-submitted bills are allowed; we log it clearly and warn in the flash.
     */
    public function markPrepaid(Request $request, $txnId)
    {
        $companyId = app('currentCompanyId');
        $user      = auth('pos')->user();

        // Role gate — only admin/manager; cashier and delivery_manager blocked.
        $allowedRoles = ['pos_admin', 'pos_manager'];
        if (!in_array($user->pos_role ?? '', $allowedRoles, true)) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => __('pos.admin_only_action')], 403);
            }
            return back()->with('error', __('pos.admin_only_action'));
        }

        $txn = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->findOrFail($txnId);

        // Task 353: stream-locked staff cannot touch the other stream's bills.
        if (!$this->streamScopeAllowsTxn($txn)) {
            abort(403);
        }

        // Delivery-context guard — only bills that are actual delivery bills
        // (order_type='delivery' AND rider assigned) may be reclassified.
        // This prevents the action from touching walk-in/dine-in cash bills
        // that happen to share the same company.
        if ($txn->order_type !== 'delivery' || empty($txn->rider_id)) {
            return back()->with('error', __('pos.mark_prepaid_err_not_delivery'));
        }

        // Idempotent — already non-cash, nothing to do.
        if ($txn->payment_method !== 'cash') {
            return back()->with('error', __('pos.mark_prepaid_err_not_cash'));
        }

        // Settled bills are locked (khata already closed for this bill).
        if ($txn->rider_settlement_id) {
            return back()->with('error', __('pos.mark_prepaid_err_settled'));
        }

        // Returned deliveries are already off the khata — no reclassification needed.
        if ($txn->delivery_status === 'returned') {
            return back()->with('error', __('pos.mark_prepaid_err_returned'));
        }

        $praSubmitted = !empty($txn->pra_invoice_number);

        $upd = [
            'payment_method'       => 'qr_payment',
            'cash_received'        => null,
            'change_due'           => null,
        ];
        if (\Schema::hasColumn('pos_transactions', 'prepaid_converted_at')) {
            $upd['prepaid_converted_at'] = now();
        }
        if (\Schema::hasColumn('pos_transactions', 'prepaid_converted_by')) {
            $upd['prepaid_converted_by'] = $user->id;
        }

        $txn->update($upd);

        // Verify the write landed (memory: eloquent-missing-attribute-null).
        $saved = \DB::table('pos_transactions')->where('id', $txn->id)->value('payment_method');

        \Log::info('POS prepaid conversion', [
            'company_id'    => $companyId,
            'txn_id'        => $txn->id,
            'invoice_no'    => $txn->invoice_number,
            'actor_id'      => $user->id,
            'actor_role'    => $user->pos_role,
            'pra_submitted' => $praSubmitted,
            'pra_invoice'   => $txn->pra_invoice_number,
            'written_pm'    => $saved,
        ]);

        $message = $praSubmitted
            ? __('pos.mark_prepaid_success_pra')
            : __('pos.mark_prepaid_success');

        return back()->with('success', $message);
    }

    /**
     * Revert a Prepaid conversion — restore the bill to cash so it re-enters
     * the rider's cash khata.  This is the guarded undo of markPrepaid.
     *
     * What markPrepaid changed (each reversed here):
     *  • payment_method   qr_payment  → cash
     *  • cash_received    null        → stays null  (original not stored; khata will show the bill again)
     *  • change_due       null        → stays null  (same reason)
     *  • prepaid_converted_at  timestamp → null  (audit stamp cleared)
     *  • prepaid_converted_by  user_id   → null  (audit stamp cleared)
     *
     * Guards (in order):
     *  1. Admin or manager only.
     *  2. Bill belongs to this company.
     *  3. prepaid_converted_at NOT NULL — must have been converted via this feature.
     *  4. rider_settlement_id IS NULL — only while unsettled.
     *  5. Rider still attached.
     *  6. Not 'returned' (same delivery-context guard as markPrepaid).
     */
    public function unmarkPrepaid(Request $request, $txnId)
    {
        $companyId = app('currentCompanyId');
        $user      = auth('pos')->user();

        // Role gate — only admin/manager.
        $allowedRoles = ['pos_admin', 'pos_manager'];
        if (!in_array($user->pos_role ?? '', $allowedRoles, true)) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => __('pos.admin_only_action')], 403);
            }
            return back()->with('error', __('pos.admin_only_action'));
        }

        $txn = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->findOrFail($txnId);

        // Task 353: stream-locked staff cannot touch the other stream's bills.
        if (!$this->streamScopeAllowsTxn($txn)) {
            abort(403);
        }

        // Must have been converted via markPrepaid — not an originally prepaid bill.
        if (empty($txn->prepaid_converted_at)) {
            return back()->with('error', __('pos.unmark_prepaid_err_not_converted'));
        }

        // Settled bills are locked (khata already closed).
        if ($txn->rider_settlement_id) {
            return back()->with('error', __('pos.unmark_prepaid_err_settled'));
        }

        // Rider must still be attached.
        if (empty($txn->rider_id)) {
            return back()->with('error', __('pos.unmark_prepaid_err_no_rider'));
        }

        // Returned deliveries are off the khata — reverting makes no sense.
        if ($txn->delivery_status === 'returned') {
            return back()->with('error', __('pos.mark_prepaid_err_returned'));
        }

        $upd = [
            'payment_method' => 'cash',
            // cash_received / change_due cannot be restored (not stored at conversion time).
            // They remain null; the bill is back on the rider's khata by payment_method alone.
        ];
        if (\Schema::hasColumn('pos_transactions', 'prepaid_converted_at')) {
            $upd['prepaid_converted_at'] = null;
        }
        if (\Schema::hasColumn('pos_transactions', 'prepaid_converted_by')) {
            $upd['prepaid_converted_by'] = null;
        }

        $txn->update($upd);

        // Verify the write landed.
        $saved = \DB::table('pos_transactions')->where('id', $txn->id)->value('payment_method');

        \Log::info('POS prepaid revert (unmark)', [
            'company_id'  => $companyId,
            'txn_id'      => $txn->id,
            'invoice_no'  => $txn->invoice_number,
            'actor_id'    => $user->id,
            'actor_role'  => $user->pos_role,
            'written_pm'  => $saved,
        ]);

        return back()->with('success', __('pos.unmark_prepaid_success'));
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
            ->whereNull('rider_settlement_id')
            ->findOrFail($txnId);

        $upd = ['delivery_status' => 'delivered'];
        if (!$txn->delivered_at && Schema::hasColumn('pos_transactions', 'delivered_at')) {
            $upd['delivered_at'] = now();
        }
        $txn->update($upd);

        return back()->with('success', 'Marked delivered.');
    }
}
