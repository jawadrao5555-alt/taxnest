<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CompanySessionGeneration;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'string'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);
        $current = DB::transaction(function () use ($request, $validated) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => __('auth.password')])->errorBag('updatePassword');
            }
            $changes = ['password' => Hash::make($validated['password'])];
            if (Schema::hasColumn('users', 'remember_token')) {
                $changes['remember_token'] = Str::random(60);
            }
            if (Schema::hasColumn('users', 'session_generation')) {
                $changes['session_generation'] = (string) Str::uuid();
            }
            $user->forceFill($changes)->save();

            return $user;
        });
        auth('web')->setUser($current);
        $request->session()->put(CompanySessionGeneration::key('web'), [
            'user_id' => $current->id, 'generation' => (string) $current->getAttribute('session_generation'),
        ]);

        return back()->with('status', 'password-updated');
    }
}
