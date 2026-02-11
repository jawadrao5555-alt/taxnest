<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Invoice;

class OnboardingController extends Controller
{
    public function index()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if ($company && $company->onboarding_completed) {
            return redirect('/dashboard');
        }

        $progress = [
            'branch' => Branch::where('company_id', $companyId)->exists(),
            'fbr_token' => !empty($company->fbr_sandbox_token) || !empty($company->fbr_production_token),
            'product' => Product::where('company_id', $companyId)->exists(),
            'invoice' => Invoice::where('company_id', $companyId)->exists(),
        ];

        $currentStep = 1;
        if ($progress['branch']) $currentStep = 2;
        if ($progress['branch'] && $progress['fbr_token']) $currentStep = 3;
        if ($progress['branch'] && $progress['fbr_token'] && $progress['product']) $currentStep = 4;

        $allDone = $progress['branch'] && $progress['fbr_token'] && $progress['product'] && $progress['invoice'];

        return view('onboarding.index', compact('company', 'progress', 'currentStep', 'allDone'));
    }

    public function complete()
    {
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['onboarding_completed' => true]);
        return redirect('/dashboard')->with('success', 'Setup complete! Welcome to TaxNest.');
    }

    public function skip()
    {
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['onboarding_completed' => true]);
        return redirect('/dashboard');
    }
}
