<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PushSubscriptionController extends Controller
{
    /**
     * Store / update a push subscription for the current user.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint'   => 'required|string|max:2048',
            'keys.p256dh' => 'nullable|string|max:255',
            'keys.auth'   => 'nullable|string|max:255',
            'scope'      => 'nullable|string|in:di,pos,fbrpos',
        ]);

        $user = Auth::user() ?? Auth::guard('pos')->user() ?? Auth::guard('fbrpos')->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'unauthenticated'], 401);
        }

        $sub = PushSubscription::updateOrCreate(
            ['user_id' => $user->id, 'endpoint' => $data['endpoint']],
            [
                'company_id'    => $user->company_id ?? null,
                'scope'         => $data['scope'] ?? 'di',
                'p256dh'        => $data['keys']['p256dh'] ?? null,
                'auth_key'      => $data['keys']['auth']   ?? null,
                'user_agent'    => substr((string) $request->userAgent(), 0, 500),
                'last_used_at'  => now(),
            ]
        );

        return response()->json(['ok' => true, 'id' => $sub->id]);
    }

    /**
     * Remove a push subscription (on permission revoke / logout).
     */
    public function destroy(Request $request): JsonResponse
    {
        $endpoint = $request->input('endpoint');
        if (!$endpoint) {
            return response()->json(['ok' => false], 422);
        }
        $user = Auth::user() ?? Auth::guard('pos')->user() ?? Auth::guard('fbrpos')->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'unauthenticated'], 401);
        }
        PushSubscription::where('endpoint', $endpoint)
            ->where('user_id', $user->id)
            ->delete();
        return response()->json(['ok' => true]);
    }
}
