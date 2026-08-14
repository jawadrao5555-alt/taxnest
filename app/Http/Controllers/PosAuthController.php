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

class PosAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::guard('pos')->check()) {
            $user = Auth::guard('pos')->user();
            if (($user->pos_role ?? null) === 'archive_viewer') {
                return redirect('/pos/archive');
            }
            if (($user->pos_role ?? null) === 'local_viewer') {
                return redirect('/pos/local-bills');
            }
            // POS UNIFICATION: every POS user (restaurant or retail) bills on the
            // single universal sale screen; restaurant behavior is driven by features.
            return redirect('/pos/invoice/create');
        }
        return view('pos.auth.login');
    }

    /**
     * PHASE 3 — Login Isolation (POS panel)
     * 1) Admin first (universal) → /admin/dashboard
     * 2) POS guard ONLY for users whose company.product_type === 'pos'
     * 3) Generic "Invalid credentials" otherwise — no info leak, no cross-redirect
     */
    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $throttleKey = Str::transliterate(Str::lower($request->input('login')) . '|pos|' . $request->ip());
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

        // ═══ STEP 2 — POS user lookup (strict isolation) ═══
        $user = null;
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $login)->first();
        } elseif (preg_match('/^\d{7,13}$/', preg_replace('/\D/', '', $login))) {
            // 7–8 digits = a real Pakistani NTN (task 433) — company lookup only.
            // 10–13 digits keep phone-first precedence, then NTN/CNIC fallback.
            $phone = preg_replace('/\D/', '', $login);
            if (strlen($phone) >= 10) {
                $user = User::where('phone', $phone)->first();
            }
            if (!$user) {
                // Frost & Brew (Aug 2026): NTN/CNIC typed WITH dashes must still
                // match — DB stores plain digits, so compare BOTH the raw input
                // and the digit-only form.
                // Task 579: panel-aware pick — if the same NTN/CNIC exists on
                // companies of different products (legacy admin-set dupes),
                // prefer THIS panel's company; otherwise the post-password
                // product check turns a valid login into a failure. Dashed
                // legacy CNICs are matched via the REPLACE digit-compare.
                $matches = Company::where(function ($q) use ($login, $phone) {
                    $q->where('ntn', $login)->orWhere('cnic', $login)
                      ->orWhere('ntn', $phone)->orWhere('cnic', $phone)
                      ->orWhereRaw("REPLACE(REPLACE(cnic, '-', ''), ' ', '') = ?", [$phone]);
                })->get();
                $company = $matches->firstWhere('product_type', 'pos') ?? $matches->first();
                if ($company) {
                    $user = User::where('company_id', $company->id)->where('role', 'company_admin')->orderBy('id')->first();
                }
            }
        } else {
            // Username login (owner report 10 Aug 2026): username column first,
            // then email local-part fallback ("cashier1" → cashier1@gmail.com),
            // scoped to POS-panel companies. Ambiguity = clear error, never a
            // guess into the wrong account.
            $resolved = \App\Services\LoginIdentifierResolver::resolveUsername($login, ['pos']);
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

            if ($company && $company->product_type === 'pos') {
                RateLimiter::clear($throttleKey);
                Auth::guard('pos')->login($user, $remember);
                $request->session()->regenerate();
                $request->session()->forget('url.intended');
                // Task 705: khufia-mode flags NEVER carry into a fresh login —
                // local-check mode and the identity-switch memory reset here
                // (regenerate() keeps session data, so clear explicitly).
                $request->session()->forget(['pos_local_check', 'pos_identity_original_id']);

                // Archive Viewer → isolated Archive Portal only. Same /pos/login URL,
                // auto-detected by pos_role. POS admin/cashier never sees this account.
                if (($user->pos_role ?? null) === 'archive_viewer') {
                    return redirect('/pos/archive');
                }

                // Local Bills Viewer → isolated Local Bills Portal only.
                if (($user->pos_role ?? null) === 'local_viewer') {
                    return redirect('/pos/local-bills');
                }

                // Kitchen account (P5, F4) → straight to the Kitchen Display.
                // PosAuth middleware confines this role to /pos/restaurant/kds*.
                if (($user->pos_role ?? null) === 'pos_kitchen') {
                    return redirect('/pos/restaurant/kds');
                }

                // Waiter account (P7, F6) → straight to the Waiter Tablet.
                // PosAuth middleware confines this role to /pos/waiter*.
                if (($user->pos_role ?? null) === 'pos_waiter') {
                    return redirect('/pos/waiter');
                }

                // Delivery Manager → straight to the Deliveries board.
                // PosAuth middleware confines this role to /pos/deliveries*.
                if (($user->pos_role ?? null) === 'pos_delivery') {
                    return redirect('/pos/deliveries');
                }

                return redirect('/pos/invoice/create');
            }
            // Wrong panel → fall through to generic failure (no info leak)
        }

        // ═══ STEP 3 — Generic failure ═══
        RateLimiter::hit($throttleKey);

        return back()->withErrors([
            'login' => 'Invalid credentials.',
        ])->withInput($request->only('login'));
    }

    public function showRegister()
    {
        if (Auth::guard('pos')->check()) {
            return redirect('/pos/invoice/create');
        }
        // Package picker (owner rule Jul 2026): the shop chooses its plan at
        // sign-up; the admin sees it at approval and approves exactly that plan.
        $plans = \App\Models\PricingPlan::where('product_type', 'pos')
            ->where('is_trial', false)
            ->orderBy('price')
            ->get();
        return view('pos.auth.register', ['plans' => $plans]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:255',
            'company_ntn' => 'nullable|string|max:50|unique:companies,ntn',
            // Task 579: optional owner CNIC — becomes a login identifier.
            'company_cnic' => \App\Services\LoginIdentifierResolver::cnicRules(),
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'pos_type' => 'required|in:restaurant,retail,general,pharmacy,grocery,clothing,electronics,hardware,salon,autoparts,bakery',
            'pricing_plan_id' => 'required|integer|exists:pricing_plans,id',
        ], \App\Services\LoginIdentifierResolver::cnicMessages('company_cnic'));

        // The selected package must be a real, non-trial POS plan — the admin
        // approves exactly this plan for 1 year (owner rule Jul 2026).
        $selectedPlan = \App\Models\PricingPlan::where('id', $request->pricing_plan_id)
            ->where('product_type', 'pos')
            ->where('is_trial', false)
            ->first();
        if (!$selectedPlan) {
            throw ValidationException::withMessages([
                'pricing_plan_id' => 'Please select a valid NestPOS package.',
            ]);
        }

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
        // Standalone edition retired (Jul 2026) — every new POS company is PRA.
        $integrationMode = 'pra';

        $companyData = [
            'name' => $request->company_name,
            'ntn' => $request->company_ntn,
            'cnic' => \App\Services\LoginIdentifierResolver::normalizeCnic($request->company_cnic),
            'email' => $request->email,
            'phone' => $request->phone,
            'company_status' => 'pending',
            // Keep BOTH status columns coherent: the admin panel's Approve button
            // and the CheckCompanyApproval view-only gate key off `status`.
            'status' => 'pending',
            'product_type' => 'pos',
            'pos_type' => $posType,
            'restaurant_mode' => ($posType === 'restaurant'),
            'pra_reporting_enabled' => false,
            'pra_environment' => 'sandbox',
        ];
        // PROD schema-drift guard: if the migration hasn't landed yet, register
        // must still work (defaults to PRA behaviour) instead of 500ing.
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_integration_mode')) {
            $companyData['pos_integration_mode'] = $integrationMode;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'requested_plan_id')) {
            $companyData['requested_plan_id'] = $selectedPlan->id;
        }

        $company = Company::create($companyData);

        $userData = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
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
        ], $company->id, 'pos');

        $this->startTrial($company->id, 'pos');

        Auth::guard('pos')->login($user);

        return redirect('/pos/invoice/create');
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
        // Staff Hazri (owner batch, 26 Jul 2026): logout par is user ki SAB
        // khuli session rows band karo (multi-tab/stale rows bhi) — report
        // mein "Logout" waqt sahi dikhe. Failure kabhi logout na roke.
        try {
            $u = Auth::guard('pos')->user();
            if ($u) {
                // Task 705: agar station khufia identity-switch mein hai to
                // asal (original) local cashier hi ja raha hai — USI ki rows
                // band karo. Current PRA counterpart ki rows ko haath na lagao:
                // woh doosre PC par apne asli login se kaam kar raha ho sakta hai.
                $hazriUserId = (int) (session('pos_identity_original_id') ?: $u->id);
                \Illuminate\Support\Facades\DB::table('pos_user_sessions')
                    ->where('user_id', $hazriUserId)
                    ->whereNull('logout_at')
                    ->update(['logout_at' => now(), 'last_activity_at' => now(), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            // Table not migrated yet — ignore.
        }

        Auth::guard('pos')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/pos');
    }
}
