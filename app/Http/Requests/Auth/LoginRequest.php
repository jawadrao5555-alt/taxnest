<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\User;

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

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = trim($this->input('login'));
        $password = $this->input('password');
        $remember = $this->boolean('remember');

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
            $user = User::where('username', $login)->first();
        }

        if (!$user) {
            $normalizedId = preg_replace('/[^0-9\-]/', '', $login);
            if (strlen($normalizedId) >= 7) {
                $company = \App\Models\Company::where(function ($q) use ($login, $normalizedId) {
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

        if (!$user || !Auth::attempt(['email' => $user->email, 'password' => $password], $remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
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
