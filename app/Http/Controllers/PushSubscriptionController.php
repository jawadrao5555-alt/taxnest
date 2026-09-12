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
            'endpoint'   => 'required|string|max:500',
            'keys.p256dh' => 'nullable|string|max:255',
            'keys.auth'   => 'nullable|string|max:255',
            'scope'      => 'nullable|string|in:di,web,pos,fbrpos',
        ]);

        $requested = $data['scope'] ?? null;
        [$user, $guard] = $this->actorForScope($requested);
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'unauthenticated'], 401);
        }

        $allowed = $this->allowedScopes($guard);
        $scope = $requested ?: $this->defaultScope($guard);
        if (!in_array($scope, $allowed, true)) {
            return response()->json(['ok' => false, 'message' => 'forbidden'], 403);
        }

        $sub = PushSubscription::updateOrCreate(
            ['user_id' => $user->id, 'endpoint' => $data['endpoint']],
            [
                'company_id'    => $user->company_id ?? null,
                'scope'         => $scope,
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
        $user = $this->anyAuthenticatedUser();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'unauthenticated'], 401);
        }
        PushSubscription::where('endpoint', $endpoint)
            ->where('user_id', $user->id)
            ->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * @return array{0: ?\Illuminate\Contracts\Auth\Authenticatable, 1: ?string}
     */
    private function actorForScope(?string $scope): array
    {
        $wanted = match ($scope) {
            'pos' => 'pos',
            'fbrpos' => 'fbrpos',
            'di', 'web' => 'web',
            default => null,
        };
        $any = $this->resolvedActor();
        if ($wanted) {
            $user = Auth::guard($wanted)->user();
            if ($user) {
                return [$user, $wanted];
            }
            // Logged in on another product: 403 later, not a fake anonymous 401.
            return $any;
        }

        return $any;
    }

    /** @return array{0: ?\Illuminate\Contracts\Auth\Authenticatable, 1: ?string} */
    private function resolvedActor(): array
    {
        foreach (['web', 'pos', 'fbrpos'] as $guard) {
            $user = Auth::guard($guard)->user();
            if ($user) {
                return [$user, $guard];
            }
        }

        return [null, null];
    }

    private function anyAuthenticatedUser()
    {
        foreach (['web', 'pos', 'fbrpos'] as $guard) {
            $user = Auth::guard($guard)->user();
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function allowedScopes(string $guard): array
    {
        return match ($guard) {
            'pos' => ['pos'],
            'fbrpos' => ['fbrpos'],
            default => ['di', 'web'],
        };
    }

    private function defaultScope(string $guard): string
    {
        return match ($guard) {
            'pos' => 'pos',
            'fbrpos' => 'fbrpos',
            default => 'di',
        };
    }
}
