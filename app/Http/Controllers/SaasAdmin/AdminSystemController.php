<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\SystemControl;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

class AdminSystemController extends Controller
{
    public function index()
    {
        $controls = SystemControl::orderBy('key')->get();
        return view('saas-admin.system-control', compact('controls'));
    }

    public function toggle(Request $request, $key)
    {
        $control = SystemControl::toggle($key, auth('admin')->id());
        AdminAuditLog::log(auth('admin')->id(), "System control toggled: {$key}", 'SystemControl', $control->id, [
            'new_value' => $control->value,
        ]);
        return back()->with('success', "'{$key}' is now {$control->value}.");
    }
}
