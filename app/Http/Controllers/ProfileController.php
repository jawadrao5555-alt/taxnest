<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Company;
use App\Services\SecurityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $company = null;
        if ($request->user()->company_id) {
            $company = Company::find($request->user()->company_id);
        }
        return view('profile.edit', [
            'user' => $request->user(),
            'company' => $company,
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        try {
            if (!in_array($request->user()->role, ['company_admin', 'super_admin'])) {
                return Redirect::route('profile.edit')->with('error', 'Only company admins can update business profile.');
            }

            $company = Company::find($request->user()->company_id);
            if (!$company) {
                return Redirect::route('profile.edit')->with('error', 'Company not found.');
            }

            $request->validate([
                'name' => 'required|string|max:255',
                'owner_name' => 'nullable|string|max:255',
                'ntn' => 'nullable|string|max:50',
                'cnic' => 'nullable|string|max:20',
                'registration_no' => 'nullable|string|max:100',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:50',
                'mobile' => 'nullable|string|max:50',
                'business_activity' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:100',
                'website' => 'nullable|string|max:255',
            ]);

            $company->update($request->only(['name', 'owner_name', 'ntn', 'cnic', 'registration_no', 'email', 'phone', 'mobile', 'business_activity', 'address', 'city', 'website']));

            SecurityLogService::log('company_profile_updated', auth()->id(), [
                'company_id' => $company->id,
            ]);

            return Redirect::route('profile.edit')->with('status', 'company-updated');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Profile company update error', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'company_id' => auth()->user()->company_id ?? null,
            ]);
            return Redirect::route('profile.edit')->with('error', 'Error updating profile: ' . $e->getMessage());
        }
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
