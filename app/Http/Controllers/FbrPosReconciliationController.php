<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Services\FbrPosOwnerReconciliation;
use App\Services\FbrPosSubmissionEvidenceService;
use Illuminate\Http\Request;

class FbrPosReconciliationController extends Controller
{
    public function show(int $id)
    {
        $companyId = (int) app('currentCompanyId');
        FbrPosOwnerReconciliation::authorize(auth('fbrpos')->user(), $companyId);
        $bill = FbrPosTransaction::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);
        abort_unless(FbrPosOwnerReconciliation::eligible($bill), 409);
        $evidence = app(FbrPosSubmissionEvidenceService::class)->diagnostics(Company::findOrFail($companyId));
        return view('fbr-pos.reconcile', compact('bill', 'evidence'));
    }

    public function store(Request $request, int $id, FbrPosOwnerReconciliation $service)
    {
        $service->resolve(auth('fbrpos')->user(), (int) app('currentCompanyId'), $id, $request->all());
        return redirect()->route('fbrpos.failQueue')->with('success', 'Owner reconciliation recorded. Accepted invoices will not be resubmitted; confirmed rejections may be retried.');
    }
}
