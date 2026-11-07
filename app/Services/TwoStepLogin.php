<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TwoStepLogin
{
    public const PANELS = ['di' => 'web', 'pos' => 'pos', 'fbrpos' => 'fbrpos', 'health' => 'health', 'admin' => 'admin'];

    public static function secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $out .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($out, 5) as $bits) {
            $encoded .= $alphabet[bindec(str_pad($bits, 5, '0'))];
        }

        return $encoded;
    }

    /** RFC4226 HOTP / RFC6238 TOTP, SHA1, 30-second steps. */
    public static function code(string $secret, int $step, int $digits = 6): string
    {
        if ($step < 0 || ! in_array($digits, [6, 8], true) || ! preg_match('/^[A-Z2-7]{16,128}$/D', $secret)) {
            throw new \InvalidArgumentException('Invalid authenticator parameters.');
        }
        $bits = '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        foreach (str_split($secret) as $c) {
            $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $key .= chr(bindec($chunk));
            }
        }
        $mac = hash_hmac('sha1', pack('N2', intdiv($step, 4294967296), $step % 4294967296), $key, true);
        $offset = ord($mac[19]) & 15;
        $number = unpack('N', substr($mac, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function matchingStep(string $secret, string $code, ?int $after = null): ?int
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            return null;
        }
        $current = intdiv(now()->timestamp, 30);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if ($step >= 0 && ($after === null || $step > $after) && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function identity(object $actor): array
    {
        return ['actor_type' => $actor instanceof \App\Models\AdminUser ? 'admin' : 'user', 'actor_id' => (int) $actor->id];
    }

    public static function credential(object $actor): ?object
    {
        if (! Schema::hasTable('two_step_credentials')) {
            return null;
        }

        return DB::table('two_step_credentials')->where(self::identity($actor))->first();
    }

    private static function seal(object $actor, object $credential): string
    {
        return hash('sha256', json_encode(self::identity($actor)).'|'.$actor->getAuthPassword().'|'.$credential->secret);
    }

    public static function verified(Request $request, string $guard, object $actor, object $credential): bool
    {
        $value = $request->session()->get('two_step_verified.'.$guard);

        return is_string($value) && hash_equals(self::seal($actor, $credential), $value);
    }

    public static function markVerified(Request $request, string $guard, object $actor): void
    {
        $credential = self::credential($actor);
        if (! $credential) {
            throw new \LogicException('Two-step credential is unavailable.');
        }
        $request->session()->regenerate();
        $request->session()->put('two_step_verified.'.$guard, self::seal($actor, $credential));
    }

    /** Return a challenge response before any protected handler executes. */
    public static function challenge(Request $request, string $guard)
    {
        $panel = array_search($guard, self::PANELS, true);
        if ($panel === false || ! $request->hasSession()) {
            return null;
        }
        $actor = auth($guard)->user();
        if (! $actor) {
            return null;
        }
        if (! Schema::hasTable('two_step_credentials')) {
            return null; // Legacy schemas have no enrolled account to enforce.
        }
        // An audited admin grant authorizes company impersonation. Its own
        // configured second factor must be verified independently first.
        $imp = $request->session()->get('impersonation');
        if ($guard !== 'admin' && is_array($imp) && ($imp['guard'] ?? null) === $guard
            && (int) ($imp['company_id'] ?? 0) === (int) $actor->company_id) {
            $admin = auth('admin')->user()?->fresh();
            if ($admin && $admin->role === 'super_admin' && (int) $admin->id === (int) ($imp['admin_id'] ?? 0)) {
                return self::challenge($request, 'admin');
            }
        }
        $actor = self::consultantPrincipal($request, $guard, $actor);
        $credential = self::credential($actor);
        if (! $credential) {
            return null; // Default-off accounts retain their existing login path.
        }
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        if (self::verified($request, $guard, $actor, $credential)) {
            return null;
        }
        $url = route('two-step.challenge', ['panel' => $panel]);
        if ($request->expectsJson()) {
            return response()->json(['error' => 'two_step_required', 'challenge_url' => $url], 403);
        }

        return redirect($url);
    }

    /** A consented switch authenticates the consultant, never borrows the client's factor. */
    public static function consultantPrincipal(Request $request, string $guard, object $actor): object
    {
        $flag = $request->session()->get(\App\Services\ConsultantService::SESSION_KEY);
        if ($guard !== 'web' || ! is_array($flag) || (int) ($flag['client_user_id'] ?? 0) !== (int) $actor->id) {
            return $actor;
        }
        $id = (int) ($flag['consultant_user_id'] ?? 0);
        $consultant = \App\Models\User::find($id);
        abort_unless($consultant && $consultant->is_active
            && \App\Services\ConsultantService::activeProfileFor($id)
            && \App\Services\ConsultantService::activeLink($id, (int) $actor->company_id), 403);

        return $consultant;
    }

    /** Locking makes a TOTP step or recovery code usable at most once. */
    public static function consume(object $actor, string $code): bool
    {
        return DB::transaction(function () use ($actor, $code) {
            $row = DB::table('two_step_credentials')->where(self::identity($actor))->lockForUpdate()->first();
            if (! $row || ($row->blocked_until && now()->lt($row->blocked_until))) {
                return false;
            }
            $hashes = json_decode($row->recovery_hashes, true);
            if (! is_array($hashes)) {
                throw new \RuntimeException('Invalid two-step recovery storage.');
            }
            $step = self::matchingStep(Crypt::decryptString($row->secret), $code, $row->last_step === null ? null : (int) $row->last_step);
            $recovery = null;
            foreach ($hashes as $i => $hash) {
                if (is_string($hash) && hash_equals($hash, hash('sha256', $code))) {
                    $recovery = $i;
                }
            }
            $valid = $step !== null || $recovery !== null;
            if (! $valid) {
                $failures = $row->blocked_until ? 1 : (int) $row->failed_attempts + 1;
                DB::table('two_step_credentials')->where('id', $row->id)->update(['failed_attempts' => $failures,
                    'blocked_until' => $failures >= 5 ? now()->addMinutes(5) : null, 'updated_at' => now()]);

                return false;
            }
            if ($recovery !== null) {
                unset($hashes[$recovery]);
            }
            DB::table('two_step_credentials')->where('id', $row->id)->update(['last_step' => $step ?? $row->last_step,
                'recovery_hashes' => json_encode(array_values($hashes)), 'failed_attempts' => 0, 'blocked_until' => null, 'updated_at' => now()]);
            self::event($actor, $recovery !== null ? 'recovery_code_used' : 'challenge_verified');

            return true;
        });
    }

    public static function event(object $actor, string $event): void
    {
        DB::table('two_step_events')->insert(self::identity($actor) + ['event' => $event, 'created_at' => now()]);
    }
}
