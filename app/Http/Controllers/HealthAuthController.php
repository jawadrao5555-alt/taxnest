<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\User;
use App\Services\CredentialLedgerService;
use App\Services\HealthAccessService;
use App\Services\HealthModuleService;
use App\Services\HealthPlatformService;
use App\Services\LoginIdentifierResolver;
use App\Services\RequestedPackageService;
use App\Support\HealthPanel;
use App\Support\PosLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * Healthcare ERP authentication.
 *
 * Deliberately the same three-step shape as the POS panels — admin first, then
 * a STRICTLY product-isolated user lookup, then one generic failure message —
 * so a clinic account can never sign into a POS panel and a shop can never sign
 * in here. The refusal text is identical in every case on purpose: telling a
 * stranger "that account exists but belongs to another product" leaks who our
 * customers are.
 */
class HealthAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::guard(HealthPanel::GUARD)->check()) {
            return $this->redirectToPortal();
        }

        return view('health.auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $throttleKey = Str::transliterate(Str::lower($request->input('login')) . '|health|' . $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'login' => __('health.auth_throttled', ['seconds' => $seconds]),
            ])->withInput($request->only('login'));
        }

        $login = trim($request->login);
        $password = $request->password;
        $remember = $request->boolean('remember');

        // ═══ STEP 1 — Admin attempt (universal, same as every other panel) ═══
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $admin = AdminUser::where('email', $login)->first();
            if ($admin && Hash::check($password, $admin->password)) {
                RateLimiter::clear($throttleKey);
                Auth::guard('admin')->login($admin, $remember);
                $request->session()->regenerate();

                return redirect('/admin/dashboard');
            }
        }

        // ═══ STEP 2 — Healthcare user lookup (strict product isolation) ═══
        $user = $this->findUser($login, $throttleKey, $ambiguous);
        if ($ambiguous) {
            return back()->withErrors([
                'login' => __('pos.auth_username_ambiguous'),
            ])->withInput($request->only('login'));
        }

        if ($user && Hash::check($password, $user->password)) {
            $company = $user->company_id ? Company::find($user->company_id) : null;

            if (HealthPlatformService::isHealthcareCompany($company)
                && HealthAccessService::roleFor($user) !== null
                && $user->is_active) {
                RateLimiter::clear($throttleKey);
                Auth::guard(HealthPanel::GUARD)->login($user, $remember);
                $request->session()->regenerate();
                $request->session()->forget('url.intended');

                return $this->redirectToPortal($user);
            }
            // Wrong panel / no healthcare role → fall through, no info leak.
        }

        // ═══ STEP 3 — Generic failure ═══
        RateLimiter::hit($throttleKey);

        return back()->withErrors([
            'login' => __('health.auth_invalid_credentials'),
        ])->withInput($request->only('login'));
    }

    /**
     * Email → NTN/CNIC/phone → username, exactly like the POS panels, with the
     * username resolver scoped to healthcare companies so "reception1" can
     * never resolve into a restaurant's account.
     */
    private function findUser(string $login, string $throttleKey, ?bool &$ambiguous): ?User
    {
        $ambiguous = false;

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return User::where('email', $login)->first();
        }

        $digits = preg_replace('/\D/', '', $login);
        if (preg_match('/^\d{7,13}$/', $digits)) {
            $user = null;
            if (strlen($digits) >= 10) {
                $user = User::where('phone', $digits)->first();
            }
            if (!$user) {
                $matches = Company::where(function ($q) use ($login, $digits) {
                    $q->where('ntn', $login)->orWhere('cnic', $login)
                        ->orWhere('ntn', $digits)->orWhere('cnic', $digits)
                        ->orWhereRaw("REPLACE(REPLACE(cnic, '-', ''), ' ', '') = ?", [$digits]);
                })->get();
                $company = $matches->firstWhere('product_type', HealthPanel::PRODUCT_TYPE) ?? $matches->first();
                if ($company) {
                    $user = User::where('company_id', $company->id)
                        ->where('role', 'company_admin')
                        ->first();
                }
            }

            return $user;
        }

        $resolved = LoginIdentifierResolver::resolveUsername($login, [HealthPanel::PRODUCT_TYPE]);
        if ($resolved['ambiguous']) {
            RateLimiter::hit($throttleKey);
            $ambiguous = true;

            return null;
        }

        return $resolved['user'];
    }

    public function showRegister()
    {
        $this->assertRegistrationOpen();

        if (Auth::guard(HealthPanel::GUARD)->check()) {
            return $this->redirectToPortal();
        }

        $picked = RequestedPackageService::resolvePlan(request('plan'), HealthPanel::PRODUCT_TYPE);

        return view('health.auth.register', [
            'pickedPlanName' => $picked?->name,
            'orgTypes' => HealthPanel::ORG_TYPES,
        ]);
    }

    /**
     * Pre-pilot front door.
     *
     * 404, not 403: a stranger should not learn that a healthcare signup exists
     * here at all. Enforced on BOTH register paths — hiding the buttons only
     * hides the buttons, and the POST is the one that actually creates a
     * company.
     */
    protected function assertRegistrationOpen(): void
    {
        abort_unless(HealthPanel::registrationOpen(), 404);
    }

    public function register(Request $request)
    {
        $this->assertRegistrationOpen();

        $request->validate([
            'company_name' => 'required|string|max:255',
            'company_ntn' => 'required|string|max:50|unique:companies,ntn',
            'company_cnic' => LoginIdentifierResolver::cnicRules(),
            'org_type' => 'required|in:' . implode(',', HealthPanel::ORG_TYPES),
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], LoginIdentifierResolver::cnicMessages('company_cnic'));

        // Anti free-trial-abuse: the same platform ledger every product uses.
        if ($usedType = CredentialLedgerService::firstUsed([
            'email' => $request->email,
            'phone' => $request->phone,
            'ntn' => $request->company_ntn,
        ])) {
            [$field, $message] = CredentialLedgerService::rejectionFor($usedType);
            throw ValidationException::withMessages([$field => $message]);
        }

        $orgType = HealthPanel::normalizeOrgType($request->input('org_type'));

        $requestedPackage = RequestedPackageService::companyAttributes(
            RequestedPackageService::resolvePlan($request->input('requested_plan'), HealthPanel::PRODUCT_TYPE)
        );

        // hasColumn guards: a deploy-before-migrate window must never 500 a signup.
        $healthDefaults = [];
        if (Schema::hasColumn('companies', 'health_org_type')) {
            $healthDefaults['health_org_type'] = $orgType;
        }
        if (Schema::hasColumn('companies', 'health_modules')) {
            // Plain array: the model casts this column, so a pre-encoded
            // string would be stored as JSON inside JSON.
            $healthDefaults['health_modules'] = HealthModuleService::defaultsForOrgType($orgType);
        }

        $company = Company::create([
            'name' => $request->company_name,
            'ntn' => $request->company_ntn,
            'cnic' => LoginIdentifierResolver::normalizeCnic($request->company_cnic),
            'email' => $request->email,
            'phone' => $request->phone,
            // BOTH status columns, always coherent — different admin panels read
            // different ones and a half-set company is invisible to one of them.
            'company_status' => 'pending',
            'status' => 'pending',
            'product_type' => HealthPanel::PRODUCT_TYPE,
        ] + $healthDefaults + $requestedPackage);

        $userData = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'is_active' => true,
        ];
        if (Schema::hasColumn('users', 'health_role')) {
            $userData['health_role'] = HealthAccessService::ROLE_OWNER;
        }
        $guestLang = $request->session()->get(PosLocale::SESSION_KEY);
        if (PosLocale::isValid($guestLang) && Schema::hasColumn('users', 'language')) {
            $userData['language'] = $guestLang;
        }

        $user = User::create($userData);

        CredentialLedgerService::record([
            'email' => $request->email,
            'phone' => $request->phone,
            'ntn' => $request->company_ntn,
        ], $company->id, HealthPanel::PRODUCT_TYPE);

        HealthPlatformService::ensureTrial($company->id);

        Auth::guard(HealthPanel::GUARD)->login($user);

        return $this->redirectToPortal($user);
    }

    public function logout(Request $request)
    {
        Auth::guard(HealthPanel::GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(HealthPanel::LANDING_PATH);
    }

    /**
     * PATH-RELATIVE on purpose: the live app forces HTTPS URLs, but the
     * development preview reaches Laravel over a local HTTP bridge, so an
     * absolute forced-HTTPS Location would block a valid sign-in.
     */
    private function redirectToPortal(?User $user = null): \Illuminate\Http\RedirectResponse
    {
        $user = $user ?: Auth::guard(HealthPanel::GUARD)->user();

        return redirect()->away(HealthAccessService::homePathFor($user));
    }
}
