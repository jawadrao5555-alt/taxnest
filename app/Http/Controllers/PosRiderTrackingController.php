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
            if (!empty($p['at']) && is_numeric($p['at'])) {
                try {
                    // Epoch is absolute — convert to app TZ so recorded_at
                    // lines up with created_at/now() comparisons everywhere.
                    $at = Carbon::createFromTimestampMs((int) $p['at'])
                        ->setTimezone(config('app.timezone'));
                } catch (\Throwable $e) {
                    $at = now();
                }
                if ($at->gt(now())) {
                    $at = now();
                }
                if ($at->lt($oldestAccepted)) {
                    continue; // stale offline buffer — beyond accepted window
                }
            }
            $rows[] = [
                'company_id' => $rider->company_id,
                'rider_id' => $rider->id,
                'lat' => round($lat, 7),
                'lng' => round($lng, 7),
                'accuracy_m' => isset($p['acc']) && is_numeric($p['acc'])
                    ? min(65000, max(0, (int) $p['acc'])) : null,
                'recorded_at' => $at->format('Y-m-d H:i:s'),
                'created_at' => now(),
            ];
            if ($newest === null || $rows[count($rows) - 1]['recorded_at'] > $newest['recorded_at']) {
                $newest = $rows[count($rows) - 1];
            }
        }

        if ($rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('pos_rider_locations')->insert($chunk);
            }
            $rider->update([
                'last_lat' => $newest['lat'],
                'last_lng' => $newest['lng'],
                'last_located_at' => $newest['recorded_at'],
            ]);
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

        $openDeliveries = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $rider->company_id)
            ->where('rider_id', $rider->id)
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->count();

        return response()->json([
            'ok' => true,
            'rider' => ['id' => (int) $rider->id, 'name' => $rider->name],
            'duty' => (bool) $rider->on_duty,
            'duty_started_at' => optional($rider->duty_started_at)->toIso8601String(),
            'open_deliveries' => (int) $openDeliveries,
            'khata_owed' => (float) $rider->openCashBills()->sum('total_amount'),
            'last_located_at' => optional($rider->last_located_at)->toIso8601String(),
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

        return view('pos.rider-tracking', ['locked' => $locked, 'riders' => $riders]);
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

    /** GET /pos/riders/tracking/trail/{rider}?date=Y-m-d — polyline points. */
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

        $points = DB::table('pos_rider_locations')
            ->where('company_id', $companyId)
            ->where('rider_id', $rider->id)
            ->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('recorded_at')
            ->get(['lat', 'lng', 'recorded_at']);

        // Downsample huge trails so the polyline stays light.
        $stride = max(1, (int) ceil($points->count() / 3000));
        $trail = $points->values()->filter(fn ($p, $i) => $i % $stride === 0)
            ->map(fn ($p) => [
                (float) $p->lat,
                (float) $p->lng,
                Carbon::parse($p->recorded_at)->format('H:i'),
            ])->values();

        return response()->json([
            'ok' => true,
            'rider' => ['id' => (int) $rider->id, 'name' => $rider->name],
            'date' => $day->format('Y-m-d'),
            'points' => $trail,
        ]);
    }
}
