<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Persistent restore boundary, independent of the configured session backend. */
final class RestoreSessionEpoch
{
    public const KEY = 'restore_session_epoch';

    public static function current(): string
    {
        if (! Schema::hasTable('restore_security_quarantines')) {
            return 'legacy';
        }

        return (string) (DB::table('restore_security_quarantines')->where('status', 'completed')
            ->orderByDesc('id')->value('restore_reference_hash') ?: 'legacy');
    }

    public static function bindLogin(Request $request, string $guard): void
    {
        if ($request->hasSession() && config('auth.guards.'.$guard.'.driver') === 'session'
            && ! auth($guard)->viaRemember()) {
            $request->session()->put(self::KEY, self::current());
        }
    }
}
