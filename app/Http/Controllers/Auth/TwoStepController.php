<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TwoStepLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class TwoStepController extends Controller
{
    private function actor(string $panel): array
    {
        $guard = TwoStepLogin::PANELS[$panel] ?? null;
        abort_unless($guard, 404);
        $actor = auth($guard)->user()?->fresh();
        abort_unless($actor, 403);
        if ($guard !== 'admin') {
            abort_unless($actor->is_active, 403);
            $company = \App\Models\Company::find($actor->company_id);
            $expected = $panel === 'health' ? 'erps' : $panel;
            abort_unless(($company && \App\Support\NestErps::canonicalProductType($company->product_type) === $expected)
                || ($panel === 'di' && ! $actor->company_id && \App\Services\ConsultantService::activeProfileFor($actor->id)), 403);
        }

        return [$guard, $actor];
    }

    private function key(string $guard): string
    {
        return 'two_step_enrollment.'.$guard;
    }

    private function ownAccount(Request $request): void
    {
        abort_if($request->session()->has('impersonation') || $request->session()->has('consultant_console'), 403);
    }

    public function settings(Request $request, string $panel)
    {
        $this->ownAccount($request);
        [$guard,$actor] = $this->actor($panel);
        if ($response = TwoStepLogin::challenge($request, $guard)) {
            return $response;
        }
        $credential = TwoStepLogin::credential($actor);
        $pending = $request->session()->get($this->key($guard));
        $secret = is_array($pending) && ($pending['actor_id'] ?? null) === $actor->id
            && ($pending['expires_at'] ?? 0) > now()->timestamp
            && hash_equals((string) ($pending['password_seal'] ?? ''), hash('sha256', $actor->getAuthPassword()))
            ? $pending['secret'] : null;
        $recovery = $request->session()->get('two_step_recovery_codes', []);
        $codes = ($recovery['guard'] ?? null) === $guard && ($recovery['actor_id'] ?? null) === $actor->id
            ? ($recovery['codes'] ?? []) : [];
        if ($codes) {
            $request->session()->forget('two_step_recovery_codes');
        }

        return response()->view('auth.two-step', ['panel' => $panel, 'enabled' => (bool) $credential,
            'challenge' => false, 'secret' => $secret, 'recoveryCodes' => $codes])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function begin(Request $request, string $panel)
    {
        $this->ownAccount($request);
        [$guard,$actor] = $this->actor($panel);
        $request->validate(['password' => 'required|string']);
        abort_if(TwoStepLogin::credential($actor), 409);
        if (! Hash::check($request->password, $actor->getAuthPassword())) {
            return back()->withErrors(['password' => 'Password is incorrect.']);
        }
        $request->session()->put($this->key($guard), ['actor_id' => $actor->id, 'secret' => TwoStepLogin::secret(),
            'expires_at' => now()->addMinutes(10)->timestamp, 'attempts' => 0, 'password_seal' => hash('sha256', $actor->getAuthPassword())]);

        return redirect()->route('two-step.settings', ['panel' => $panel]);
    }

    public function confirm(Request $request, string $panel)
    {
        $this->ownAccount($request);
        [$guard,$actor] = $this->actor($panel);
        $request->validate(['code' => 'required|string|max:64']);
        $pending = $request->session()->get($this->key($guard));
        if (! is_array($pending) || ($pending['actor_id'] ?? null) !== $actor->id || ($pending['expires_at'] ?? 0) <= now()->timestamp
            || ! hash_equals((string) ($pending['password_seal'] ?? ''), hash('sha256', $actor->getAuthPassword()))) {
            $request->session()->forget($this->key($guard));

            return redirect()->route('two-step.settings', ['panel' => $panel])->withErrors(['code' => 'Setup expired. Start again with your password.']);
        }
        $step = TwoStepLogin::matchingStep($pending['secret'], $request->code);
        if ($step === null) {
            $pending['attempts']++;
            if ($pending['attempts'] >= 5) {
                $request->session()->forget($this->key($guard));
            } else {
                $request->session()->put($this->key($guard), $pending);
            }

            return back()->withErrors(['code' => 'Authenticator code is invalid.']);
        }
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = bin2hex(random_bytes(16));
        }
        DB::transaction(function () use ($actor, $pending, $step, $codes) {
            $locked = $actor->newQuery()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($pending['password_seal'], hash('sha256', $locked->getAuthPassword())), 409);
            abort_if(TwoStepLogin::credential($actor), 409);
            DB::table('two_step_credentials')->insert(TwoStepLogin::identity($actor) + [
                'secret' => Crypt::encryptString($pending['secret']), 'recovery_hashes' => json_encode(array_map(fn ($c) => hash('sha256', $c), $codes)),
                'last_step' => $step, 'failed_attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
            TwoStepLogin::event($actor, 'enabled');
        });
        $request->session()->forget($this->key($guard));
        TwoStepLogin::markVerified($request, $guard, $actor);

        return redirect()->route('two-step.settings', ['panel' => $panel])->with('two_step_recovery_codes', [
            'guard' => $guard, 'actor_id' => $actor->id, 'codes' => $codes]);
    }

    public function challenge(Request $request, string $panel)
    {
        [$guard,$actor] = $this->actor($panel);
        $actor = TwoStepLogin::consultantPrincipal($request, $guard, $actor);
        if (! TwoStepLogin::credential($actor)) {
            return redirect($this->home($panel));
        }

        return response()->view('auth.two-step', ['panel' => $panel, 'enabled' => true, 'challenge' => true, 'secret' => null, 'recoveryCodes' => []])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function verify(Request $request, string $panel)
    {
        [$guard,$actor] = $this->actor($panel);
        $actor = TwoStepLogin::consultantPrincipal($request, $guard, $actor);
        $request->validate(['code' => 'required|string|max:64']);
        if (! TwoStepLogin::consume($actor, trim($request->code))) {
            return back()->withErrors(['code' => 'Code is invalid, already used or temporarily locked. Use a fresh code or an unused recovery code.']);
        }
        TwoStepLogin::markVerified($request, $guard, $actor);

        return redirect($this->home($panel));
    }

    public function disable(Request $request, string $panel)
    {
        $this->ownAccount($request);
        [$guard,$actor] = $this->actor($panel);
        $request->validate(['password' => 'required|string', 'code' => 'required|string|max:64']);
        if (! Hash::check($request->password, $actor->getAuthPassword())) {
            return back()->withErrors(['password' => 'Password is incorrect.']);
        }
        $removed = DB::transaction(function () use ($actor, $request) {
            $locked = $actor->newQuery()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($request->password, $locked->getAuthPassword())) {
                return false;
            }
            if (! TwoStepLogin::consume($actor, trim($request->code))) {
                return false;
            }
            DB::table('two_step_credentials')->where(TwoStepLogin::identity($actor))->delete();
            TwoStepLogin::event($actor, 'disabled');

            return true;
        });
        if (! $removed) {
            return back()->withErrors(['code' => 'A fresh authenticator code or unused recovery code is required.']);
        }
        $request->session()->forget(['two_step_verified.'.$guard, $this->key($guard)]);

        return redirect()->route('two-step.settings', ['panel' => $panel]);
    }

    public function logout(Request $request, string $panel)
    {
        $this->ownAccount($request);
        [$guard] = $this->actor($panel);
        auth($guard)->logoutCurrentDevice();
        $request->session()->forget(['two_step_verified.'.$guard, $this->key($guard)]);

        return redirect($panel === 'di' ? '/login' : '/'.($panel === 'fbrpos' ? 'fbr-pos' : $panel).'/login');
    }

    private function home(string $panel): string
    {
        return $panel === 'di' ? '/dashboard' : '/'.($panel === 'fbrpos' ? 'fbr-pos' : $panel).'/dashboard';
    }
}
