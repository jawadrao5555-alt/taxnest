<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

class AdminAuditController extends Controller
{
    public function index(Request $request)
    {
        $query = AdminAuditLog::with('admin')->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', \App\Helpers\DbCompat::like(), "%{$request->action}%");
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $logs = $query->paginate(30)->appends($request->all());
        return view('saas-admin.audit-logs', compact('logs'));
    }
}
