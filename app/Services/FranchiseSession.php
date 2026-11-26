<?php

namespace App\Services;

use App\Models\Franchise;
use Illuminate\Http\Request;

final class FranchiseSession
{
    private const KEY = 'franchise_session_generation';

    public static function bind(Request $request, Franchise $franchise): void
    {
        $request->session()->put(self::KEY, [
            'id' => (int) $franchise->id,
            'generation' => $franchise->session_generation,
        ]);
    }

    public static function matches(Request $request, Franchise $franchise): bool
    {
        if ($franchise->session_generation === null) {
            return true;
        }

        $issued = $request->session()->get(self::KEY);

        return is_array($issued) && ($issued['id'] ?? null) === (int) $franchise->id
            && is_string($issued['generation'] ?? null)
            && hash_equals($franchise->session_generation, $issued['generation']);
    }

    public static function revokeCurrent(Request $request): void
    {
        auth('franchise')->logoutCurrentDevice();
        $request->session()->forget([self::KEY, 'two_step_verified.franchise', 'two_step_enrollment.franchise']);
        if ($request->session()->get('two_step_recovery_codes.guard') === 'franchise') {
            $request->session()->forget('two_step_recovery_codes');
        }
    }
}
