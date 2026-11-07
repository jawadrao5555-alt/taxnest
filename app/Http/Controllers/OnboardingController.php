<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class OnboardingController extends Controller
{
    public function index()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if ($company && $company->onboarding_completed) {
            return redirect('/dashboard');
        }

        abort_unless($company, 404);
        $progress = $this->progress($company);

        $currentStep = 1;
        if ($progress['branch']) $currentStep = 2;
        if ($progress['branch'] && $progress['fbr_token']) $currentStep = 3;
        if ($progress['branch'] && $progress['fbr_token'] && $progress['product']) $currentStep = 4;

        $allDone = $progress['branch'] && $progress['fbr_token'] && $progress['product'] && $progress['invoice'];

        return view('onboarding.index', compact('company', 'progress', 'currentStep', 'allDone'));
    }

    public function complete()
    {
        $companyId = $this->manageableCompanyId();
        $completed = DB::transaction(function () use ($companyId) {
            $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            if (!$company->onboarding_completed && in_array(false, $this->progress($company), true)) return false;
            $company->update(['onboarding_completed' => true]);
            return true;
        });
        if (!$completed) {
            return redirect('/onboarding')->withErrors(['setup' => 'Complete the remaining setup steps, or choose Skip Setup to continue later.']);
        }
        return redirect('/dashboard')->with('success', 'Setup complete! Welcome to TaxNest.');
    }

    public function skip()
    {
        $companyId = $this->manageableCompanyId();
        Company::where('id', $companyId)->update(['onboarding_completed' => true]);
        return redirect('/dashboard');
    }

    private function manageableCompanyId(): int
    {
        $id = app('currentCompanyId');
        $user = auth()->user();
        abort_unless($user && $id && (int) $user->company_id === (int) $id
            && in_array($user->role, ['company_admin', 'super_admin'], true), 403);
        return (int) $id;
    }

    private function progress(Company $company): array
    {
        return [
            'branch' => Branch::where('company_id', $company->id)->exists(),
            'fbr_token' => !empty($company->fbr_sandbox_token) || !empty($company->fbr_production_token),
            'product' => Product::where('company_id', $company->id)->exists(),
            // A local draft is sufficient for this setup step. Never send a
            // fiscal invoice merely to satisfy onboarding completion.
            'invoice' => Invoice::where('company_id', $company->id)->exists(),
        ];
    }
}
