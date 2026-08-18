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

    // Task #1102: live-map warnings + auto duty-off.
    private const IDLE_MINUTES = 15;    // stationary-with-open-deliveries warning window
    private const IDLE_RADIUS_M = 120;  // movement below this across the window = "ruka hua"
    private const SILENT_MINUTES = 10;  // no upload while on duty = "GPS/net band"
    private const AUTO_OFF_HOUR = 3;    // late-night duty cutoff (app timezone)

    // APK hosted on OUR server (never a GitHub
    // release — desktop agents auto-update from this repo's releases/latest).
    // Latest version lives in the rider_app_latest_version SystemSetting (Task 443).
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

    // ─── Task #1102: auto duty-off (lazy, no cron dependency) ───────────────

    /**
     * Most recent late-night cutoff moment (app TZ). Duty sessions that
     * STARTED before this moment have run past the cutoff and get flipped off.
     * A rider who re-enables duty after the cutoff sets duty_started_at=now()
     * (> cutoff) and is naturally exempt until the next night — idempotent.
     */
    private function autoOffCutoff(): Carbon
    {
        $cutoff = now()->setTime(self::AUTO_OFF_HOUR, 0, 0);

        return $cutoff->gt(now()) ? $cutoff->subDay() : $cutoff;
    }

    /**
     * Company-wide lazy sweep — piggybacks on the admin trackingData poll
     * (like the retention lottery: live cPanel may have no cron; never rely
     * on Schedule:: alone). Single indexed UPDATE; flipped rows no longer
     * match the WHERE, so calling it on every 20s poll is safe.
     */
    private function sweepAutoDutyOff(int $companyId): void
    {
        $cutoff = $this->autoOffCutoff();
        $update = ['on_duty' => false];
        if (Schema::hasColumn('pos_riders', 'duty_auto_off_at')) {
            $update['duty_auto_off_at'] = now();
        }
        // NULL duty_started_at is left alone: every duty-on stamps it, so NULL
        // means we cannot prove the session crossed the cutoff — don't guess.
        PosRider::where('company_id', $companyId)
            ->where('on_duty', true)
            ->whereNotNull('duty_started_at')
            ->where('duty_started_at', '<=', $cutoff)
            ->update($update);
    }

    /**
     * Per-rider variant for the upload path: an admin who never opens the map
     * must not leave riders uploading all night. Flipping duty here makes the
     * very same request hit the per-point duty gate → 409 → the app flips its
     * local state (existing self-correct mechanism, no APK change).
     */
    private function maybeAutoDutyOff(PosRider $rider): void
    {
        // NULL duty_started_at = session age unprovable — leave it alone
        // (mirrors sweepAutoDutyOff; every real duty-on stamps the column).
        if (!$rider->on_duty || !$rider->duty_started_at
            || $rider->duty_started_at->gt($this->autoOffCutoff())) {
            return;
        }
        $update = ['on_duty' => false];
        if (Schema::hasColumn('pos_riders', 'duty_auto_off_at')) {
            $update['duty_auto_off_at'] = now();
        }
        $rider->update($update);
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

        // Any explicit duty toggle from the app clears the "auto off" stamp —
        // the note only describes the CURRENT off-duty state (hasColumn guard:
        // prod schema drift safe).
        $update = $on
            ? ['on_duty' => true, 'duty_started_at' => now()]
            : ['on_duty' => false];
        if (Schema::hasColumn('pos_riders', 'duty_auto_off_at')) {
            $update['duty_auto_off_at'] = null;
        }
        $rider->update($update);

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

        // Task #1102: lazy auto duty-off on the upload path — duty that ran
        // past the late-night cutoff flips off HERE, so the duty gate below
        // rejects the fresh points and the 409 tells the app to stop.
        $this->maybeAutoDutyOff($rider);

        // v1.3.0+: per-point duty enforcement replaces the old upfront global
        // 409 gate.  Fresh points (no `at` field, or server-computed lag <
        // OFFLINE_HEURISTIC_MINUTES) still require duty=ON — a fresh fix while
        // off-duty is rejected.  Buffered past-timestamp points (is_offline=true)
        // are accepted regardless of duty status so that an offline route
        // recorded during a duty session is preserved even when the rider
        // ends duty before connectivity returns.
        $dutyOn = (bool) $rider->on_duty;

        $points = $request->input('points');
        if (!is_array($points) || !count($points)) {
            return response()->json(['ok' => false, 'error' => 'no_points'], 422);
        }
        $points = array_slice($points, 0, 240);

        $rows      = [];
        $newestLive = null; // newest fresh (non-offline) point — drives regression guard
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

            // Per-point duty gate: fresh points require duty=ON; buffered past
            // points (is_offline) are accepted regardless of duty status.
            if (!$isOffline && !$dutyOn) {
                continue; // fresh GPS fix while rider is off-duty — skip
            }

            $row = [
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
            $rows[] = $row;

            // Regression guard tracks only LIVE (non-offline) points.
            // Drain batches of past-buffered points must not overwrite the
            // rider's current last-known live position on the admin map.
            if (!$isOffline) {
                if ($newestLive === null || $row['recorded_at'] > $newestLive['recorded_at']) {
                    $newestLive = $row;
                }
            }
        }

        if ($rows) {
            // insertOrIgnore: duplicate (rider_id, client_ts_ms) rows from a
            // replayed offline batch are silently skipped by the unique index.
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('pos_rider_locations')->insertOrIgnore($chunk);
            }

            // Regression guard: only advance last_lat/lng/located_at from a
            // LIVE (non-offline) fix that is strictly newer than what is stored.
            // Buffered past-timestamp drain batches are stored for trail history
            // but do not touch the denormalized position fields — a drain of
            // yesterday's route must not overwrite today's live rider position.
            if ($newestLive !== null) {
                $currentLocatedAt = $rider->last_located_at
                    ? $rider->last_located_at->format('Y-m-d H:i:s')
                    : null;
                if ($currentLocatedAt === null || $newestLive['recorded_at'] > $currentLocatedAt) {
                    $rider->update([
                        'last_lat'        => $newestLive['lat'],
                        'last_lng'        => $newestLive['lng'],
                        'last_located_at' => $newestLive['recorded_at'],
                    ]);
                }
            }
        }

        // Opportunistic retention purge — no cron dependency.
        if (random_int(1, 200) === 1) {
            DB::table('pos_rider_locations')
                ->where('company_id', $rider->company_id)
                ->where('recorded_at', '<', now()->subDays(self::RETENTION_DAYS))
                ->limit(3000)->delete();
        }

        // Backward-compat 409 for v1.2.0 clients (and v1.3.0 TrackingService):
        // When duty is OFF and no rows were accepted (stored==0), the batch
        // contained only fresh points (lag < OFFLINE_HEURISTIC_MINUTES, skipped
        // by the per-point duty gate) or permanently-invalid points (bad coords /
        // beyond 7-day window).  Returning 200 here would leave a v1.2.0
        // TrackingService running indefinitely after an admin duty-off — it relies
        // on 409 as its only server-side stop signal when backgrounded (the
        // MainActivity /me poll is paused while the app is not in the foreground).
        // v1.3.0 QueueDrain handles 409 gracefully (keeps queue, retries later).
        // When duty is OFF but stored > 0, at least one buffered past-timestamp
        // point was accepted — return 200 so the client trims those from the queue.
        if (!$dutyOn && count($rows) === 0) {
            return response()->json(['ok' => false, 'error' => 'duty_off'], 409);
        }

        // Return exact stored count so the app can trim its queue precisely.
        // count($rows) = points that passed all validation (lat/lng/age/duty
        // gate); insertOrIgnore may write fewer on duplicate replay, but the
        // client should remove all validated rows — duplicates are safe to drop.
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
            'khata_owed' => $rider->openCashRemaining(),
            'last_located_at' => optional($rider->last_located_at)->toIso8601String(),
        ]);
    }

    /** GET /api/rider-app/v1/version — app self-update check (public). */
    public function appVersion()
    {
        // Task #443: version comes ONLY from the admin-editable SystemSetting
        // (same release gate as the shell apps' /api/app-version). Empty =
        // update check disabled — no hardcoded fallback, otherwise old riders
        // would see an update prompt while the owner is still phone-testing.
        return response()->json([
            'ok' => true,
            'latest' => trim((string) \App\Models\SystemSetting::get('rider_app_latest_version', '')),
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

        // Task #320 (ZFC): dukan ki saved location — set ho to map isi par
        // khulta hai aur distinct shop marker dikhta hai. hasColumn guard:
        // prod par migration se pehle page 500 na ho.
        $shopLat = $shopLng = null;
        if (Schema::hasColumn('companies', 'shop_lat') && $company->shop_lat !== null && $company->shop_lng !== null) {
            $shopLat = (float) $company->shop_lat;
            $shopLng = (float) $company->shop_lng;
        }

        return view('pos.rider-tracking', [
            'locked' => $locked,
            'riders' => $riders,
            'companyCity' => $companyCity,
            'shopLat' => $shopLat,
            'shopLng' => $shopLng,
        ]);
    }

    /**
     * POST /pos/riders/tracking/resolve-link — Task #446 (ZFC, Aug 2026):
     * pasted Google Maps SHORT link (maps.app.goo.gl etc.) → lat/lng.
     * Browser can't follow those redirects (CORS); server does, with a fixed
     * Google-host allowlist (see GoogleMapsLinkResolver — SSRF-safe).
     */
    public function resolveShopLink(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!PosFeatureService::planAllows($company, 'riders_enabled')
            || !PosFeatureService::planAllows($company, 'rider_tracking_enabled')) {
            return response()->json(['ok' => false, 'error' => 'plan_locked'], 403);
        }

        $data = $request->validate(['url' => 'required|string|max:600']);

        if (!\App\Services\GoogleMapsLinkResolver::isResolvableUrl($data['url'])) {
            return response()->json(['ok' => false, 'error' => 'not_a_maps_link'], 422);
        }

        $ll = \App\Services\GoogleMapsLinkResolver::resolve($data['url']);
        if (!$ll) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        return response()->json(['ok' => true, 'lat' => $ll['lat'], 'lng' => $ll['lng']]);
    }

    /**
     * POST /pos/riders/tracking/shop-location — Task #320 (ZFC, Aug 2026):
     * admin map par pin rakh kar dukan ki location save karta hai.
     * PosAdminOnly route group; Pakistan-bounds validation (map PK-locked hai).
     */
    public function saveShopLocation(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!PosFeatureService::planAllows($company, 'riders_enabled')
            || !PosFeatureService::planAllows($company, 'rider_tracking_enabled')) {
            return response()->json(['ok' => false, 'error' => 'plan_locked'], 403);
        }
        if (!Schema::hasColumn('companies', 'shop_lat') || !Schema::hasColumn('companies', 'shop_lng')) {
            return response()->json(['ok' => false, 'error' => 'schema_not_ready'], 503);
        }

        $data = $request->validate([
            'lat' => 'required|numeric|between:22.8,37.5',
            'lng' => 'required|numeric|between:60.4,77.6',
        ]);

        $company->shop_lat = round((float) $data['lat'], 7);
        $company->shop_lng = round((float) $data['lng'], 7);
        $company->save();

        return response()->json([
            'ok' => true,
            'lat' => (float) $company->shop_lat,
            'lng' => (float) $company->shop_lng,
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

        // Task #1102: lazy auto duty-off sweep piggybacks on the poll
        // (like the retention lottery — live cPanel may have no cron).
        $this->sweepAutoDutyOff($companyId);

        $open = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereNotNull('rider_id')
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->selectRaw('rider_id, COUNT(*) AS c, MIN(' .
                (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_assigned_at')
                    ? 'COALESCE(rider_assigned_at, created_at)' : 'created_at') . ') AS oldest')
            ->groupBy('rider_id')->get()->keyBy('rider_id');

        $rows = PosRider::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('on_duty')->orderBy('name')
            ->get();

        // ── Task #1102: "ruka hua" idle detection (cheap) ────────────────────
        // Candidates: on duty + open deliveries + still uploading. One grouped
        // indexed query over the last IDLE_MINUTES gives each candidate's
        // bounding box — if the box stayed tiny across (nearly) the whole
        // window, the rider is stationary.
        $silentSecs = self::SILENT_MINUTES * 60;
        $isSilent = function ($r) use ($silentSecs) {
            if (!$r->on_duty) {
                return false;
            }
            // Freshness reference = the LATER of duty-start and last fix, so a
            // rider who just came on duty gets the full window as grace before
            // the red badge (his last fix may be hours old from yesterday).
            $refTs = max(
                $r->last_located_at ? $r->last_located_at->getTimestamp() : 0,
                $r->duty_started_at ? $r->duty_started_at->getTimestamp() : 0
            );
            if ($refTs === 0) {
                return true; // on duty, no fix ever, no known start — silent
            }
            return (now()->getTimestamp() - $refTs) > $silentSecs;
        };

        $idleCandidates = $rows->filter(fn ($r) => $r->on_duty
            && (int) ($open[$r->id]->c ?? 0) > 0
            && !$isSilent($r))->pluck('id')->all();

        $moveBoxes = collect();
        if ($idleCandidates) {
            $moveBoxes = DB::table('pos_rider_locations')
                ->where('company_id', $companyId)
                ->whereIn('rider_id', $idleCandidates)
                ->where('recorded_at', '>=', now()->subMinutes(self::IDLE_MINUTES)->format('Y-m-d H:i:s'))
                ->groupBy('rider_id')
                ->selectRaw('rider_id, MIN(lat) AS mnlat, MAX(lat) AS mxlat, MIN(lng) AS mnlng, MAX(lng) AS mxlng, COUNT(*) AS c, MIN(recorded_at) AS oldest')
                ->get()->keyBy('rider_id');
        }

        $isIdle = function ($r) use ($moveBoxes) {
            $box = $moveBoxes[$r->id] ?? null;
            if (!$box || (int) $box->c < 3) {
                return false;
            }
            // Coverage: points must span (almost) the whole window — a rider
            // on duty for only 5 minutes cannot be judged yet.
            $oldestAge = now()->getTimestamp() - strtotime((string) $box->oldest);
            if ($oldestAge < (self::IDLE_MINUTES - 3) * 60) {
                return false;
            }
            // Bounding-box span in metres (Pakistan ≈ 30°N: 1° lng ≈ 96.5 km).
            // Live PDO returns decimals as strings — cast.
            $latSpanM = ((float) $box->mxlat - (float) $box->mnlat) * 111320;
            $lngSpanM = ((float) $box->mxlng - (float) $box->mnlng) * 96500;
            return max($latSpanM, $lngSpanM) < self::IDLE_RADIUS_M;
        };

        $hasAutoOff = Schema::hasColumn('pos_riders', 'duty_auto_off_at');

        $riders = $rows->map(fn ($r) => [
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
            'open_deliveries' => (int) ($open[$r->id]->c ?? 0),
            // Kitne DIN se sab se purana bill atka hai (owner, 7 Aug 2026) —
            // Carbon 3 signed diff, abs() zaroori.
            'oldest_open_days' => (isset($open[$r->id]) && $open[$r->id]->oldest)
                ? (int) floor(abs(now()->diffInHours(\Carbon\Carbon::parse($open[$r->id]->oldest))) / 24) : 0,
            // Task #1102: warning badges + auto-off note.
            'is_silent' => $isSilent($r),                       // red: uploads stopped
            'is_idle' => !$isSilent($r) && $isIdle($r),         // amber: stationary w/ open bills
            'auto_off' => $hasAutoOff && !$r->on_duty && $r->duty_auto_off_at !== null,
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
                    // Task #1102: epoch seconds — client computes segment speed
                    // + stop durations + playback readout from deltas (extra
                    // array slot is backward-compatible for old clients).
                    strtotime((string) $p->recorded_at),
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
