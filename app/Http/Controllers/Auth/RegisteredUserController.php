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
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'company_name' => ['required', 'string', 'max:255'],
            'company_ntn' => ['required', 'string', 'max:50', 'unique:companies,ntn'],
        ]);

        $user = DB::transaction(function () use ($request) {
            $company = Company::create([
                'name' => $request->company_name,
                'ntn' => $request->company_ntn,
                'email' => $request->email,
            ]);

            $freePlan = PricingPlan::orderBy('price')->first();
            if ($freePlan) {
                Subscription::create([
                    'company_id' => $company->id,
                    'pricing_plan_id' => $freePlan->id,
                    'start_date' => now(),
                    'end_date' => now()->addMonth(),
                    'trial_ends_at' => now()->addDays(14),
                    'active' => true,
                ]);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'company_id' => $company->id,
                'role' => 'company_admin',
            ]);

            SecurityLogService::log('self_registration', $user->id, [
                'company_id' => $company->id,
                'company_name' => $company->name,
            ]);

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
