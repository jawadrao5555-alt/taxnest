<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Services\SecurityLogService;

class CompanyUserController extends Controller
{
    public function index()
    {
        $companyId = auth()->user()->company_id;
        $users = User::where('company_id', $companyId)->orderBy('created_at', 'desc')->get();
        return view('company.users', compact('users'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:company_admin,employee,viewer',
        ]);

        $companyId = auth()->user()->company_id;

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'company_id' => $companyId,
            'is_active' => true,
        ]);

        SecurityLogService::log('company_user_created', auth()->id(), [
            'new_user_id' => $user->id,
            'role' => $request->role,
            'company_id' => $companyId,
        ]);

        return redirect('/company/users')->with('success', 'User added successfully.');
    }

    public function updateRole(Request $request, User $user)
    {
        $companyId = auth()->user()->company_id;
        if ($user->company_id !== $companyId) {
            abort(403);
        }

        $request->validate([
            'role' => 'required|in:company_admin,employee,viewer',
        ]);

        $oldRole = $user->role;
        $user->update(['role' => $request->role]);

        SecurityLogService::log('company_user_role_changed', auth()->id(), [
            'user_id' => $user->id,
            'old_role' => $oldRole,
            'new_role' => $request->role,
        ]);

        return redirect('/company/users')->with('success', 'Role updated successfully.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $companyId = auth()->user()->company_id;
        if ($user->company_id !== $companyId) {
            abort(403);
        }

        $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $user->update(['password' => Hash::make($request->password)]);

        SecurityLogService::log('company_user_password_reset', auth()->id(), [
            'user_id' => $user->id,
        ]);

        return redirect('/company/users')->with('success', 'Password reset successfully.');
    }

    public function toggleActive(User $user)
    {
        $companyId = auth()->user()->company_id;
        if ($user->company_id !== $companyId) {
            abort(403);
        }

        if ($user->id === auth()->id()) {
            return redirect('/company/users')->with('error', 'You cannot deactivate yourself.');
        }

        $user->update(['is_active' => !$user->is_active]);

        SecurityLogService::log('company_user_toggled', auth()->id(), [
            'user_id' => $user->id,
            'is_active' => $user->is_active,
        ]);

        $action = $user->is_active ? 'activated' : 'deactivated';
        return redirect('/company/users')->with('success', "User {$action} successfully.");
    }
}
