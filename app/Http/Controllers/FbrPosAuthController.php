<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Company;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use App\Services\CredentialLedgerService;

class FbrPosAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::guard('fbrpos')->check()) {
            return redirect('/fbr-pos/create');
        }
        return view('fbr-pos.auth.login');
    }

    /**
     * PHASE 3 — Login Isolation (FBR POS panel)
     * 1) Admin first (universal) → /admin/dashboard
     * 2) FBR POS guard ONLY for users whose company.product_type === 'fbrpos'
     * 3) Generic "Invalid credentials" otherwise — no info leak, no cross-redirect
     */
    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $throttleKey = Str::transliterate(Str::lower($request->input('login')) . '|fbrpos|' . $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return back()->withErrors([
                'login' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ])->withInput($request->only('login'));
        }

        $login = trim($request->login);
        $password = $request->password;
        $remember = $request->boolean('remember');

        // ═══ STEP 1 — Admin attempt (universal) ═══
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $admin = AdminUser::where('email', $login)->first();
            if ($admin && Hash::check($password, $admin->password)) {
                RateLimiter::clear($throttleKey);
                Auth::guard('admin')->login($admin, $remember);
                $request->session()->regenerate();
                return redirect('/admin/dashboard');
            }
        }

        // ═══ STEP 2 — FBR POS user lookup (strict isolation) ═══
        $user = null;
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $login)->first();
        } elseif (preg_match('/^\d{10,13}$/', preg_replace('/\D/', '', $login))) {
            $phone = preg_replace('/\D/', '', $login);
            $user = User::where('phone', $phone)->first();
            if (!$user) {
                // Frost & Brew (Aug 2026): NTN/CNIC typed WITH dashes must still
                // match — DB stores plain digits, so compare BOTH the raw input
                // and the digit-only form.
                $company = Company::where('ntn', $login)->orWhere('cnic', $login)
                    ->orWhere('ntn', $phone)->orWhere('cnic', $phone)->first();
                if ($company) {
                    $user = User::where('company_id', $company->id)->where('role', 'company_admin')->orderBy('id')->first();
                }
            }
        } else {
            // Username login (owner report 10 Aug 2026): username column first,
            // then email local-part fallback ("cashier1" → cashier1@gmail.com),
            // scoped to FBR-POS-panel companies. Ambiguity = clear error, never
            // a guess into the wrong account.
            $resolved = \App\Services\LoginIdentifierResolver::resolveUsername($login, ['fbrpos']);
            if ($resolved['ambiguous']) {
                RateLimiter::hit($throttleKey);
                return back()->withErrors([
                    'login' => __('pos.auth_username_ambiguous'),
                ])->withInput($request->only('login'));
            }
            $user = $resolved['user'];
        }

        if ($user && Hash::check($password, $user->password)) {
            $company = $user->company_id ? Company::find($user->company_id) : null;

            if ($company && $company->product_type === 'fbrpos' && $company->fbr_pos_enabled) {
                RateLimiter::clear($throttleKey);
                Auth::guard('fbrpos')->login($user, $remember);
                $request->session()->regenerate();
                $request->session()->forget('url.intended');
                return redirect('/fbr-pos/create');
            }
            // Wrong panel / FBR not enabled → fall through to generic failure (no info leak)
        }

        // ═══ STEP 3 — Generic failure ═══
        RateLimiter::hit($throttleKey);

        return back()->withErrors([
            'login' => 'Invalid credentials.',
        ])->withInput($request->only('login'));
    }

    public function showRegister()
    {
        if (Auth::guard('fbrpos')->check()) {
            return redirect('/fbr-pos/create');
        }
        return view('fbr-pos.auth.register');
    }

    public function register(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:255',
            'company_ntn' => 'required|string|max:50|unique:companies,ntn',
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'pos_type' => 'required|in:restaurant,retail,general,pharmacy,grocery,clothing,electronics,hardware,salon,autoparts,bakery',
        ]);

        // Anti free-trial-abuse: block re-use of any previously-registered credential.
        if ($usedType = CredentialLedgerService::firstUsed([
            'email' => $request->email,
            'phone' => $request->phone,
            'ntn' => $request->company_ntn,
        ])) {
            [$field, $message] = CredentialLedgerService::rejectionFor($usedType);
            throw ValidationException::withMessages([$field => $message]);
        }

        $posType = $request->pos_type ?? 'general';

        $company = Company::create([
            'name' => $request->company_name,
            'ntn' => $request->company_ntn,
            'email' => $request->email,
            'phone' => $request->phone,
            'company_status' => 'pending',
            // Keep BOTH status columns coherent: the admin panel's Approve button
            // and the CheckCompanyApproval view-only gate key off `status`.
            'status' => 'pending',
            'product_type' => 'fbrpos',
            'pos_type' => $posType,
            'restaurant_mode' => ($posType === 'restaurant'),
            'fbr_pos_enabled' => true,
            'fbr_pos_environment' => 'sandbox',
            'fbr_reporting_enabled' => true,
        ]);

        $userData = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'is_active' => true,
        ];
        // Guest language picker on the register page: keep the language the
        // user chose before signing up. hasColumn = PROD schema-drift guard.
        $guestLang = $request->session()->get(\App\Support\PosLocale::SESSION_KEY);
        if (\App\Support\PosLocale::isValid($guestLang)
            && \Illuminate\Support\Facades\Schema::hasColumn('users', 'language')) {
            $userData['language'] = $guestLang;
        }
        $user = User::create($userData);

        CredentialLedgerService::record([
            'email' => $request->email,
            'phone' => $request->phone,
            'ntn' => $request->company_ntn,
        ], $company->id, 'fbrpos');

        $this->startTrial($company->id, 'fbrpos');

        Auth::guard('fbrpos')->login($user);

        return redirect('/fbr-pos/create');
    }

    /**
     * Give a freshly-registered company a 3-day / 10-invoice free trial by
     * attaching the product's trial pricing plan. Mirrors the DI flow.
     */
    private function startTrial(int $companyId, string $productType): void
    {
        // Always attaches a subscription row, even if the trial plan seed is
        // missing (plan-less row still carries trial_ends_at).
        \App\Services\TrialSubscriptionService::ensureTrial($companyId, $productType);
    }

    public function logout(Request $request)
    {
        Auth::guard('fbrpos')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/fbr-pos-landing');
    }
}
