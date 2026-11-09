<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\NestErps;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Password recovery revokes existing company sessions independently per guard. */
class CompanySessionGeneration
{
    public static function key(string $guard): string
    {
        return 'company_session_generation.'.$guard;
    }

    public static function guards(): array
    {
        return array_unique(array_merge(['web', 'pos', 'fbrpos'], array_column(NestErps::VERTICALS, 'guard')));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $revoked = [];
        foreach (self::guards() as $name) {
            $guard = auth($name);
            $user = $guard->user();
            if (!$user instanceof User) continue;
            $current = $user->fresh();
            $generation = $current?->getAttribute('session_generation');
            // Null is the explicit legacy contract: the additive migration
            // does not log out existing customers. Only a completed reset
            // rotates the generation. API/Agent keys and other providers stay
            // outside this browser-session contract.
            if ($current && $generation === null) continue;
            $issued = $request->session()->get(self::key($name));
            if (!$current || !is_array($issued) || (int) ($issued['user_id'] ?? 0) !== (int) $current->id
                || !hash_equals((string) $generation, (string) ($issued['generation'] ?? ''))) {
                // Do not invalidate the whole shared session or sweep sessions
                // by numeric user_id: independent products/admin can coexist.
                $guard->logoutCurrentDevice();
                $request->session()->forget(self::key($name));
                $revoked[] = $name;
            }
        }
        // Laravel can prioritize route authentication before appended web
        // middleware. Stop a revoked request here rather than allowing a
        // controller to run after its earlier authentication already passed.
        $path = ltrim($request->path(), '/');
        $target = str_starts_with($path, 'pos/') ? 'pos' : (str_starts_with($path, 'fbr-pos/') ? 'fbrpos' : 'web');
        $login = ['web' => '/login', 'pos' => '/pos/login', 'fbrpos' => '/fbr-pos/login'];
        foreach (NestErps::VERTICALS as $vertical) {
            $login[$vertical['guard']] = $vertical['login_path'];
            if (str_starts_with($path, $vertical['path_prefix'].'/')) $target = $vertical['guard'];
        }
        if (in_array($target, $revoked, true) && $path !== 'admin' && !str_starts_with($path, 'admin/')) {
            if ($request->expectsJson()) return response()->json(['error' => 'Please sign in again.'], 401);
            return redirect($login[$target]);
        }
        return $next($request);
    }
}
