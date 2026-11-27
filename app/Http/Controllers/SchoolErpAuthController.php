<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\User;
use App\Services\CredentialLedgerService;
use App\Services\ErpPublicPricing;
use App\Services\LoginIdentifierResolver;
use App\Services\ProspectiveSubscriptionPolicy;
use App\Services\RequestedPackageService;
use App\Services\SubscriptionAssignmentService;
use App\Services\TrialSubscriptionService;
use App\Support\IdentityScope;
use App\Support\NestErps;
use App\Support\PosLocale;
use App\Support\SchoolErpPanel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class SchoolErpAuthController extends Controller
{
    public function showLogin()
    {
        $guard = auth(SchoolErpPanel::GUARD);
        if ($user = $guard->user()) {
            return redirect()->away(SchoolErpPanel::homeFor($user));
        }

        return response()->view('school-erp.login')->header('Cache-Control', 'private, no-store');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:4096']);
        $user = IdentityScope::users(NestErps::PRODUCT_TYPE)
            ->whereHas('company', fn ($query) => $query->where(NestErps::VERTICAL_COLUMN, NestErps::SCHOOL))
            ->where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password) || !SchoolErpPanel::maySignIn($user)) {
            throw ValidationException::withMessages(['email' => __('school_erp.invalid_credentials')]);
        }

        auth(SchoolErpPanel::GUARD)->login($user);
        $request->session()->regenerate();

        return redirect()->away(SchoolErpPanel::homeFor($user));
    }

    public function showRegister(Request $request)
    {
        abort_unless(SchoolErpPanel::registrationOpen(), 404);

        $plans = ErpPublicPricing::registrationPackages(NestErps::SCHOOL);
        $plan = $plans->firstWhere('id', $request->integer('plan_id'));
        $cycle = $request->query('billing_cycle');
        $preferred = $plan && in_array($cycle, SubscriptionAssignmentService::cyclesForPlan($plan), true)
            ? $plan->id.':'.$cycle : null;

        return response()->view('school-erp.register', compact('plans', 'preferred'))->header('Cache-Control', 'private, no-store');
    }

    public function register(Request $request)
    {
        abort_unless(SchoolErpPanel::registrationOpen(), 404);
        abort_unless(Schema::hasColumn('companies', NestErps::VERTICAL_COLUMN), 503);
        $data = $request->validate([
            'company_name' => 'required|string|max:255',
            'company_ntn' => ['required', 'string', 'max:50', IdentityScope::uniqueNtn(NestErps::PRODUCT_TYPE)],
            'company_cnic' => LoginIdentifierResolver::cnicRules(null, NestErps::PRODUCT_TYPE),
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', IdentityScope::uniqueEmail(NestErps::PRODUCT_TYPE)],
            'password' => ['required', 'confirmed', Password::defaults()],
            'package_choice' => ['required', 'string', 'max:40', 'regex:/^[1-9][0-9]*:(monthly|quarterly|semi_annual|annual)$/'],
        ], LoginIdentifierResolver::cnicMessages('company_cnic'));

        $identifiers = ['email' => $data['email'], 'ntn' => $data['company_ntn']];
        if ($used = CredentialLedgerService::firstUsed($identifiers, NestErps::PRODUCT_TYPE)) {
            [$field, $message] = CredentialLedgerService::rejectionFor($used);
            throw ValidationException::withMessages([$field => $message]);
        }

        [$planId, $cycle] = explode(':', $data['package_choice']);
        try {
            $user = DB::transaction(function () use ($data, $identifiers, $planId, $cycle) {
                $plan = PricingPlan::whereKey($planId)->lockForUpdate()->first();
                if (!$plan || !ErpPublicPricing::availableForRegistration($plan, NestErps::SCHOOL)
                    || !in_array($cycle, SubscriptionAssignmentService::cyclesForPlan($plan), true)) {
                    throw ValidationException::withMessages(['package_choice' => __('school_erp.package_unavailable')]);
                }

                $company = Company::create([
                    'name' => $data['company_name'],
                    'ntn' => $data['company_ntn'],
                    'cnic' => LoginIdentifierResolver::normalizeCnic($data['company_cnic'] ?? null),
                    'email' => $data['email'],
                    'status' => 'pending',
                    'company_status' => 'pending',
                    'product_type' => NestErps::PRODUCT_TYPE,
                    NestErps::VERTICAL_COLUMN => NestErps::SCHOOL,
                ] + RequestedPackageService::companyAttributes($plan, $cycle));

                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'company_id' => $company->id,
                    'role' => 'company_admin',
                    'is_active' => true,
                ]);

                CredentialLedgerService::record($identifiers, $company->id, NestErps::PRODUCT_TYPE);
                if (ProspectiveSubscriptionPolicy::available()) {
                    TrialSubscriptionService::ensureSelectedTrial($company->id, NestErps::PRODUCT_TYPE, $plan->id, true);
                } else {
                    TrialSubscriptionService::ensureTrial($company->id, NestErps::PRODUCT_TYPE);
                }

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => __('school_erp.account_exists')]);
        }

        auth(SchoolErpPanel::GUARD)->login($user);
        $request->session()->regenerate();

        return redirect()->away(SchoolErpPanel::DASHBOARD_PATH);
    }

    public function language(Request $request)
    {
        $language = $request->validate(['language' => 'required|in:en,rur,ur'])['language'];
        if ($user = auth(SchoolErpPanel::GUARD)->user()) {
            abort_unless(SchoolErpPanel::ownsCompany(Company::find($user->company_id)), 403);
            $user->forceFill(['language' => $language])->save();
        }
        $request->session()->put(PosLocale::SESSION_KEY, $language);

        return back();
    }

    public function logout(Request $request)
    {
        auth(SchoolErpPanel::GUARD)->logoutCurrentDevice();
        $request->session()->forget(['two_step_verified.'.SchoolErpPanel::GUARD, 'two_step_enrollment.'.SchoolErpPanel::GUARD]);
        $request->session()->regenerate();

        return redirect()->away(SchoolErpPanel::LOGIN_PATH);
    }
}
