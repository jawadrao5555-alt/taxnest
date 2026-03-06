<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Franchise;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminFranchiseController extends Controller
{
    public function index()
    {
        $franchises = Franchise::withCount('companies')->orderBy('created_at', 'desc')->get();
        return view('saas-admin.franchises', compact('franchises'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:franchises,email',
            'phone' => 'nullable|string|max:30',
            'commission_rate' => 'required|numeric|min:0|max:100',
            'password' => 'required|string|min:6',
        ]);

        $franchise = Franchise::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'commission_rate' => $request->commission_rate,
            'password' => $request->password,
            'status' => 'active',
        ]);

        AdminAuditLog::log(auth('admin')->id(), 'Franchise created', 'Franchise', $franchise->id, ['name' => $franchise->name]);
        return back()->with('success', "Franchise '{$franchise->name}' created.");
    }

    public function update(Request $request, $id)
    {
        $franchise = Franchise::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => "required|email|unique:franchises,email,{$id}",
            'phone' => 'nullable|string|max:30',
            'commission_rate' => 'required|numeric|min:0|max:100',
        ]);

        $franchise->update($request->only(['name', 'email', 'phone', 'commission_rate']));

        if ($request->filled('password')) {
            $franchise->update(['password' => $request->password]);
        }

        AdminAuditLog::log(auth('admin')->id(), 'Franchise updated', 'Franchise', $franchise->id, ['name' => $franchise->name]);
        return back()->with('success', "Franchise '{$franchise->name}' updated.");
    }

    public function toggleStatus($id)
    {
        $franchise = Franchise::findOrFail($id);
        $newStatus = $franchise->status === 'active' ? 'suspended' : 'active';
        $franchise->update(['status' => $newStatus]);

        AdminAuditLog::log(auth('admin')->id(), "Franchise {$newStatus}", 'Franchise', $franchise->id);
        return back()->with('success', "Franchise '{$franchise->name}' is now {$newStatus}.");
    }
}
