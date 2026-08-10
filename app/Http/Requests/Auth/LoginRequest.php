<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\AdminUser;
use App\Models\Company;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * PHASE 3 — Login Isolation
     * 1) Admin first (any panel) → /admin/dashboard
     * 2) Web (DI) guard ONLY for users whose company is DI (or no company)
     * 3) Generic "Invalid credentials" — no cross-product redirect, no info leak
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = trim($this->input('login'));
        $password = $this->input('password');
        $remember = $this->boolean('remember');

        // ═══ STEP 1 — Admin attempt (universal across all panels) ═══
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $admin = AdminUser::where('email', $login)->first();
            if ($admin && Auth::guard('admin')->attempt(['email' => $login, 'password' => $password], $remember)) {
                RateLimiter::clear($this->throttleKey());
                session(['admin_login_redirect' => true]);
                return;
            }
        }

        // ═══ STEP 2 — Resolve identifier → User (DI only) ═══
        $resolution = $this->resolveUserByIdentifier($login);
        if ($resolution['ambiguous']) {
            // Two DI accounts share this email local-part — never guess.
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages([
                'login' => __('pos.auth_username_ambiguous'),
            ]);
        }
        $user = $resolution['user'];

        if ($user) {
            $company = $user->company_id ? Company::find($user->company_id) : null;
            $productType = $company ? $company->product_type : null;

            // STRICT isolation: only DI users (or orphan users with no company) may login here.
            // POS / FBR-POS users → fall through to generic failure (no info leak).
            if (($productType === null || $productType === 'di')
                && Auth::attempt(['email' => $user->email, 'password' => $password], $remember)
            ) {
                RateLimiter::clear($this->throttleKey());
                return;
            }
        }

        // ═══ STEP 3 — Generic failure ═══
        RateLimiter::hit($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.failed'),
        ]);
    }

    /**
     * Resolve a login identifier (email / phone / username / CNIC / NTN / FBR reg)
     * into a User. Does NOT verify password — that's done by Auth::attempt afterwards.
     *
     * @return array{user: ?User, ambiguous: bool}
     */
    private function resolveUserByIdentifier(string $login): array
    {
        $normalizedPhone = preg_replace('/[^0-9]/', '', $login);
        $user = null;

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $login)->first();
        }

        if (!$user && strlen($normalizedPhone) >= 10 && strlen($normalizedPhone) <= 15) {
            $user = User::where('phone', $normalizedPhone)->first();
            if (!$user) {
                $user = User::where('phone', $login)->first();
            }
        }

        if (!$user) {
            // Panel-scoped exact-username match: an out-of-panel username
            // (e.g. a POS cashier named "ali") must not block a DI account
            // whose email is ali@… — out-of-scope matches fall through to
            // the NTN/CNIC step and then the scoped local-part fallback.
            $user = \App\Services\LoginIdentifierResolver::resolveUsernameColumn($login, ['di', null]);
        }

        if (!$user) {
            $normalizedId = preg_replace('/[^0-9\-]/', '', $login);
            if (strlen($normalizedId) >= 7) {
                $company = Company::where(function ($q) use ($login, $normalizedId) {
                    $q->where('ntn', $login)
                      ->orWhere('ntn', $normalizedId)
                      ->orWhere('cnic', $login)
                      ->orWhere('cnic', $normalizedId)
                      ->orWhere('fbr_registration_no', $login)
                      ->orWhere('fbr_registration_no', $normalizedId);
                })->first();
                if ($company) {
                    $user = User::where('company_id', $company->id)
                        ->where('role', 'company_admin')
                        ->oldest()
                        ->first();
                }
            }
        }

        if (!$user) {
            // Email local-part fallback, LAST so NTN/CNIC/FBR-reg keep their
            // existing precedence (owner report 10 Aug 2026): "cashier1" must
            // find cashier1@gmail.com when no users.username matches. DI scope
            // = product_type 'di' or company-less accounts (null).
            return \App\Services\LoginIdentifierResolver::resolveEmailLocalPart($login, ['di', null]);
        }

        return ['user' => $user, 'ambiguous' => false];
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')).'|'.$this->ip());
    }
}
