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
use Illuminate\Support\Str;

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
            ->select('rider_id', DB::raw('COUNT(*) as bills'), DB::raw('COALESCE(SUM(' . PosRider::remainingExpr('pos_transactions') . '),0) as owed'))
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

        // Task #1115: pass current tracking thresholds so the settings form
        // can pre-fill with saved values (or the system defaults when NULL).
        $company = Company::find($companyId);
        $trackingEnabled = $company
            && PosFeatureService::planAllows($company, 'riders_enabled')
            && PosFeatureService::planAllows($company, 'rider_tracking_enabled')
            && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'rider_idle_minutes');
        $riderTrackingSettings = null;
        if ($trackingEnabled) {
            $riderTrackingSettings = [
                'idle_minutes'   => (int) ($company->rider_idle_minutes   ?? 15),
                'silent_minutes' => (int) ($company->rider_silent_minutes ?? 10),
                'auto_off_hour'  => (int) ($company->rider_auto_off_hour  ?? 3),
            ];
        }

        $pushConfigured = \App\Services\RiderPushService::isConfigured();
        // Firebase setup banner is platform-internal info (cPanel steps) — real
        // customer shops must never see it; only internal/QA accounts do.
        $pushBannerVisible = (bool) ($company->is_internal_account ?? false);
        self::logFcmKeyPresenceOnce();

        return view('pos.riders', compact('riders', 'khata', 'riderUsers', 'riderPasswords', 'settlements', 'trackingEnabled', 'riderTrackingSettings', 'pushConfigured'));
    }

    /**
     * Log once per 24 h that a Firebase credential is present.
     *
     * Extracted as a public static so the test can invoke it directly
     * against real production logic (cache key, TTL, log message) without
     * going through the full authenticated HTTP stack.
     */
    public static function logFcmKeyPresenceOnce(): void
    {
        if (\App\Services\RiderPushService::isConfigured()
            && !\Illuminate\Support\Facades\Cache::has('fcm_key_logged')) {
            // Log once so the owner can confirm in laravel.log after uploading the key.
            \Illuminate\Support\Facades\Log::info(
                'RiderPushService: Firebase credential is present — instant push is ACTIVE.'
            );
            \Illuminate\Support\Facades\Cache::put('fcm_key_logged', true, now()->addHours(24));
        }
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
            // Task 512 (Zahid Irfan, 12 Aug 2026): UNASSIGNED delivery bills
            // (rider_id NULL, delivery_status NULL) were invisible on every tab —
            // the assign dropdown existed but no bill ever reached it, so shops
            // needed the confined delivery-manager login just to assign. Pending
            // now also shows fresh unassigned delivery bills so the rider can be
            // chosen right here (same pos.deliveries.assign backend, no new path).
            // 7-din window on unassigned only: purane pre-feature delivery bills
            // (kabhi rider use hi nahi hua) pending tab ko flood na karein.
            ->where(function ($q) {
                $q->whereIn('delivery_status', ['assigned', 'dispatched'])
                    ->orWhere(function ($q2) {
                        $q2->whereNull('delivery_status')
                            ->whereNull('rider_id')
                            ->whereNull('rider_settlement_id')
                            ->where('order_type', 'delivery')
                            ->where('created_at', '>=', now()->subDays(7));
                    });
            })
            ->with('rider')
            ->orderBy(DB::raw($assignedTsExpr));
        $this->applyStreamScope($openBillsAll);
        $openBillsAll = $openBillsAll->get();

        // Task 524 (customer voice note, 12 Aug 2026): purane (pichhle business
        // days ke) UNASSIGNED delivery bills pending list mein "Rider Not
        // Assigned" demand ki tarah na khalein — woh ek alag collapsed "Purani
        // deliveries" section mein dikhte hain aur pending tab count mein NAHI
        // ginte (owner Option C: chhupao nahi, alag karo). Assigned/dispatched
        // bills har tareekh ke FRESH hi rehte hain (asal pending — unka behavior
        // bilkul unchanged). Fresh unassigned = business_date (fallback
        // created_at date) == current business day; 7-din window upar wali
        // query mein pehle se lagi hai (us se purane aate hi nahi).
        $bizToday = \App\Services\PosBusinessDay::current($companyId);
        [$openBillsFresh, $oldUnassigned] = $openBillsAll->partition(function ($b) use ($hasBizDate, $bizToday) {
            if ($b->rider_id || $b->delivery_status) {
                return true; // assigned/dispatched — always in the main list
            }
            $billDay = ($hasBizDate && $b->business_date)
                ? (string) $b->business_date
                : $b->created_at?->format('Y-m-d');
            return !$billDay || $billDay >= $bizToday;
        });
        $openBillsFresh = $openBillsFresh->values();
        $oldUnassigned  = $oldUnassigned->values();

        // Tab counts (computed on the collections — single DB round-trip each).
        // Pending = fresh unassigned + assigned/dispatched; purani unassigned
        // ki ginti section ke apne label par hai, tab badge par NAHI (Task 524).
        $tabCounts = [
            'pending'   => $openBillsFresh->count(),
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
            $bills = $openBillsFresh;
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

        // ─── Task 1104: nearest free rider suggestion ──────────────────────
        // Duty / distance-from-shop hints for the rider cards + assign
        // dropdowns — rendered ONLY when the rider_tracking_enabled plan gate
        // passes (Unlimited); every other plan keeps the exact old assign flow.
        // Distance = haversine on the denormalized pos_riders.last_lat/lng vs
        // the saved shop pin (companies.shop_lat/lng). hasColumn guards:
        // cPanel PROD schema-drift convention.
        $company = Company::find($companyId);
        $trackingHints = PosFeatureService::planAllows($company, 'riders_enabled')
            && PosFeatureService::planAllows($company, 'rider_tracking_enabled')
            && Schema::hasColumn('pos_riders', 'on_duty')
            && Schema::hasColumn('pos_riders', 'last_lat');
        $hasShopLocation = false;
        $riderHints = [];
        $suggestedRiderId = null;
        $ridersPicker = $riders;
        if ($trackingHints) {
            $shopLat = $shopLng = null;
            if (Schema::hasColumn('companies', 'shop_lat') && Schema::hasColumn('companies', 'shop_lng')
                && $company->shop_lat !== null && $company->shop_lng !== null) {
                $shopLat = (float) $company->shop_lat;
                $shopLng = (float) $company->shop_lng;
                $hasShopLocation = true;
            }
            foreach ($riders as $r) {
                $dist = null;
                // Distance only when the last fix is reasonably FRESH (≤6h) —
                // an off-duty rider's days-old ping must not claim "0.3 km away".
                // Carbon 3 signed diffs — abs() (seconds_ago bug class).
                $fresh = $r->last_located_at
                    && abs(now()->diffInMinutes($r->last_located_at)) <= 360;
                if ($hasShopLocation && $fresh && $r->last_lat !== null && $r->last_lng !== null) {
                    $dist = PosRider::haversineKm($shopLat, $shopLng, (float) $r->last_lat, (float) $r->last_lng);
                }
                $riderHints[$r->id] = ['on_duty' => (bool) $r->on_duty, 'distance_km' => $dist];
            }
            // Picker order: on-duty first, then fewest open deliveries, then
            // nearest, then name. Inactive khata-only riders sink to the bottom
            // (the view still shows them only on the bill they already hold).
            $rank = function ($r) use ($riderHints, $openDeliveryCounts) {
                $h = $riderHints[$r->id] ?? ['on_duty' => false, 'distance_km' => null];
                return [
                    $r->is_active ? 0 : 1,
                    $h['on_duty'] ? 0 : 1,
                    (int) ($openDeliveryCounts[$r->id] ?? 0),
                    $h['distance_km'] ?? INF,
                    mb_strtolower((string) $r->name),
                ];
            };
            $ridersPicker = $riders->sort(fn ($a, $b) => $rank($a) <=> $rank($b))->values();
            // Suggested = best on-duty AND free (no open deliveries) candidate;
            // the sort already put the nearest of those first. No such rider →
            // no badge (the human always picks either way).
            $best = $ridersPicker->first(fn ($r) => $r->is_active
                && ($riderHints[$r->id]['on_duty'] ?? false)
                && (int) ($openDeliveryCounts[$r->id] ?? 0) === 0);
            $suggestedRiderId = $best?->id;
        }

        // Pre-built <option> suffix per rider — ONE string reused by both assign
        // dropdowns (main table + old-unassigned section). Non-tracking plans
        // get exactly the old ":count out" suffix, nothing more.
        $hasBatteryPct = Schema::hasColumn('pos_riders', 'last_battery_pct')
            && Schema::hasColumn('pos_riders', 'on_duty');
        // Task 1138: battery freshness proxy — same 6-hour window as the
        // distance hint (Task 1104). last_located_at rides the same APK
        // heartbeat as last_battery_pct, so a stale fix = stale reading.
        // hasColumn guard: PROD drift rule.
        $hasBatteryLocatedAt = $hasBatteryPct
            && Schema::hasColumn('pos_riders', 'last_located_at');
        $riderOptionSuffix = [];
        foreach ($riders as $r) {
            $bits = [];
            $h = $trackingHints ? ($riderHints[$r->id] ?? null) : null;
            if ($h && $suggestedRiderId !== null && (int) $suggestedRiderId === (int) $r->id) {
                $bits[] = '★ ' . __('pos.rider_suggested_badge');
            }
            if ($h) {
                $bits[] = $h['on_duty'] ? __('pos.rider_duty_on_chip') : __('pos.rider_duty_off_chip');
            }
            $openC = (int) ($openDeliveryCounts[$r->id] ?? 0);
            if ($openC > 0) {
                $bits[] = __('pos.rider_out_pill', ['count' => $openC]);
            } elseif ($h) {
                $bits[] = __('pos.rider_free_chip');
            }
            if ($h && $h['distance_km'] !== null) {
                $bits[] = __('pos.rider_km_away', ['km' => number_format($h['distance_km'], 1)]);
            }
            // Task 1132/1138: low-battery marker at assign time. Hidden when
            // the last APK heartbeat (last_located_at) is older than 6 h —
            // a frozen reading from hours ago misleads more than it helps.
            // NULL battery (old APK) or NULL/stale last_located_at → no marker.
            $batteryFresh = $hasBatteryLocatedAt
                && $r->last_located_at
                && abs(now()->diffInMinutes($r->last_located_at)) <= 360;
            if ($hasBatteryPct && $batteryFresh && $r->last_battery_pct !== null
                && (int) $r->last_battery_pct <= 20 && $r->on_duty) {
                $bits[] = '🪫 ' . (int) $r->last_battery_pct . '%';
            }
            $riderOptionSuffix[$r->id] = count($bits) ? ' — ' . implode(' · ', $bits) : '';
        }

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

        // Task 786: load names for users who closed unassigned bills — keyed by user id.
        // Only the Delivered tab bills matter (only they can have delivered_by set).
        $deliveredByUsers = [];
        if (Schema::hasColumn('pos_transactions', 'delivered_by')) {
            $byIds = $allBills->where('delivery_status', 'delivered')
                ->whereNull('rider_id')
                ->pluck('delivered_by')
                ->filter()
                ->unique()
                ->values();
            if ($byIds->count()) {
                $deliveredByUsers = \App\Models\User::whereIn('id', $byIds)
                    ->pluck('name', 'id')
                    ->toArray();
            }
        }

        // ─── Task 1105: customer location + distance/ETA chips ─────────────
        // Only on tracking plans (Unlimited) AND once the customer_lat columns
        // landed (fresh idempotent migration — cPanel PROD drift guard).
        $custLocReady = $trackingHints && Schema::hasColumn('pos_transactions', 'customer_lat')
            && Schema::hasColumn('pos_transactions', 'track_token');
        $billEtas = [];
        $rememberedLoc = [];
        $shopPin = null;
        if ($custLocReady) {
            if ($hasShopLocation) {
                $shopPin = ['lat' => (float) $company->shop_lat, 'lng' => (float) $company->shop_lng];
            }
            $riderById = $riders->keyBy('id');
            foreach ($openBillsFresh as $b) {
                if ($b->customer_lat === null || $b->customer_lng === null || !$b->rider_id) continue;
                if (!in_array($b->delivery_status, ['assigned', 'dispatched'], true)) continue;
                $r = $riderById->get((int) $b->rider_id);
                // Chip only for an ON-DUTY rider with a FRESH fix (≤6h — same
                // freshness rule as the assign-dropdown distance hints).
                if (!$r || !$r->on_duty || $r->last_lat === null || $r->last_lng === null) continue;
                if (!$r->last_located_at || abs(now()->diffInMinutes($r->last_located_at)) > 360) continue;
                $km = PosRider::haversineKm((float) $r->last_lat, (float) $r->last_lng, (float) $b->customer_lat, (float) $b->customer_lng);
                $billEtas[$b->id] = ['km' => round($km, 1), 'min' => PosRider::etaMinutes($km)];
            }
            // Remembered per-phone pin → locate modal opens pre-pinned next time.
            if (Schema::hasColumn('pos_customers', 'geo_lat')) {
                $phones = $openBillsFresh
                    ->filter(fn ($b) => $b->customer_lat === null && $b->customer_phone)
                    ->pluck('customer_phone')->unique()->values();
                if ($phones->count()) {
                    $known = \App\Models\PosCustomer::where('company_id', $companyId)
                        ->whereIn('phone', $phones)
                        ->whereNotNull('geo_lat')->whereNotNull('geo_lng')
                        ->get(['phone', 'geo_lat', 'geo_lng'])->keyBy('phone');
                    foreach ($openBillsFresh as $b) {
                        if ($b->customer_lat === null && $b->customer_phone && ($c = $known->get($b->customer_phone))) {
                            $rememberedLoc[$b->id] = ['lat' => (float) $c->geo_lat, 'lng' => (float) $c->geo_lng];
                        }
                    }
                }
            }
        }

        return view('pos.deliveries', compact('bills', 'riders', 'khataBills', 'day', 'openDeliveryCounts', 'openDeliveryOldest', 'tabCounts', 'activeTab', 'riderDaySummary', 'isAdminOrManager', 'oldUnassigned', 'deliveredByUsers', 'trackingHints', 'hasShopLocation', 'riderHints', 'suggestedRiderId', 'ridersPicker', 'riderOptionSuffix', 'custLocReady', 'billEtas', 'rememberedLoc', 'shopPin'));
    }

    // ─── Task 1105: customer pin, public track link & ETA poll ─────────────

    /** Shared gate: Unlimited tracking plan + schema readiness. Null = OK. */
    private function customerTrackGateError()
    {
        $company = Company::find(app('currentCompanyId'));
        if (!PosFeatureService::planAllows($company, 'riders_enabled')
            || !PosFeatureService::planAllows($company, 'rider_tracking_enabled')) {
            return response()->json(['ok' => false, 'error' => 'plan_locked', 'message' => __('pos.rt_plan_locked_api')], 403);
        }
        if (!Schema::hasColumn('pos_transactions', 'customer_lat')
            || !Schema::hasColumn('pos_transactions', 'track_token')) {
            return response()->json(['ok' => false, 'error' => 'schema_not_ready'], 503);
        }
        return null;
    }

    /**
     * POST /pos/deliveries/{id}/customer-location — save the customer's
     * delivery pin on a bill. Accepts EITHER lat/lng (mini-map pick /
     * client-parsed "31.52, 74.35" text) OR url (Google Maps link — the
     * server follows redirects; SSRF-safe allowlist in GoogleMapsLinkResolver,
     * same flow as the shop-location paste). Also remembers the pin on the
     * matching pos_customers row (by phone) for the next order.
     */
    public function saveCustomerLocation(Request $request, $txnId)
    {
        if ($err = $this->customerTrackGateError()) return $err;
        $companyId = app('currentCompanyId');
        $txn = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)->findOrFail($txnId);
        if (!$this->streamScopeAllowsTxn($txn)) abort(403);
        if (in_array($txn->delivery_status, ['delivered', 'returned'], true)) {
            return response()->json(['ok' => false, 'error' => 'already_closed'], 422);
        }

        if ($request->filled('url')) {
            $data = $request->validate(['url' => 'required|string|max:600']);
            if (!\App\Services\GoogleMapsLinkResolver::isResolvableUrl($data['url'])) {
                return response()->json(['ok' => false, 'error' => 'not_a_maps_link'], 422);
            }
            $ll = \App\Services\GoogleMapsLinkResolver::resolve($data['url']);
            if (!$ll) {
                return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            }
            $lat = (float) $ll['lat'];
            $lng = (float) $ll['lng'];
            // Map is PK-locked — mirror the shop-location bounds.
            if ($lat < 22.8 || $lat > 37.5 || $lng < 60.4 || $lng > 77.6) {
                return response()->json(['ok' => false, 'error' => 'out_of_bounds'], 422);
            }
            // "Find" button: resolve the link only — the cashier checks the
            // pin on the mini map first, Save then posts plain lat/lng.
            if ($request->boolean('resolve_only')) {
                return response()->json(['ok' => true, 'resolved' => true, 'lat' => round($lat, 7), 'lng' => round($lng, 7)]);
            }
        } else {
            $data = $request->validate([
                'lat' => 'required|numeric|between:22.8,37.5',
                'lng' => 'required|numeric|between:60.4,77.6',
            ]);
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];
        }

        $txn->update(['customer_lat' => round($lat, 7), 'customer_lng' => round($lng, 7)]);

        // Remember per phone — best-effort only, never blocks the bill save.
        if ($txn->customer_phone && Schema::hasColumn('pos_customers', 'geo_lat')) {
            try {
                \App\Models\PosCustomer::where('company_id', $companyId)
                    ->where('phone', $txn->customer_phone)
                    ->update(['geo_lat' => round($lat, 7), 'geo_lng' => round($lng, 7)]);
            } catch (\Throwable $e) {
                // ignore — remembered pin is a convenience, not a record
            }
        }

        return response()->json(['ok' => true, 'lat' => round($lat, 7), 'lng' => round($lng, 7)]);
    }

    /**
     * POST /pos/deliveries/{id}/track-link — mint (or reuse) the public
     * customer tracking URL for a bill. Token = 48-char random (unguessable);
     * the public endpoints go dead once the bill is delivered/returned, so a
     * closed bill refuses to mint. Absolute URL from the live request host —
     * route() would force https on plain-http dev (memory rule).
     */
    public function trackLink(Request $request, $txnId)
    {
        if ($err = $this->customerTrackGateError()) return $err;
        $companyId = app('currentCompanyId');
        $txn = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)->findOrFail($txnId);
        if (!$this->streamScopeAllowsTxn($txn)) abort(403);
        if (in_array($txn->delivery_status, ['delivered', 'returned'], true)) {
            return response()->json(['ok' => false, 'error' => 'already_closed'], 422);
        }

        if (!$txn->track_token) {
            $txn->update(['track_token' => Str::random(48)]);
        }
        $url = $request->getSchemeAndHttpHost() . '/track/' . $txn->track_token;
        $shop = Company::find($companyId)?->name ?: 'TaxNest POS';

        return response()->json([
            'ok'      => true,
            'url'     => $url,
            'wa_text' => __('pos.cl_wa_message', ['shop' => $shop, 'link' => $url]),
            'phone'   => (string) ($txn->customer_phone ?? ''),
        ]);
    }

    /**
     * GET /pos/deliveries/eta/data — lightweight board poll: distance/ETA for
     * every open located bill with an on-duty rider. Straight-line haversine ×
     * city-speed factor (PosRider::etaMinutes) — deliberately no routing API.
     */
    public function etaData(Request $request)
    {
        if ($err = $this->customerTrackGateError()) return $err;
        $companyId = app('currentCompanyId');

        $q = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereNotNull('rider_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->whereNotNull('customer_lat')->whereNotNull('customer_lng');
        $this->applyStreamScope($q);
        $bills = $q->get(['id', 'rider_id', 'customer_lat', 'customer_lng']);

        $etas = [];
        if ($bills->count() && Schema::hasColumn('pos_riders', 'last_lat')) {
            $riders = PosRider::where('company_id', $companyId)
                ->whereIn('id', $bills->pluck('rider_id')->unique()->values())
                ->get()->keyBy('id');
            foreach ($bills as $b) {
                $r = $riders->get((int) $b->rider_id);
                if (!$r || !$r->on_duty || $r->last_lat === null || $r->last_lng === null) continue;
                // Carbon 3 signed diffs — abs(); freshness rule same as board hints (≤6h).
                if (!$r->last_located_at || abs(now()->diffInMinutes($r->last_located_at)) > 360) continue;
                $km = PosRider::haversineKm((float) $r->last_lat, (float) $r->last_lng, (float) $b->customer_lat, (float) $b->customer_lng);
                // Live PDO returns numerics as strings — cast for JS (memory rule).
                $etas[(string) $b->id] = ['km' => round($km, 1), 'min' => PosRider::etaMinutes($km)];
            }
        }

        return response()->json(['ok' => true, 'etas' => $etas]);
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
            return $this->statusError($request, 'This bill is already settled — rider cannot be changed.');
        }
        // Rider is LOCKED once the delivery reached a terminal state (owner,
        // Jul 2026): delivered/returned bills keep the rider who actually ran
        // them — reassigning would silently move the cash khata to someone who
        // never carried the order. Reassign stays open while assigned/dispatched
        // (rider suddenly unavailable → pick another; khata follows rider_id).
        if (in_array($txn->delivery_status, ['delivered', 'returned'], true)) {
            return $this->statusError($request, 'This delivery is already ' . $txn->delivery_status . ' — rider can no longer be changed.');
        }
        // Only delivery-shaped bills can carry a rider.
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

        // Task #1106: push only on a genuinely NEW assignment for this rider —
        // re-saving the same rider must not re-ping his phone.
        $isNewAssignment = $riderId && (int) $txn->rider_id !== (int) $riderId;

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

        // Task #1106: instant FCM push to the newly assigned rider. Fire-and-
        // forget (runs in app()->terminating, all failures swallowed) — the
        // assign itself can never block or fail because of push. The 15-min
        // app poll stays as fallback for phones where push is unavailable.
        if ($isNewAssignment) {
            \Illuminate\Support\Facades\Log::info('RiderPushService: queued from Deliveries-board assign', [
                'rider_id' => $riderId,
                'txn_id'   => $txn->id,
            ]);
            \App\Services\RiderPushService::queuePush((int) $riderId);
        }

        // Sale-screen Pending Deliveries popup (Task 513) assigns via fetch —
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

    /** Dispatch / delivered / returned lifecycle. Returned = khata drop ONLY (never voids the bill). */
    public function updateStatus(Request $request, $txnId)
    {
        $companyId = app('currentCompanyId');
        $request->validate(['delivery_status' => 'required|in:assigned,dispatched,delivered,returned']);

        $txn = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)->findOrFail($txnId);

        // Task 353: stream-locked staff cannot touch the other stream's bills.
        if (!$this->streamScopeAllowsTxn($txn)) {
            abort(403);
        }

        // Task 774: unassigned delivery bill (rider_id NULL) — only 'delivered'
        // allowed. No rider cash, no khata, no settlement involved; just closes
        // the pending bill. All other transitions (dispatched, returned) stay
        // gated behind rider_id NOT NULL.
        // status=completed guard mirrors the board query — incomplete/held bills
        // must not be closeable via a direct POST.
        if (!$txn->rider_id) {
            $newStatus = $request->input('delivery_status');
            if ($newStatus !== 'delivered'
                || $txn->delivery_status !== null
                || $txn->order_type !== 'delivery'
                || $txn->status !== 'completed'
                || $txn->rider_settlement_id) {
                return $this->statusError($request, 'Unassigned delivery bills can only be marked delivered (once).');
            }
            $upd = ['delivery_status' => 'delivered'];
            if (Schema::hasColumn('pos_transactions', 'delivered_at')) {
                $upd['delivered_at'] = now();
            }
            // Task 786: stamp who closed the unassigned bill for audit trail.
            if (Schema::hasColumn('pos_transactions', 'delivered_by')) {
                $upd['delivered_by'] = auth('pos')->id();
            }
            $txn->update($upd);
            if ($request->expectsJson()) {
                return response()->json(['success' => true, 'delivery_status' => 'delivered', 'return_url' => null, 'auto_return_id' => null, 'auto_return_invoice' => null]);
            }
            return back()->with('success', 'Delivery marked as delivered.');
        }

        if ($txn->rider_settlement_id) {
            // Task 773 safety net: a settled bill stuck at assigned/dispatched may
            // move FORWARD to delivered only (clears Pending zombies). No
            // delivered_at stamp (settle time ≠ delivery time). Returned and
            // reassign stay locked — they would reverse settled cash.
            if ($request->input('delivery_status') === 'delivered'
                && in_array($txn->delivery_status, ['assigned', 'dispatched'], true)) {
                $txn->update(['delivery_status' => 'delivered']);
                if ($request->expectsJson()) {
                    return response()->json(['success' => true, 'delivery_status' => 'delivered', 'return_url' => null, 'auto_return_id' => null, 'auto_return_invoice' => null]);
                }
                return back()->with('success', 'Delivery status updated.');
            }
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
        // Task 847: stamp returned_at when a single bill is marked returned —
        // mirrors the bulk path (Task 839) so the column is consistent across
        // all return paths regardless of Schema presence.
        if ($newStatus === 'returned' && Schema::hasColumn('pos_transactions', 'returned_at')) {
            $upd['returned_at'] = now();
        }
        $txn->update($upd);

        // Auto full return / credit note (Task 586): the moment a bill is
        // marked returned, the FULL return bill is created automatically via
        // the shared PosReturnService — no manual form. Runs for EVERY
        // deliveries-board role (cashier/delivery manager included); the
        // manager-only gate stays on the MANUAL form only. Status + khata
        // drop are already applied above — a failed auto return NEVER blocks
        // the board (falls back to the Task 570 manual prompt below).
        $autoReturn = null;
        if ($newStatus === 'returned') {
            $autoReturn = $this->attemptAutoReturn($txn, $request->boolean('wastage'));
        }

        // Return / credit-note prompt (Task 570): a PRA-reported bill coming
        // back with the rider should flow straight into the return-bill form —
        // "returned" alone only drops the rider khata, it never fixes tax/stock.
        // Shown ONLY when the auto return did not happen (existing partial
        // return, or a failure). Cashiers never see the prompt (the manual
        // form is manager/owner-only).
        $returnUrl = null;
        if ($newStatus === 'returned' && !$autoReturn
            && Schema::hasColumn('pos_transactions', 'transaction_type')
            && !(auth('pos')->user()?->posCashierBlocked())
            && \App\Services\PosReturnService::returnableReason($txn->fresh()) === null) {
            $returnUrl = route('pos.transaction.return-form', $txn->id, false);
        }

        // Sale-screen Pending Deliveries panel (3 Aug 2026) marks FINAL bills
        // delivered via fetch — JSON clients get JSON; page forms keep back().
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'delivery_status' => $newStatus,
                'return_url' => $returnUrl,
                'auto_return_id' => $autoReturn?->id,
                'auto_return_invoice' => $autoReturn?->invoice_number,
            ]);
        }

        if ($autoReturn) {
            return back()->with('success', __('pos.return_auto_created', ['invoice' => $autoReturn->invoice_number]));
        }

        if ($returnUrl) {
            return back()->with('success', 'Delivery status updated.')
                ->with('return_prompt_url', $returnUrl)
                ->with('return_prompt_invoice', $txn->invoice_number)
                ->with('return_prompt_partial', (float) ($txn->rider_partial_paid ?? 0));
        }

        return back()->with('success', 'Delivery status updated.');
    }

    /**
     * Task 586: attempt the automatic FULL return (credit note) for a bill
     * just marked returned. Refund method is always CASH — the rider never
     * handed the cash over, so the day-close stays net-zero.
     *
     * Guards (skip → NULL, caller falls back to the manual prompt):
     *  - any existing (partial) return on the bill — double refund kabhi nahi;
     *  - non-returnable parents (returns-of-returns, non-completed) — except
     *    PROVISIONAL parents, which produce a LOCAL return (record/stock only,
     *    nothing goes to PRA);
     *  - any exception — logged, never blocks the status/khata drop.
     */
    private function attemptAutoReturn(PosTransaction $txn, bool $wastage): ?PosTransaction
    {
        if (!Schema::hasColumn('pos_transactions', 'transaction_type')) {
            return null; // prod schema drift — manual prompt handles it
        }
        try {
            $fresh = PosTransaction::withoutGlobalScope('hide_archived')->find($txn->id);
            if (!$fresh) {
                return null;
            }
            $reason = \App\Services\PosReturnService::returnableReason($fresh);
            if ($reason !== null && $reason !== 'provisional') {
                return null;
            }
            // Double-refund guard: koi (partial) return pehle se ban chuka ho
            // to auto SKIP — remaining items manual form se hi wapis hon.
            if (\App\Services\PosReturnService::hasExistingReturn($fresh)) {
                return null;
            }
            $result = \App\Services\PosReturnService::createReturn(
                (int) $fresh->company_id,
                (int) $fresh->id,
                null,          // NULL = full return of every remaining line
                'cash',        // cash was never collected — net-zero day close
                auth('pos')->id(),
                ['wastage' => $wastage, 'allow_provisional' => true]
            );
            if (isset($result['error'])) {
                \Illuminate\Support\Facades\Log::warning('Rider auto-return refused', ['tx' => $txn->id, 'err' => $result['error']]);
                return null;
            }
            // Post-commit PRA queue/submit (fiscal-device rows just queue).
            \App\Services\PosReturnService::submitToPraPostCommit($result);

            return $result['return'];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Rider auto-return failed', ['tx' => $txn->id, 'err' => $e->getMessage()]);
            return null;
        }
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
        $openQuery = fn () => $this->applyStreamScope(PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('rider_id', $rider->id)
            ->whereNull('rider_settlement_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched']));

        // Bulk RETURNED (Task 586): per-bill so every bill also gets its auto
        // full return (credit note) — the wastage choice applies to ALL of
        // them. Status flips first per bill; a failed auto return never blocks
        // the rest (same fallback rule as the single-bill path).
        if ($newStatus === 'returned') {
            $bills = $openQuery()->orderBy('id')->get();
            if ($bills->isEmpty()) {
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => 'No open deliveries for ' . $rider->name . '.'], 422);
                }
                return back()->with('error', 'No open deliveries for ' . $rider->name . '.');
            }
            $wastage = $request->boolean('wastage');
            $made = 0;
            $stampReturnedAt = Schema::hasColumn('pos_transactions', 'returned_at');
            foreach ($bills as $b) {
                $b->update(array_merge(
                    ['delivery_status' => 'returned'],
                    $stampReturnedAt ? ['returned_at' => now()] : []
                ));
                if ($this->attemptAutoReturn($b, $wastage)) {
                    $made++;
                }
            }
            $key = $made > 0 ? 'pos.return_auto_bulk' : 'pos.return_auto_bulk_none';

            return back()->with('success', __($key, ['count' => $bills->count(), 'made' => $made]));
        }

        $count = $openQuery()
            ->update(array_merge(
                ['delivery_status' => $newStatus],
                // Bulk "All Delivered" bhi duration stamp kare (3 Aug 2026).
                ($newStatus === 'delivered' && Schema::hasColumn('pos_transactions', 'delivered_at'))
                    ? ['delivered_at' => now()] : []
            ));

        if ($count === 0) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'No open deliveries for ' . $rider->name . '.'], 422);
            }
            return back()->with('error', 'No open deliveries for ' . $rider->name . '.');
        }

        return back()->with('success', $count . ' ' . ($count === 1 ? 'delivery' : 'deliveries') . ' marked ' . $newStatus . ' for ' . $rider->name . '.');
    }

    /** Settle selected open CASH bills for one rider (partial = per-bill selection).
     *  settle_all=1 (Pending Deliveries panel, Task 123): settle EVERY open cash
     *  bill on the rider's khata in one click — no bill_ids needed. JSON clients
     *  (the sale-screen panel) get JSON back instead of a redirect.
     *  received_amount (Task 525, "aadha cash abhi, baqi baad"): optional — the
     *  cash actually handed over. Applies oldest-first: fully covered bills
     *  settle, the remainder lands on the next bill's rider_partial_paid and the
     *  rest of the khata stays outstanding. Omitted = full settle (unchanged). */
    public function settle(Request $request, $riderId)
    {
        $companyId = app('currentCompanyId');
        $rider = PosRider::where('company_id', $companyId)->findOrFail($riderId);

        $settleAll = $request->boolean('settle_all');
        $request->validate([
            'bill_ids' => ($settleAll ? 'nullable' : 'required') . '|array|min:1',
            'bill_ids.*' => 'integer',
            'received_amount' => 'nullable|numeric',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $rider, $companyId, $settleAll) {
            // Lock + re-verify each bill is genuinely open rider-cash for THIS rider.
            // Task 353: stream-scoped — a stream-locked manager can settle only
            // his own stream's cash (cross-stream bill_ids silently drop out and
            // the empty-set guard below rejects the request).
            // Oldest first — partial receipts must clear the oldest khata first.
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
            $bills = $query->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();

            if ($bills->isEmpty()) {
                $msg = $settleAll ? 'No open cash bills on this rider\'s khata.' : 'No open cash bills matched the selection.';
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return back()->with('error', $msg);
            }

            $hasPartialCol = Schema::hasColumn('pos_transactions', 'rider_partial_paid');
            $remainingOf = fn ($b) => round((float) $b->total_amount - ($hasPartialCol ? (float) ($b->rider_partial_paid ?? 0) : 0), 2);
            $outstanding = round((float) $bills->sum($remainingOf), 2);

            // Received amount: default = full outstanding of the selection.
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
            // Pre-migration schema can't hold a partial remainder — full only.
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
                    // Degenerate zero-remaining bill — mark settled, nothing owed.
                    $settledIds[] = $b->id;
                    continue;
                }
                $applied = min($left, $rem);
                $allocation[] = [
                    'bill_id' => (int) $b->id,
                    'amount' => round($applied, 2),
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
                'company_id' => $companyId,
                'rider_id' => $rider->id,
                'settled_by' => auth('pos')->id(),
                // Cash actually received NOW (partials already received earlier
                // are on their own settlement rows — never double-counted).
                'total_amount' => $received,
                'bill_count' => count($settledIds),
                'notes' => $request->input('notes'),
            ];
            if (Schema::hasColumn('pos_rider_settlements', 'allocation')) {
                $settlementData['allocation'] = $allocation;
                $settlementData['panel'] = 'pra';
            }
            $settlement = PosRiderSettlement::create($settlementData);

            if ($settledIds) {
                PosTransaction::withoutGlobalScope('hide_archived')
                    ->whereIn('id', $settledIds)
                    ->update([
                        'rider_settlement_id' => $settlement->id,
                        'rider_settled_at' => now(),
                    ]);
                // Task 773: cash settle = delivery ka kaam khatam. Fully settled
                // bills still sitting at assigned/dispatched auto-advance to
                // delivered so they leave the Pending tab (no more zombies).
                // delivered_at is deliberately NOT stamped — settle time ≠ actual
                // delivery time ("Delivered in X min" chip skips NULL delivered_at).
                PosTransaction::withoutGlobalScope('hide_archived')
                    ->whereIn('id', $settledIds)
                    ->whereIn('delivery_status', ['assigned', 'dispatched'])
                    ->update(['delivery_status' => 'delivered']);
            }
            if ($partialBill) {
                PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('id', $partialBill[0])
                    ->update(['rider_partial_paid' => $partialBill[1]]);
            }

            // Whole-khata remaining AFTER this receipt (audit: kitna reh gaya).
            $khataLeft = $rider->openCashRemaining();
            if (Schema::hasColumn('pos_rider_settlements', 'outstanding_after')) {
                $settlement->forceFill(['outstanding_after' => $khataLeft])->save();
            }

            $msg = $khataLeft > 0.009
                ? __('pos.partial_settled_msg', [
                    'amount' => number_format($received),
                    'name' => $rider->name,
                    'left' => number_format($khataLeft),
                ])
                : 'Settled Rs. ' . number_format($received) . ' (' . count($settledIds) . ' bills) from ' . $rider->name . '.';
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'total_amount' => (float) $received,
                    'bill_count' => count($settledIds),
                    'outstanding_after' => (float) $khataLeft,
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

        $owed = $rider->openCashRemaining();

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
