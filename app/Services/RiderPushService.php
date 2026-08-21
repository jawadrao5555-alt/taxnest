<?php

namespace App\Services;

use App\Models\PosRider;
use App\Models\PosTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Rider-app instant push via Firebase Cloud Messaging HTTP v1 (Task #1106).
 *
 * Replaces the 15-minute WorkManager poll as the PRIMARY new-delivery alert;
 * the poll stays as fallback for phones where push fails (no Play Services,
 * force-stopped app, missing google-services.json build, old APK).
 *
 * Design rules:
 *  - FIRE-AND-FORGET: callers use queuePush() which defers the network call
 *    to app()->terminating() — after the response is flushed — and every
 *    failure is swallowed (logged at warning). Push must NEVER block or fail
 *    an assign/pay path.
 *  - Data-only message (no `notification` block): the app's PushService
 *    routes the payload through DeliveryNotifier.process(), the SAME dedupe
 *    used by the /me poll — push + poll can never double-notify.
 *  - Payload = the rider's CURRENT open-delivery list (same shape/dedupe
 *    semantics as the /me `deliveries` array), capped to fit FCM's 4 KB
 *    data limit.
 *  - Credential = Firebase service-account JSON, loaded from
 *    storage/app/firebase/rider-fcm.json or FIREBASE_CREDENTIALS_JSON env
 *    (raw or base64). Missing credential → isConfigured()=false → no-op.
 *  - Zero composer dependencies: OAuth2 token minted with a hand-rolled
 *    RS256 JWT (openssl_sign) and cached ~50 min.
 *  - UNREGISTERED/invalid token responses null out pos_riders.fcm_token so
 *    dead tokens are not retried forever.
 */
class RiderPushService
{
    private const TOKEN_CACHE_KEY = 'fcm_oauth_token_v1';
    private const MAX_BILLS_IN_PUSH = 40; // ~60 bytes/bill — stays well under FCM 4KB data cap
    private const SYNC_PUSH_CACHE_KEY = 'rider_sync_push_v1:';
    private const SYNC_PUSH_THROTTLE_MIN = 5; // one wake-up nudge per rider per 5 min

    // ─── Public API ─────────────────────────────────────────────────────────

    /**
     * Schedule a new-deliveries push for the rider AFTER the response is sent.
     * Safe to call unconditionally from assign/pay paths — every precondition
     * (rider id, credential, schema, fcm_token) is re-checked inside, and the
     * terminating callback traps all throwables.
     */
    public static function queuePush(?int $riderId): void
    {
        if (!$riderId || !self::isConfigured()) {
            return;
        }
        try {
            if (!Schema::hasColumn('pos_riders', 'fcm_token')) {
                return; // PROD schema drift — migration not run yet
            }
        } catch (\Throwable $e) {
            return;
        }
        app()->terminating(function () use ($riderId) {
            try {
                self::sendNewDeliveries($riderId);
            } catch (\Throwable $e) {
                Log::warning('RiderPushService: push failed (assign completed normally)', [
                    'rider_id' => $riderId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Task #1359 — silent "sync now" nudge for a rider who has gone quiet on
     * the live map.
     *
     * Why a push and not a cron: the phones that break tracking (Infinix,
     * Tecno, Vivo, Oppo, Xiaomi battery savers) freeze the app in the
     * background, and a HIGH-priority FCM data message is one of the very few
     * events that still wakes it — and one of the few states in which Android
     * lets a backgrounded app restart a foreground service. So the moment the
     * map notices silence we knock on the phone directly.
     *
     * Throttled per rider (SYNC_PUSH_THROTTLE_MIN) because the admin map polls
     * every few seconds: without it, one open map tab would fire a push per
     * poll. Throttle sits BEFORE the terminating() defer so repeated polls do
     * not even queue callbacks.
     */
    public static function queueSyncPush(?int $riderId): void
    {
        if (!$riderId || !self::isConfigured()) {
            return;
        }
        try {
            if (!Schema::hasColumn('pos_riders', 'fcm_token')) {
                return; // PROD schema drift — migration not run yet
            }
            // Cache::add is atomic: first caller in the window wins.
            if (!Cache::add(self::SYNC_PUSH_CACHE_KEY . $riderId, 1,
                now()->addMinutes(self::SYNC_PUSH_THROTTLE_MIN))) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }
        app()->terminating(function () use ($riderId) {
            try {
                self::sendSyncNow($riderId);
            } catch (\Throwable $e) {
                Log::warning('RiderPushService: sync push failed', [
                    'rider_id' => $riderId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /** True when a Firebase service-account credential is available. */
    public static function isConfigured(): bool
    {
        return self::credentials() !== null;
    }

    // ─── Send ───────────────────────────────────────────────────────────────

    /** Blocking send — only ever runs inside the terminating callback. */
    private static function sendNewDeliveries(int $riderId): void
    {
        $rider = PosRider::find($riderId);
        if (!$rider || !$rider->fcm_token) {
            return; // old APK / push never registered — poll fallback covers
        }

        // Same query + shape as appMe's deliveries array (dedupe parity: the
        // app REPLACES its seen-set with this list, exactly like the poll).
        $hasAssignedAt = Schema::hasColumn('pos_transactions', 'rider_assigned_at');
        $bills = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $rider->company_id)
            ->where('rider_id', $rider->id)
            ->whereIn('delivery_status', ['assigned', 'dispatched'])
            ->orderBy('id')
            ->limit(self::MAX_BILLS_IN_PUSH)
            ->get(['id', 'invoice_number', 'total_amount']);
        if ($bills->isEmpty()) {
            return; // assign was undone before the response flushed — nothing to alert
        }

        $deliveries = $bills->map(fn ($b) => [
            'id' => (int) $b->id, // live PDO returns ints as strings — cast
            'invoice_number' => (string) $b->invoice_number,
            'amount' => (float) $b->total_amount,
        ])->values()->all();

        $creds = self::credentials();
        $accessToken = self::oauthToken($creds);
        if (!$accessToken) {
            return;
        }

        $resp = Http::timeout(8)->connectTimeout(5)
            ->withToken($accessToken)
            ->post('https://fcm.googleapis.com/v1/projects/' . $creds['project_id'] . '/messages:send', [
                'message' => [
                    'token' => $rider->fcm_token,
                    // Data-only + HIGH priority: onMessageReceived fires even
                    // with the app backgrounded, so DeliveryNotifier's dedupe
                    // always runs (a `notification` block would bypass it).
                    'android' => ['priority' => 'HIGH'],
                    'data' => [
                        'type' => 'new_deliveries',
                        'deliveries' => json_encode($deliveries, JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ]);

        if ($resp->successful()) {
            Log::info('RiderPushService: push sent', [
                'rider_id'       => $riderId,
                'delivery_count' => count($deliveries),
            ]);
            return;
        }

        // Dead registration token (uninstall / data-clear) — stop retrying it.
        $body = (string) $resp->body();
        if ($resp->status() === 404 || str_contains($body, 'UNREGISTERED')
            || str_contains($body, 'INVALID_ARGUMENT')) {
            $rider->update(['fcm_token' => null]);
            return;
        }
        Log::warning('RiderPushService: FCM send non-2xx', [
            'rider_id' => $riderId,
            'status' => $resp->status(),
            'body' => mb_substr($body, 0, 500),
        ]);
    }

    /**
     * Blocking send of the data-only "sync now" nudge (Task #1359).
     * Payload carries no business data — the app reacts by draining its GPS
     * buffer and restarting the duty service, nothing user-visible.
     */
    private static function sendSyncNow(int $riderId): void
    {
        $rider = PosRider::find($riderId);
        if (!$rider || !$rider->fcm_token || !$rider->on_duty) {
            return; // old APK / push never registered / duty ended meanwhile
        }

        $creds = self::credentials();
        $accessToken = self::oauthToken($creds);
        if (!$accessToken) {
            return;
        }

        $resp = Http::timeout(8)->connectTimeout(5)
            ->withToken($accessToken)
            ->post('https://fcm.googleapis.com/v1/projects/' . $creds['project_id'] . '/messages:send', [
                'message' => [
                    'token' => $rider->fcm_token,
                    // HIGH priority + short TTL: this is only useful while the
                    // rider is still silent. If the phone is offline right now,
                    // FCM delivers it the moment data returns — exactly when we
                    // want the buffer flushed.
                    'android' => ['priority' => 'HIGH', 'ttl' => '600s'],
                    'data' => ['type' => 'sync_now'],
                ],
            ]);

        if ($resp->successful()) {
            Log::info('RiderPushService: sync push sent', ['rider_id' => $riderId]);
            return;
        }

        $body = (string) $resp->body();
        if ($resp->status() === 404 || str_contains($body, 'UNREGISTERED')
            || str_contains($body, 'INVALID_ARGUMENT')) {
            $rider->update(['fcm_token' => null]);
            return;
        }
        Log::warning('RiderPushService: FCM sync send non-2xx', [
            'rider_id' => $riderId,
            'status' => $resp->status(),
            'body' => mb_substr($body, 0, 500),
        ]);
    }

    // ─── Credential + OAuth2 (no composer deps) ─────────────────────────────

    /** Parsed service-account array or null. Memoized per request. */
    private static ?array $credsMemo = null;
    private static bool $credsLoaded = false;

    private static function credentials(): ?array
    {
        if (self::$credsLoaded) {
            return self::$credsMemo;
        }
        self::$credsLoaded = true;

        $raw = null;
        $file = (string) config('services.fcm.credentials_file', '');
        if ($file !== '' && is_file($file) && is_readable($file)) {
            $raw = (string) file_get_contents($file);
        }
        if ($raw === null || trim($raw) === '') {
            $env = trim((string) config('services.fcm.credentials_json', ''));
            if ($env !== '') {
                // Accept raw JSON or base64 (multiline JSON in .env is fragile).
                $raw = str_starts_with($env, '{') ? $env : (base64_decode($env, true) ?: '');
            }
        }
        if ($raw === null || trim($raw) === '') {
            return self::$credsMemo = null;
        }

        $creds = json_decode($raw, true);
        if (!is_array($creds) || empty($creds['project_id'])
            || empty($creds['client_email']) || empty($creds['private_key'])) {
            return self::$credsMemo = null;
        }
        return self::$credsMemo = $creds;
    }

    /** OAuth2 access token via service-account JWT grant; cached ~50 min. */
    private static function oauthToken(array $creds): ?string
    {
        $cacheKey = self::TOKEN_CACHE_KEY . ':' . substr(sha1($creds['client_email']), 0, 12);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = Carbon::now('UTC')->timestamp;
        $header = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64url(json_encode([
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signature = '';
        if (!openssl_sign($header . '.' . $claims, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
            Log::warning('RiderPushService: JWT signing failed (bad private_key in credential?)');
            return null;
        }
        $jwt = $header . '.' . $claims . '.' . self::b64url($signature);

        $resp = Http::asForm()->timeout(8)->connectTimeout(5)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        if (!$resp->successful()) {
            Log::warning('RiderPushService: OAuth token exchange failed', [
                'status' => $resp->status(),
                'body' => mb_substr((string) $resp->body(), 0, 300),
            ]);
            return null;
        }
        $token = (string) ($resp->json('access_token') ?? '');
        if ($token === '') {
            return null;
        }
        Cache::put($cacheKey, $token, now()->addMinutes(50));
        return $token;
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
