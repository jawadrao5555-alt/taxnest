<?php

namespace App\Http\Controllers;

use App\Models\CustomerProfile;
use Illuminate\Http\Request;

class CustomerProfileController extends Controller
{
    public function index(Request $request)
    {
        $companyId = app('currentCompanyId');
        $search = $request->get('search', '');

        $query = CustomerProfile::where('company_id', $companyId);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('ntn', 'ilike', "%{$search}%")
                  ->orWhere('cnic', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        $customers = $query->orderBy('name')->paginate(15)->appends(['search' => $search]);

        return view('customer-profiles.index', compact('customers', 'search'));
    }

    public function create()
    {
        return view('customer-profiles.create');
    }

    public function store(Request $request)
    {
        $ntn = $request->ntn;
        $ntnDigits = preg_replace('/[^0-9]/', '', $ntn ?? '');
        $registrationType = (strlen($ntnDigits) >= 7) ? 'Registered' : 'Unregistered';

        $rules = [
            'name' => 'required|string|max:255',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:15',
            'address' => 'nullable|string|max:1000',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
        ];

        if ($registrationType === 'Registered') {
            $rules['ntn'] = 'required|string|max:50';
            $rules['cnic'] = 'required|string|max:15';
        }

        $request->validate($rules);

        $companyId = app('currentCompanyId');

        CustomerProfile::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'ntn' => $request->ntn,
            'cnic' => $request->cnic,
            'address' => $request->address,
            'phone' => $request->phone,
            'email' => $request->email,
            'registration_type' => $registrationType,
        ]);

        return redirect('/customer-profiles')->with('success', 'Customer profile created successfully.');
    }

    public function edit(CustomerProfile $customerProfile)
    {
        $companyId = app('currentCompanyId');
        if ($customerProfile->company_id !== $companyId) abort(403);
        return view('customer-profiles.edit', compact('customerProfile'));
    }

    public function update(Request $request, CustomerProfile $customerProfile)
    {
        $companyId = app('currentCompanyId');
        if ($customerProfile->company_id !== $companyId) abort(403);

        $ntn = $request->ntn;
        $ntnDigits = preg_replace('/[^0-9]/', '', $ntn ?? '');
        $registrationType = (strlen($ntnDigits) >= 7) ? 'Registered' : 'Unregistered';

        $rules = [
            'name' => 'required|string|max:255',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:15',
            'address' => 'nullable|string|max:1000',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
        ];

        if ($registrationType === 'Registered') {
            $rules['ntn'] = 'required|string|max:50';
            $rules['cnic'] = 'required|string|max:15';
        }

        $request->validate($rules);

        $customerProfile->update([
            'name' => $request->name,
            'ntn' => $request->ntn,
            'cnic' => $request->cnic,
            'address' => $request->address,
            'phone' => $request->phone,
            'email' => $request->email,
            'registration_type' => $registrationType,
        ]);

        return redirect('/customer-profiles')->with('success', 'Customer profile updated successfully.');
    }

    public function toggle(CustomerProfile $customerProfile)
    {
        $companyId = app('currentCompanyId');
        if ($customerProfile->company_id !== $companyId) abort(403);
        $customerProfile->update(['is_active' => !$customerProfile->is_active]);
        return redirect('/customer-profiles')->with('success', 'Customer status updated.');
    }

    public function search(Request $request)
    {
        $companyId = app('currentCompanyId');
        $query = $request->get('q', '');
        $customers = CustomerProfile::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                  ->orWhere('ntn', 'ilike', "%{$query}%")
                  ->orWhere('cnic', 'ilike', "%{$query}%");
            })
            ->take(20)
            ->get(['id', 'name', 'ntn', 'cnic', 'address', 'phone', 'email', 'registration_type']);

        return response()->json($customers);
    }
}
