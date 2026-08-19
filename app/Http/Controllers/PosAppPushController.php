<?php

namespace App\Http\Controllers;

use App\Models\PosAppDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * POS shell-app FCM device-token registration (Task #1142).
 *
 * The TaxNest POS Android shell has NO bearer token — it rides the normal
 * WebView session cookie (pos guard). register() therefore lives under
 * pos.auth; /pos/* is already CSRF-exempt platform-wide, so a required
 * custom header (X-TaxNest-App) stands in as the cross-site forgery guard:
 * browsers cannot attach custom headers cross-origin without a CORS
 * preflight, and only the shell sets it.
 *
 * clear() is deliberately STATELESS (no auth): the shell detects logout by
 * landing back on /pos/login — at that point the session cookie is already
 * invalidated, so it authenticates by possession of the token itself
 * (unguessable; knowing it means you control the device). Deleting a token
 * only ever stops notifications.
 */
class PosAppPushController extends Controller
{
    /** Devices kept per user — oldest beyond this are pruned. */
    private const MAX_DEVICES_PER_USER = 5;

    /** POST /pos/app/fcm-token  {token, app_version?} — register/rotate. */
    public function register(Request $request)
    {
        // CSRF stand-in (see class docblock) — the shell always sends this.
        if ($request->header('X-TaxNest-App') !== 'pos') {
            return response()->json(['ok' => false], 403);
        }
        $user = Auth::guard('pos')->user();
        if (!$user) {
            return response()->json(['ok' => false], 401);
        }

        return $this->storeToken($request, $user);
    }

    /**
     * POST /fbr-pos/app/fcm-token — FBR shell twin of register() (Task 1275).
     * Same table/dedupe/prune; only the required header value and the session
     * guard differ (users are strictly per-panel, so no product column is
     * needed on pos_app_devices). NOTE: /fbr-pos/* is NOT in the platform CSRF
     * exempt list, so the route carries withoutMiddleware(ValidateCsrfToken) —
     * the X-TaxNest-App header remains the forgery guard, exactly like PRA.
     */
    public function registerFbr(Request $request)
    {
        if ($request->header('X-TaxNest-App') !== 'fbrpos') {
            return response()->json(['ok' => false], 403);
        }
        $user = Auth::guard('fbrpos')->user();
        if (!$user) {
            return response()->json(['ok' => false], 401);
        }

        return $this->storeToken($request, $user);
    }

    /** Shared register body — guard-agnostic ($user already resolved). */
    private function storeToken(Request $request, $user)
    {
        try {
            if (!Schema::hasTable('pos_app_devices')) {
                return response()->json(['ok' => true]); // pre-migration: accept quietly
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => true]);
        }

        $token = trim((string) $request->input('token', ''));
        if ($token === '' || strlen($token) > 4096) {
            return response()->json(['ok' => false, 'message' => 'Invalid token'], 422);
        }

        // token_hash is the dedupe key: the same device re-registering after
        // a user switch simply moves to the new user/company.
        PosAppDevice::updateOrCreate(
            ['token_hash' => hash('sha256', $token)],
            [
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'fcm_token' => $token,
                'app_version' => substr(trim((string) $request->input('app_version', '')), 0, 30) ?: null,
                'last_seen_at' => now(),
            ]
        );

        // Prune: keep the N most recently seen devices per user (old rotated
        // tokens also die naturally via UNREGISTERED cleanup on send).
        $ids = PosAppDevice::where('user_id', $user->id)
            ->orderByDesc('last_seen_at')->orderByDesc('id')->pluck('id');
        if ($ids->count() > self::MAX_DEVICES_PER_USER) {
            PosAppDevice::whereIn('id', $ids->slice(self::MAX_DEVICES_PER_USER)->values()->all())->delete();
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/pos-app/fcm-token/clear  {token} — stop this device's pushes. */
    public function clear(Request $request)
    {
        try {
            if (!Schema::hasTable('pos_app_devices')) {
                return response()->json(['ok' => true]);
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => true]);
        }
        $token = trim((string) $request->input('token', ''));
        if ($token === '' || strlen($token) > 4096) {
            return response()->json(['ok' => false, 'message' => 'Invalid token'], 422);
        }
        PosAppDevice::where('token_hash', hash('sha256', $token))->delete();
        return response()->json(['ok' => true]);
    }
}
