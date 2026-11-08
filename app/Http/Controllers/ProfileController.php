<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Company;
use App\Services\CompanyDeletionLifecycle;
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
        $companyDeletionRequest = null;
        if ($request->user()->company_id) {
            $company = Company::find($request->user()->company_id);
            if ($company && in_array($request->user()->role, ['company_admin', 'super_admin'], true)) {
                $companyDeletionRequest = app(CompanyDeletionLifecycle::class)->active((int) $company->id);
            }
        }

        return view('profile.edit', [
            'user' => $request->user(),
            'company' => $company,
            'companyDeletionRequest' => $companyDeletionRequest,
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
            if (! in_array($request->user()->role, ['company_admin', 'super_admin'])) {
                return Redirect::route('profile.edit')->with('error', 'Only company admins can update business profile.');
            }

            $company = Company::find($request->user()->company_id);
            if (! $company) {
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
                'dp_footer_text' => 'nullable|string|max:150',
            ]);

            $data = $request->only(['name', 'owner_name', 'ntn', 'cnic', 'registration_no', 'email', 'phone', 'mobile', 'business_activity', 'address', 'city', 'website']);

            // Invoice display preferences (per-company, DI product scope)
            if ($request->has('invoice_prefs_submitted')) {
                // Only the 'di' set is owned here; merged onto the FRESH row under a
                // lock (Company::mergeJsonColumn) together with the profile columns,
                // so POS receipt sets saved concurrently are never overwritten.
                $company->mergeJsonColumn('invoice_display_prefs', function (array $prefs) use ($request): array {
                    $prefs['di'] = [
                        'show_address' => $request->has('dp_show_address'),
                        'show_ntn' => $request->has('dp_show_ntn'),
                        'show_email' => $request->has('dp_show_email'),
                        'show_mobile' => $request->has('dp_show_mobile'),
                        'show_footer' => $request->has('dp_show_footer'),
                        'footer_text' => trim((string) $request->input('dp_footer_text', '')) ?: null,
                    ];

                    return $prefs;
                }, $data);
            } else {
                $company->update($data);
            }

            SecurityLogService::log('company_profile_updated', auth()->id(), [
                'company_id' => $company->id,
            ]);

            return Redirect::route('dashboard')->with('success', 'Business profile updated successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Profile company update error', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'company_id' => auth()->user()->company_id ?? null,
            ]);

            return Redirect::route('profile.edit')->with('error', 'Error updating profile: '.$e->getMessage());
        }
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user()?->fresh();
        abort_unless($user, 403);
        if (! \Illuminate\Support\Facades\Hash::check((string) $request->input('password'), $user->password)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['password' => __('auth.password')])->errorBag('userDeletion');
        }

        // A business owner cannot safely "delete account" by removing only
        // their login while the company, invoices and fiscal records remain.
        // Password verification above establishes the requester; the actual
        // company purge remains an administrator-reviewed, recoverable hold.
        if ($user->company_id && in_array($user->role, ['company_admin', 'super_admin'], true)) {
            $company = Company::findOrFail($user->company_id);
            $deletion = app(CompanyDeletionLifecycle::class)->requestByOwner(
                $company,
                $user,
                trim((string) $request->input('reason')) ?: null,
                (string) $request->input('password')
            );

            if (! $deletion) {
                return Redirect::route('profile.edit')->with('error',
                    'Deletion requests are temporarily unavailable while the security update is being installed. No account or data was deleted.');
            }

            return Redirect::route('profile.edit')->with('success',
                'Company deletion request recorded and password-verified. Nothing has been deleted yet; an administrator must review it and the recoverable bin hold still applies.');
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
