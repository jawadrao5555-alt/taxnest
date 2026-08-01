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
use Illuminate\Validation\ValidationException;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
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
        ]);

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

        $user = DB::transaction(function () use ($request) {
            $company = Company::create([
                'name' => $request->company_name,
                'ntn' => $request->company_ntn,
                'email' => $request->email,
                'product_type' => 'di',
                'company_status' => 'active',
                'status' => 'pending',
            ]);

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
