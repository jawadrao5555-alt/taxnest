<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Services\CollectionFbrSubmission;
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
        $company = Company::findOrFail($companyId);
        $collectionReceipt = $company->isLocalCollectionPilot()
            ? CollectionFbrSubmission::receiptPath($bill)
            : null;
        abort_if($company->isLocalCollectionPilot() && !$collectionReceipt, 404);
        $evidence = app(FbrPosSubmissionEvidenceService::class)->diagnostics($company);
        return view('fbr-pos.reconcile', compact('bill', 'evidence', 'collectionReceipt'));
    }

    public function store(Request $request, int $id, FbrPosOwnerReconciliation $service)
    {
        abort_if($request->session()->get('impersonation.readonly'), 403);
        $bill = $service->resolve(auth('fbrpos')->user(), (int) app('currentCompanyId'), $id, $request->all());
        if ($bill->company?->isLocalCollectionPilot()) {
            $receipt = CollectionFbrSubmission::receiptPath($bill);
            abort_unless($receipt, 404);

            return redirect($receipt)->with('ims_status', 'Existing FBR invoice number recorded from reviewed evidence.');
        }
        return redirect()->route('fbrpos.failQueue')->with('success', 'Owner reconciliation recorded. Accepted invoices will not be resubmitted; confirmed rejections may be retried.');
    }
}
