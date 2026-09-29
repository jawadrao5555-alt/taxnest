<?php

namespace App\Http\Controllers\Franchise;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

class FranchiseApprovalController extends Controller
{
    public function approve(int $companyId)
    {
        $franchiseId = auth('franchise')->id();

        $outcome = DB::transaction(function () use ($companyId, $franchiseId) {
            $company = Company::whereKey($companyId)
                ->where('franchise_id', $franchiseId)->lockForUpdate()->firstOrFail();
            if ($company->status !== 'pending' || $company->company_status !== 'pending') {
                return 'Company is no longer pending review.';
            }
            DB::table('franchise_company_approvals')->insertOrIgnore([
                'company_id' => $company->id, 'franchise_id' => $franchiseId,
                'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return null;
        });

        if ($outcome) {
            return back()->with('error', $outcome);
        }

        AdminAuditLog::create([
            'admin_id' => null, 'action' => 'Franchise company review approved',
            'target_type' => 'Company', 'target_id' => $companyId,
            'metadata' => ['franchise_id' => $franchiseId],
        ]);
        return back()->with('success', 'Registration reviewed. TaxNest admin will complete activation and package decisions.');
    }
}
