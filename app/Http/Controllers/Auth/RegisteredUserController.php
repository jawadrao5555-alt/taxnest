<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules;
use Illuminate\View\View;
use App\Services\SecurityLogService;
use App\Services\CredentialLedgerService;
use App\Services\RequestedPackageService;
use Illuminate\Validation\ValidationException;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        // Task 1483/1484: the Digital Invoice landing's comparison table sends
        // the visitor here with ?plan=<package name>&cycle=<billing cycle>, so
        // the page can name the column they clicked AND the form can carry both
        // back on submit. store() re-resolves them, so an unknown or missing
        // name simply shows (and records) nothing; an unknown cycle falls back
        // to monthly, which is how DI is quoted.
        $picked = RequestedPackageService::resolvePlan(request('plan'), 'di');
        $cycle = $picked ? RequestedPackageService::cycleForPlan($picked, request('cycle')) : null;

        return view('auth.register', [
            'pickedPlanName' => $picked?->name,
            'pickedCycle' => $cycle,
            'pickedCycleLabel' => $cycle ? Subscription::getCycleLabel($cycle) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'username' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'company_name' => ['required', 'string', 'max:255'],
            'company_ntn' => ['required', 'string', 'max:50', 'unique:companies,ntn'],
            'referral_code' => ['nullable', 'string', 'max:30'],
        ]);

        // Referral attribution (affiliate program): a provided code must match
        // an ACTIVE consultant — otherwise fail loudly so nobody loses
        // attribution to a typo. Empty code = normal signup.
        $referrer = null;
        if (filled($request->referral_code)) {
            $referrer = \App\Services\ConsultantService::profileForReferralCode($request->referral_code);
            if (!$referrer) {
                throw ValidationException::withMessages([
                    'referral_code' => 'This referral code is not valid. Remove it or ask your consultant for the correct code.',
                ]);
            }
        }

        // Anti free-trial-abuse: block if any credential was ever used before
        // (survives account deletion) — force login / subscription instead.
        if ($usedType = CredentialLedgerService::firstUsed([
            'email' => $request->email,
            'phone' => $request->phone,
            'ntn' => $request->company_ntn,
            'username' => $request->username ?? null,
        ])) {
            [$field, $message] = CredentialLedgerService::rejectionFor($usedType);
            throw ValidationException::withMessages([$field => $message]);
        }

        // First-touch attribution, set once at signup, immutable after. Only
        // touch the columns when a code was actually used AND the columns
        // exist (deploy-before-migrate window must never break signups).
        $referralAttrs = [];
        if ($referrer) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'referred_by_user_id')) {
                $referralAttrs = [
                    'referred_by_user_id' => $referrer->user_id,
                    'referral_code_used' => $referrer->referral_code,
                ];
            } else {
                \Log::warning('Referral code used before consultant migration ran — attribution skipped', [
                    'code' => $referrer->referral_code,
                ]);
            }
        }

        // Task 1484: the package (and billing cycle) the visitor clicked on the
        // pricing table, carried here by hidden fields. Re-resolved server-side,
        // so a tampered, unknown, trial or wrong-product name is ignored rather
        // than stored — a signup with no package behind it stores nothing at all
        // — and an unrecognised cycle falls back to monthly.
        $requestedPackage = RequestedPackageService::companyAttributes(
            RequestedPackageService::resolvePlan($request->input('requested_plan'), 'di'),
            $request->input('requested_billing_cycle')
        );

        $user = DB::transaction(function () use ($request, $referralAttrs, $requestedPackage) {
            $company = Company::create(array_merge([
                'name' => $request->company_name,
                'ntn' => $request->company_ntn,
                'email' => $request->email,
                'product_type' => 'di',
                'company_status' => 'active',
                'status' => 'pending',
            ], $referralAttrs, $requestedPackage));

            // Always attaches a trial subscription (even if the trial plan
            // seed row is missing) — no signup may leave a company bare.
            \App\Services\TrialSubscriptionService::ensureTrial($company->id, 'di');

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone ? preg_replace('/[^0-9]/', '', $request->phone) : null,
                'username' => $request->username ?: null,
                'password' => Hash::make($request->password),
                'company_id' => $company->id,
                'role' => 'company_admin',
            ]);

            SecurityLogService::log('self_registration', $user->id, [
                'company_id' => $company->id,
                'company_name' => $company->name,
            ]);

            CredentialLedgerService::record([
                'email' => $request->email,
                'phone' => $request->phone,
                'ntn' => $request->company_ntn,
                'username' => $request->username ?? null,
            ], $company->id, 'di');

            return $user;
        });

        event(new Registered($user));

        return redirect('/login')->with('success', 'Registration submitted! Your company is pending approval. You have a 3-day free trial with up to 10 invoices.');
    }
}
