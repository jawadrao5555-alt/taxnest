<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosRider;
use App\Models\PosTransaction;
use App\Models\User;
use App\Services\PosFeatureService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rider LIVE Tracking (Aug 2026) — Unlimited-plan exclusive.
 *
 * Two halves:
 *  1. Stateless JSON API for the TaxNest Rider Android app
 *     (/api/rider-app/v1/*, bearer token; SHA-256 stored in pos_riders.app_token).
 *     Rider signs in with his EXISTING portal login (users row with
 *     pos_role='pos_rider'); login rotates the token → one active device.
 *  2. Admin live map + trails (/pos/riders/tracking*, PosAdminOnly group).
 *
 * Design notes:
 *  - Plan gates (riders_enabled + rider_tracking_enabled) re-checked on EVERY
 *    app call, so a downgrade stops uploads immediately.
 *  - pos_riders carries denormalized last_lat/lng/located_at so the admin
 *    20s poll never scans the points table.
 *  - Points older than 30 days purged opportunistically (no cron dependency).
 *  - Live PDO returns numeric columns as strings — cast floats/ints in JSON.
 */
class PosRiderTrackingController extends Controller
{
    private const POINT_MAX_AGE_DAYS = 7;    // oldest offline-buffered point accepted
    private const RETENTION_DAYS = 30;       // history kept for trails
    private const GAP_THRESHOLD_MINUTES = 5; // default gap detection threshold
    private const OFFLINE_HEURISTIC_MINUTES = 5; // created_at - recorded_at delta for offline tag

    // Bump on each Android release; APK hosted on OUR server (never a GitHub
    // release — desktop agents auto-update from this repo's releases/latest).
    private const APP_LATEST_VERSION = '1.2.0';
    private const APP_DOWNLOAD_URL = 'https://taxnest.com.pk/downloads/taxnest-rider.apk';

    // ─── Shared gates ───────────────────────────────────────────────────────

    /** Null when allowed; otherwise a translated refusal message. */
    private function planLockMessage(?Company $company): ?string
    {
        if (!$company) {
            return __('pos.plan_locked_feature');
        }
        $suspended = ($company->status ?? null) === 'suspended'
            || ($company->company_status ?? null) === 'suspended';
        if ($suspended) {
            return __('pos.plan_locked_feature');
        }
        if (!PosFeatureService::planAllows($company, 'riders_enabled')
            || !PosFeatureService::planAllows($company, 'rider_tracking_enabled')) {
            return __('pos.rt_plan_locked_api');
        }
        return null;
    }

    /** Resolve the rider from the Bearer token or abort with JSON. */
    private function riderFromToken(Request $request): PosRider
    {
        $token = (string) $request->bearerToken();
        $riderId = (int) strtok($token, '|');
        $rider = $riderId > 0 ? PosRider::find($riderId) : null;

        if (!$rider || !$rider->app_token || !$rider->is_active
            || !hash_equals($rider->app_token, hash('sha256', $token))) {
            abort(response()->json(['ok' => false, 'error' => 'unauthorized'], 401));
        }
        if ($rider->user_id) {
            $user = User::find($rider->user_id);
            if (!$user || !$user->is_active) {
                abort(response()->json(['ok' => false, 'error' => 'unauthorized'], 401));
            }
        }
        if ($msg = $this->planLockMessage(Company::find($rider->company_id))) {
            abort(response()->json(['ok' => false, 'error' => 'plan_locked', 'message' => $msg], 403));
        }
        return $rider;
    }

    // ─── Rider app API (stateless) ──────────────────────────────────────────

    /** POST /api/rider-app/v1/login {email, password} */
    public function appLogin(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)
            ->where('pos_role', 'pos_rider')->first();
        if (!$user || !$user->is_active || !Hash::check($request->password, $user->password)) {
            return response()->json(['ok' => false, 'error' => 'invalid_credentials',
                'message' => __('pos.rt_bad_login')], 401);
        }

        $rider = PosRider::where('company_id', $user->company_id)
            ->where('user_id', $user->id)->where('is_active', true)->first();
        if (!$rider) {
            return response()->json(['ok' => false, 'error' => 'no_rider',
                'message' => __('pos.rt_no_rider_record')], 403);
        }

        $company = Company::find($rider->company_id);
        if ($msg = $this->planLockMessage($company)) {
            return response()->json(['ok' => false, 'error' => 'plan_locked', 'message' => $msg], 403);
        }

        // Rotate: one active device per rider.
        $plain = $rider->id . '|' . Str::random(48);
        $rider->update(['app_token' => hash('sha256', $plain)]);

        return response()->json([
            'ok' => true,
            'token' => $plain,
            'rider' => [
                'id' => (int) $rider->id,
                'name' => $rider->name,
                'company' => $company->name ?? '',
            ],
            'duty' => (bool) $rider->on_duty,
        ]);
    }

    /** POST /api/rider-app/v1/duty {on: bool} */
    public function appDuty(Request $request)
    {
        $rider = $this->riderFromToken($request);
        $on = filter_var($request->input('on'), FILTER_VALIDATE_BOOLEAN);

        $rider->update($on
            ? ['on_duty' => true, 'duty_started_at' => now()]
            : ['on_duty' => false]);

        return response()->json(['ok' => true, 'duty' => $on]);
    }

    /**
     * POST /api/rider-app/v1/locations {points: [{lat, lng, acc?, at?}, ...]}
     * `at` = epoch milliseconds (client clock, clamped server-side).
     *
     * Offline-buffer support (v1.2.0+):
     *  - client_ts_ms stored per row for replay-dedupe via unique index
     *    (rider_id, client_ts_ms); insertOrIgnore silently skips duplicates.
     *  - last_lat/last_lng/last_located_at updated only when the batch's
     *    newest recorded_at is strictly newer than the stored value (regression
     *    guard: a replayed batch of old points must not overwrite a fresher fix).
     *  - Old APKs that omit `at` get client_ts_ms=NULL; NULL rows bypass the
     *    unique constraint (MySQL/SQLite both allow multiple NULLs in unique idx).
     */
    public function appLocations(Request $request)
    {
        $rider = $this->riderFromToken($request);

        if (!$rider->on_duty) {
            return response()->json(['ok' => false, 'error' => 'duty_off'], 409);
        }

        $points = $request->input('points');
        if (!is_array($points) || !count($points)) {
            return response()->json(['ok' => false, 'error' => 'no_points'], 422);
        }
        $points = array_slice($points, 0, 240);

        $rows = [];
        $newest = null;
        $oldestAccepted = now()->subDays(self::POINT_MAX_AGE_DAYS);
        foreach ($points as $p) {
            $lat = isset($p['lat']) ? (float) $p['lat'] : null;
            $lng = isset($p['lng']) ? (float) $p['lng'] : null;
            if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180
                || ($lat == 0.0 && $lng == 0.0)) {
                continue;
            }
            $at = now();
            $clientTsMs = null; // NULL for old APKs — bypasses unique constraint
            if (!empty($p['at']) && is_numeric($p['at'])) {
                $clientTsMs = (int) $p['at'];
                try {
                    // Epoch is absolute — convert to app TZ so recorded_at
                    // lines up with created_at/now() comparisons everywhere.
                    $at = Carbon::createFromTimestampMs($clientTsMs)
                        ->setTimezone(config('app.timezone'));
                } catch (\Throwable $e) {
                    $at = now();
                    $clientTsMs = null;
                }
                if ($at->gt(now())) {
                    $at = now();
                }
                if ($at->lt($oldestAccepted)) {
                    continue; // stale offline buffer — beyond accepted window
                }
            }
            // Stamp is_offline at insert time so trail() never has to rely on a
            // clock-skew-sensitive heuristic for new points.
            // A point is offline when its recorded_at is 5+ minutes older than
            // server now() — rider buffered it while off-network and uploaded later.
            // For old APKs that send no `at`, $clientTsMs is NULL and $at was
            // set to now() above, so lag == 0 → is_offline = false (correct:
            // we cannot know, and false is the safe / non-alarming default).
            //
            // Use raw timestamps: $at <= now() is already enforced above (future
            // values are clamped), so the difference is always non-negative.
            $isOffline = (now()->timestamp - $at->timestamp)
                >= (self::OFFLINE_HEURISTIC_MINUTES * 60);

            $rows[] = [
                'company_id'   => $rider->company_id,
                'rider_id'     => $rider->id,
                'lat'          => round($lat, 7),
                'lng'          => round($lng, 7),
                'accuracy_m'   => isset($p['acc']) && is_numeric($p['acc'])
                    ? min(65000, max(0, (int) $p['acc'])) : null,
                'recorded_at'  => $at->format('Y-m-d H:i:s'),
                'client_ts_ms' => $clientTsMs, // always present (may be NULL)
                'is_offline'   => $isOffline,  // stamped server-side at insert
                'created_at'   => now(),
            ];
            $lastRow = $rows[count($rows) - 1];
            if ($newest === null || $lastRow['recorded_at'] > $newest['recorded_at']) {
                $newest = $lastRow;
            }
        }

        if ($rows) {
            // insertOrIgnore: duplicate (rider_id, client_ts_ms) rows from a
            // replayed offline batch are silently skipped by the unique index.
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('pos_rider_locations')->insertOrIgnore($chunk);
            }

            // Regression guard: only advance last_lat/lng/located_at when the
            // incoming batch carries a strictly newer fix than what is stored.
            // NULL stored = always update (first ever fix for this rider).
            $currentLocatedAt = $rider->last_located_at
                ? $rider->last_located_at->format('Y-m-d H:i:s')
                : null;
            if ($currentLocatedAt === null || $newest['recorded_at'] > $currentLocatedAt) {
                $rider->update([
                    'last_lat'        => $newest['lat'],
                    'last_lng'        => $newest['lng'],
                    'last_located_at' => $newest['recorded_at'],
                ]);
            }
        }

        // Opportunistic retention purge — no cron dependency.
        if (random_int(1, 200) === 1) {
            DB::table('pos_rider_locations')
                ->where('company_id', $rider->company_id)
                ->where('recorded_at', '<', now()->subDays(self::RETENTION_DAYS))
                ->limit(3000)->delete();
        }

        return response()->json(['ok' => true, 'stored' => count($rows)]);
    }

    /** GET /api/rider-app/v1/me — app home screen summary. */
    public function appMe(Request $request)
    {
        $rider = $this->riderFromToken($request);

        // Owner fix #4 (3 Aug 2026): rider app ab sirf ginti nahi, poori list
        // dikhaye — bill no, customer, phone, address, raqam, maps link, aur
        // kitni der se assign hai. Purane APK is extra field ko ignore karte
        // hain (backward compatible).
        $hasAssignedAt = Schema::hasColumn('pos_transactions', 'rider_assigned_at');
        $openBills = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $rider->company_id)
            ->where('rider_id', $rider->id)
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->orderBy('id')
            ->get(['id', 'invoice_number', 'customer_name', 'customer_phone', 'delivery_address', 'total_amount', 'payment_method', 'delivery_status', 'created_at',
                   ...($hasAssignedAt ? ['rider_assigned_at'] : [])]);

        $deliveries = $openBills->map(function ($b) use ($hasAssignedAt) {
            $assignedAt = $hasAssignedAt && $b->rider_assigned_at
                ? Carbon::parse($b->rider_assigned_at)
                : null;
            // Task 285: is_prepaid = customer pre-paid online; rider should NOT
            // collect cash. Non-cash payment_method is the signal.
            $isPrepaid = $b->payment_method !== 'cash';
            return [
                'id'             => (int) $b->id,
                'invoice_number' => $b->invoice_number,
                'customer_name'  => $b->customer_name,
                'customer_phone' => $b->customer_phone,
                'address'        => $b->delivery_address,
                'amount'         => (float) $b->total_amount,
                'payment_method' => $b->payment_method,
                'is_prepaid'     => $isPrepaid,
                'status'         => $b->delivery_status,
                'assigned_at'    => $assignedAt?->toIso8601String(),
                'assigned_mins'  => $assignedAt ? (int) $assignedAt->diffInMinutes(now()) : null,
                'maps_url'       => filled($b->delivery_address)
                    ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($b->delivery_address)
                    : null,
            ];
        });

        return response()->json([
            'ok' => true,
            'rider' => ['id' => (int) $rider->id, 'name' => $rider->name],
            'duty' => (bool) $rider->on_duty,
            'duty_started_at' => optional($rider->duty_started_at)->toIso8601String(),
            'open_deliveries' => $openBills->count(),
            'deliveries' => $deliveries,
            'khata_owed' => (float) $rider->openCashBills()->sum('total_amount'),
            'last_located_at' => optional($rider->last_located_at)->toIso8601String(),
        ]);
    }

    /** GET /api/rider-app/v1/version — app self-update check (public). */
    public function appVersion()
    {
        return response()->json([
            'ok' => true,
            'latest' => self::APP_LATEST_VERSION,
            'url' => self::APP_DOWNLOAD_URL,
        ]);
    }

    /** POST /api/rider-app/v1/logout */
    public function appLogout(Request $request)
    {
        $rider = $this->riderFromToken($request);
        $rider->update(['app_token' => null, 'on_duty' => false]);
        return response()->json(['ok' => true]);
    }

    // ─── Admin: live map + trails ───────────────────────────────────────────

    /** GET /pos/riders/tracking — live map page (locked card when plan lacks). */
    public function trackingPage()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $locked = !PosFeatureService::planAllows($company, 'riders_enabled')
            || !PosFeatureService::planAllows($company, 'rider_tracking_enabled');

        $riders = $locked ? collect() : PosRider::where('company_id', $companyId)
            ->where('is_active', true)->orderBy('name')->get();

        // Company ki apni city (profile se) — map isi par khule (owner, Aug 2026:
        // "Pakistan ke map ko focus kiya jaye"); IP-lookup sirf fallback hai.
        $companyCity = trim((string) ($company->city ?? ''));

        return view('pos.rider-tracking', [
            'locked' => $locked,
            'riders' => $riders,
            'companyCity' => $companyCity,
        ]);
    }

    /** GET /pos/riders/tracking/data — 20s poll: last-known positions. */
    public function trackingData()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!PosFeatureService::planAllows($company, 'riders_enabled')
            || !PosFeatureService::planAllows($company, 'rider_tracking_enabled')) {
            return response()->json(['ok' => false, 'error' => 'plan_locked'], 403);
        }

        $open = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereNotNull('rider_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->selectRaw('rider_id, COUNT(*) AS c')
            ->groupBy('rider_id')->pluck('c', 'rider_id');

        $riders = PosRider::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('on_duty')->orderBy('name')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'phone' => $r->phone,
                'on_duty' => (bool) $r->on_duty,
                'duty_started_at' => optional($r->duty_started_at)->toIso8601String(),
                'lat' => $r->last_lat !== null ? (float) $r->last_lat : null,
                'lng' => $r->last_lng !== null ? (float) $r->last_lng : null,
                'located_at' => optional($r->last_located_at)->toIso8601String(),
                'seconds_ago' => $r->last_located_at
                    ? (int) abs(now()->diffInSeconds($r->last_located_at)) : null,
                'open_deliveries' => (int) ($open[$r->id] ?? 0),
            ])->values();

        return response()->json(['ok' => true, 'riders' => $riders, 'server_time' => now()->toIso8601String()]);
    }

    /** GET /pos/riders/tracking/trail/{rider}?date=Y-m-d&gap_min=N — polyline points. */
    public function trail($riderId, Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!PosFeatureService::planAllows($company, 'riders_enabled')
            || !PosFeatureService::planAllows($company, 'rider_tracking_enabled')) {
            return response()->json(['ok' => false, 'error' => 'plan_locked'], 403);
        }

        $rider = PosRider::where('company_id', $companyId)->findOrFail($riderId);

        $date = $request->query('date');
        try {
            $day = $date ? Carbon::createFromFormat('Y-m-d', $date) : now();
        } catch (\Throwable $e) {
            $day = now();
        }

        // Optional gap threshold override — clamped to a sane range.
        $gapMin = (int) ($request->query('gap_min', self::GAP_THRESHOLD_MINUTES));
        $gapMin = max(2, min(60, $gapMin));

        // Fetch full set. is_offline is NULL on pre-migration rows → heuristic
        // fallback used in gap detection below; created_at still fetched for
        // that fallback path.
        $rawPoints = DB::table('pos_rider_locations')
            ->where('company_id', $companyId)
            ->where('rider_id', $rider->id)
            ->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('recorded_at')
            ->get(['lat', 'lng', 'recorded_at', 'created_at', 'is_offline']);

        $total = $rawPoints->count();

        // ── Gap detection on the full (pre-downsample) set ──────────────────
        // We also compute a "boundary" flag so stride never drops the point
        // immediately before or after a gap.
        $gapThresholdSecs = $gapMin * 60;
        $offlineThresholdSecs = self::OFFLINE_HEURISTIC_MINUTES * 60;

        $gapMeta    = []; // keyed by full-set index of the point BEFORE the gap
        $boundaries = []; // set of full-set indices that must survive downsampling

        for ($i = 1; $i < $total; $i++) {
            $prev = $rawPoints[$i - 1];
            $curr = $rawPoints[$i];

            $prevTs = strtotime($prev->recorded_at);
            $currTs = strtotime($curr->recorded_at);
            $gapSecs = $currTs - $prevTs;

            if ($gapSecs >= $gapThresholdSecs) {
                // Determine if the batch of points after the gap were offline-buffered.
                //
                // Strategy (per-point):
                //  • is_offline NOT NULL (stamped at insert by server) → trust it directly.
                //  • is_offline IS NULL (pre-migration row) → fall back to
                //    created_at − recorded_at heuristic (original logic).
                //
                // A gap is "offline after" when the majority of the first 1-3 points
                // after the gap are classified as offline by whichever signal applies.
                $offlineCount = 0;
                $checkUpTo = min($i + 3, $total);
                for ($j = $i; $j < $checkUpTo; $j++) {
                    $pt = $rawPoints[$j];
                    if ($pt->is_offline !== null) {
                        // Column is authoritative — no heuristic needed.
                        if ((bool) $pt->is_offline) {
                            $offlineCount++;
                        }
                    } else {
                        // Pre-migration row: fall back to created_at − recorded_at.
                        $lag = strtotime($pt->created_at) - strtotime($pt->recorded_at);
                        if ($lag >= $offlineThresholdSecs) {
                            $offlineCount++;
                        }
                    }
                }
                $isOfflineAfter = $offlineCount >= (int) ceil(($checkUpTo - $i) / 2);

                $gapMeta[$i - 1] = [
                    'gap_secs'        => $gapSecs,
                    'is_offline_after' => $isOfflineAfter,
                ];
                // Protect boundary points from stride-based downsampling.
                $boundaries[$i - 1] = true;
                $boundaries[$i]     = true;
            }
        }

        // ── Downsample, preserving boundary points ───────────────────────────
        $stride = max(1, (int) ceil($total / 3000));
        $kept = []; // map: full-set index → kept point array index
        $trail = [];

        foreach ($rawPoints->values() as $idx => $p) {
            if ($stride === 1 || $idx % $stride === 0 || isset($boundaries[$idx])) {
                $kept[$idx] = count($trail);
                $trail[] = [
                    (float) $p->lat,
                    (float) $p->lng,
                    Carbon::parse($p->recorded_at)->format('H:i'),
                ];
            }
        }

        // ── Re-index gaps using kept[] map ───────────────────────────────────
        $gaps = [];
        foreach ($gapMeta as $fullIdx => $meta) {
            // Both boundary points are guaranteed kept, so this lookup always hits.
            if (!isset($kept[$fullIdx])) {
                continue; // safety (should not happen)
            }
            $gaps[] = [
                'after_idx'        => $kept[$fullIdx],
                'minutes'          => (int) round($meta['gap_secs'] / 60),
                'is_offline_after' => $meta['is_offline_after'],
            ];
        }

        return response()->json([
            'ok' => true,
            'rider' => ['id' => (int) $rider->id, 'name' => $rider->name],
            'date' => $day->format('Y-m-d'),
            'points' => array_values($trail),
            'gaps'   => $gaps,
        ]);
    }
}
