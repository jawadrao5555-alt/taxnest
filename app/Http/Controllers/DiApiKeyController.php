<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Task 1231: DI panel management of the invoice push API key.
 *
 * Key: dik_{companyId}_{random40}. The plain key is flashed to the session
 * exactly once (shown on the next page load only); the DB stores the SHA-256
 * hash + a display hint. Regenerating replaces the key; revoking disables
 * API access entirely.
 */
class DiApiKeyController extends Controller
{
    public function index()
    {
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::findOrFail($companyId);

        return view('company.api-access', [
            'company' => $company,
            'newKey' => session('di_api_new_key'),
        ]);
    }

    public function generate(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::findOrFail($companyId);

        $plain = 'dik_' . $company->id . '_' . Str::random(40);

        $company->forceFill([
            'di_api_key_hash' => hash('sha256', $plain),
            'di_api_key_hint' => substr($plain, 0, 12) . '…' . substr($plain, -4),
            'di_api_key_created_at' => now(),
            'di_api_key_last_used_at' => null,
        ])->save();

        AuditLogService::log('di_api_key_generated', 'Company', $company->id, null, [
            'hint' => $company->di_api_key_hint,
            'by' => auth()->user()->name ?? null,
        ]);

        return redirect('/company/api-access')
            ->with('di_api_new_key', $plain)
            ->with('success', 'API key generated. Copy it now — it will not be shown again.');
    }

    public function revoke(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::findOrFail($companyId);

        if (empty($company->di_api_key_hash)) {
            return redirect('/company/api-access')->with('error', 'No API key to revoke.');
        }

        $company->forceFill([
            'di_api_key_hash' => null,
            'di_api_key_hint' => null,
            'di_api_key_created_at' => null,
            'di_api_key_last_used_at' => null,
        ])->save();

        AuditLogService::log('di_api_key_revoked', 'Company', $company->id, null, [
            'by' => auth()->user()->name ?? null,
        ]);

        return redirect('/company/api-access')->with('success', 'API key revoked. Third-party software can no longer push invoices.');
    }

    public function docs()
    {
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::findOrFail($companyId);

        return view('company.api-docs', ['company' => $company]);
    }
}
