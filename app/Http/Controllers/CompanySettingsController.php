<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Services\SecurityLogService;
use Illuminate\Support\Facades\Crypt;

class CompanySettingsController extends Controller
{
    public function profile()
    {
        $company = Company::find(auth()->user()->company_id);
        return view('company.profile', compact('company'));
    }

    public function updateProfile(Request $request)
    {
        $company = Company::find(auth()->user()->company_id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
        ]);

        $company->update($request->only(['name', 'email', 'phone', 'address']));

        SecurityLogService::log('company_profile_updated', auth()->id(), [
            'company_id' => $company->id,
        ]);

        return redirect('/company/profile')->with('success', 'Company profile updated.');
    }

    public function fbrSettings()
    {
        $company = Company::find(auth()->user()->company_id);

        $sandboxToken = null;
        $productionToken = null;
        try {
            if ($company->fbr_sandbox_token) {
                $sandboxToken = Crypt::decryptString($company->fbr_sandbox_token);
            }
            if ($company->fbr_production_token) {
                $productionToken = Crypt::decryptString($company->fbr_production_token);
            }
        } catch (\Exception $e) {
            $sandboxToken = $company->fbr_sandbox_token;
            $productionToken = $company->fbr_production_token;
        }

        return view('company.fbr-settings', compact('company', 'sandboxToken', 'productionToken'));
    }

    public function updateFbrSettings(Request $request)
    {
        $company = Company::find(auth()->user()->company_id);

        $request->validate([
            'fbr_environment' => 'required|in:sandbox,production',
            'fbr_sandbox_token' => 'nullable|string|max:500',
            'fbr_production_token' => 'nullable|string|max:500',
            'fbr_registration_no' => 'nullable|string|max:100',
            'fbr_business_name' => 'nullable|string|max:255',
        ]);

        if ($request->fbr_environment === 'production' && $company->fbr_environment !== 'production') {
            if (!$request->has('confirm_production') || $request->confirm_production !== 'CONFIRM') {
                return redirect('/company/fbr-settings')->with('error', 'Production switch requires double confirmation. Please type CONFIRM.');
            }
        }

        $data = [
            'fbr_environment' => $request->fbr_environment,
            'fbr_registration_no' => $request->fbr_registration_no,
            'fbr_business_name' => $request->fbr_business_name,
        ];

        if ($request->filled('fbr_sandbox_token')) {
            $data['fbr_sandbox_token'] = Crypt::encryptString($request->fbr_sandbox_token);
        }

        if ($request->filled('fbr_production_token')) {
            $data['fbr_production_token'] = Crypt::encryptString($request->fbr_production_token);
        }

        $company->update($data);

        SecurityLogService::log('fbr_settings_updated', auth()->id(), [
            'company_id' => $company->id,
            'environment' => $request->fbr_environment,
        ]);

        return redirect('/company/fbr-settings')->with('success', 'FBR settings updated.');
    }
}
