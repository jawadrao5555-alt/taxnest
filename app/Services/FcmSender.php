<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared Firebase Cloud Messaging HTTP v1 sender (Task #1142).
 *
 * Generalizes the credential + OAuth + send machinery introduced for the
 * rider app (Task #1106, RiderPushService) so ANY device token can be
 * pushed to — the POS shell app (waiter/cashier/owner notifications) uses
 * this; the rider path keeps its own service untouched (byte-for-byte
 * behavior preservation) but shares the SAME service-account credential,
 * config keys and OAuth token cache, so the token is only minted once.
 *
 * Contract (identical semantics to the rider path):
 *  - Data-only messages (no `notification` block), Android priority HIGH.
 *  - Missing credential → isConfigured()=false → callers no-op silently.
 *  - Zero composer dependencies: OAuth2 token minted with a hand-rolled
 *    RS256 JWT (openssl_sign), cached ~50 min under the SAME cache key
 *    RiderPushService uses ('fcm_oauth_token_v1:<hash>').
 *  - send() never throws; it returns a result array the caller uses to
 *    clean up dead tokens ('dead' on 404/UNREGISTERED/INVALID_ARGUMENT).
 */
class FcmSender
{
    // SAME key as RiderPushService — one shared OAuth token for the project.
    private const TOKEN_CACHE_KEY = 'fcm_oauth_token_v1';

    /** True when a Firebase service-account credential is available. */
    public static function isConfigured(): bool
    {
        return self::credentials() !== null;
    }

    /**
     * Blocking data-only HIGH-priority send to one device token.
     *
     * Returns ['result' => 'sent'|'dead'|'error'|'skipped', 'status' => ?int, 'body' => string]
     *  - sent    : FCM accepted the message
     *  - dead    : registration token is gone (uninstall/data-clear/rotation)
     *              → caller should delete/null the stored token
     *  - error   : other non-2xx (caller may log; token stays)
     *  - skipped : no credential / OAuth failed (already logged) — caller
     *              should stop its send loop quietly
     *
     * All data values are coerced to strings (FCM data payload requirement).
     */
    public static function send(string $fcmToken, array $data): array
    {
        $creds = self::credentials();
        if (!$creds) {
            return ['result' => 'skipped', 'status' => null, 'body' => ''];
        }
        $accessToken = self::oauthToken($creds);
        if (!$accessToken) {
            return ['result' => 'skipped', 'status' => null, 'body' => ''];
        }

        $resp = Http::timeout(8)->connectTimeout(5)
            ->withToken($accessToken)
            ->post('https://fcm.googleapis.com/v1/projects/' . $creds['project_id'] . '/messages:send', [
                'message' => [
                    'token' => $fcmToken,
                    // Data-only + HIGH priority: onMessageReceived fires even
                    // with the app backgrounded/closed (a `notification` block
                    // would bypass the app's own handler).
                    'android' => ['priority' => 'HIGH'],
                    'data' => array_map(fn ($v) => (string) $v, $data),
                ],
            ]);

        if ($resp->successful()) {
            return ['result' => 'sent', 'status' => $resp->status(), 'body' => ''];
        }

        $body = (string) $resp->body();
        // Dead registration token (uninstall / data-clear) — stop retrying it.
        if ($resp->status() === 404 || str_contains($body, 'UNREGISTERED')
            || str_contains($body, 'INVALID_ARGUMENT')) {
            return ['result' => 'dead', 'status' => $resp->status(), 'body' => mb_substr($body, 0, 500)];
        }
        return ['result' => 'error', 'status' => $resp->status(), 'body' => mb_substr($body, 0, 500)];
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
            Log::warning('FcmSender: JWT signing failed (bad private_key in credential?)');
            return null;
        }
        $jwt = $header . '.' . $claims . '.' . self::b64url($signature);

        $resp = Http::asForm()->timeout(8)->connectTimeout(5)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        if (!$resp->successful()) {
            Log::warning('FcmSender: OAuth token exchange failed', [
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
