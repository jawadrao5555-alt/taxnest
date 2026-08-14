<?php

namespace App\Http\Controllers;

use App\Models\PosTransaction;
use App\Services\PosReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS Return / Credit-Note flow (Task 570, Aug 2026).
 *
 * Modeled on FbrPosPhase2Controller::returnForm/processReturn with PRA rules:
 *  - Permission (Task 678): PosAccessService::returnsAllowed — owner/manager
 *    always; cashiers only via the per-staff "Return / Credit Note" Custom
 *    Access tick (default OFF). Stream-locked staff may only return bills in
 *    their own stream (allowedForBillingScope).
 *  - BOTH streams returnable (Task 678): local parents produce local-triple
 *    returns that are NEVER reported; only PRA-numbered parents credit-note.
 *  - Refund methods are cash/card ONLY — PRA POS has no khata/credit bills.
 *  - Return rows store POSITIVE amounts (FBR convention); PraIntegrationService
 *    flips the sign and emits InvoiceType=3 + RefUSIN when submitting.
 *  - PRA whole-rupee header total; tax-inclusive parents keep the menu-money
 *    snapshot semantics (header subtotal = menu sum − included tax).
 *  - Only parents with a PRA fiscal number are reported (credit note needs a
 *    RefUSIN PRA has actually seen); everything else stays a LOCAL return
 *    (pra_status NULL — same category as reporting-OFF finals).
 *
 * Task 586: the processing itself moved to PosReturnService (shared with the
 * rider auto-return path) — this controller keeps the gates + HTTP shell.
 */
class PosReturnController extends Controller
{
    /** Gate shared by both endpoints. Returns an error response or null. */
    private function gate()
    {
        $user = auth('pos')->user();
        // Task 678: per-staff "Return / Credit Note" grant — single verdict
        // (PosAccessService::returnsAllowed) shared with the Return buttons on
        // the bill detail + transactions list. Owner/manager allowed by
        // default; cashiers need the Team-page Custom Access tick (default
        // OFF). The PATH_MAP 'returns' entry keeps posCashierBlocked()/PosAuth
        // consistent with this same verdict on the return routes.
        if (!$user || !\App\Services\PosAccessService::returnsAllowed($user)) {
            abort(403, __('pos.return_manager_only'));
        }
        if (!Schema::hasColumn('pos_transactions', 'transaction_type')) {
            // Prod schema drift (migration not yet applied) — fail loudly,
            // never write a return the reports cannot recognize.
            abort(503, 'Return flow unavailable: database migration pending.');
        }
        return null;
    }

    /**
     * Billing Scope stream lock (Task 678): stream-locked staff may only
     * return bills inside their own visible stream — same per-row predicate
     * the reports/lists use (allowedForBillingScope mirrors applyStreamTab).
     */
    private function assertScopeAllows(PosTransaction $txn): void
    {
        $scope = auth('pos')->user()?->posBillingScope() ?? 'both';
        if (!$txn->allowedForBillingScope($scope)) {
            abort(403, __('pos.return_manager_only'));
        }
    }

    /**
     * Which parent bills can be returned: completed finals in the PRA stream.
     * Provisionals keep their existing delete flow; returns of returns never.
     * (Delegates to PosReturnService — single source of truth since Task 586.)
     */
    public static function returnableReason(PosTransaction $txn): ?string
    {
        return PosReturnService::returnableReason($txn);
    }

    public function returnForm($id)
    {
        $this->gate();
        $companyId = app('currentCompanyId');
        // withoutGlobalScope: a reporting-OFF final archived by day-close is
        // still a real sale — its goods can come back.
        $original = PosTransaction::withoutGlobalScope('hide_archived')
            ->with('items')->where('company_id', $companyId)->findOrFail($id);

        $this->assertScopeAllows($original);

        if ($reason = self::returnableReason($original)) {
            return redirect()->route('pos.transaction.show', $original->id)
                ->with('error', __('pos.return_not_allowed_' . $reason));
        }

        // Notice branch (Task 678): SAME predicate the service uses — only a
        // non-local parent with an actual PRA fiscal number + reporting ON
        // produces a credit note; everything else stays a local return.
        $company = \App\Models\Company::find($companyId);
        $localParent = ($original->invoice_mode === 'local' || $original->pra_status === 'local');
        $praEligible = !$localParent
            && !empty($original->pra_invoice_number)
            && $company && $company->praReportingActive();

        return view('pos.return-form', compact('original', 'praEligible', 'localParent'));
    }

    public function processReturn(Request $r, $id)
    {
        $this->gate();
        $companyId = app('currentCompanyId');

        $r->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.return_qty' => 'required|numeric|min:0',
            // PRA POS has no khata/credit bills — cash or card refund only.
            'refund_method' => 'required|in:cash,card',
        ]);

        $user = auth('pos')->user();

        // Existence check (404) before the service runs — behaviour parity
        // with the pre-refactor findOrFail inside the transaction.
        $parent = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)->findOrFail($id);
        $this->assertScopeAllows($parent);

        $result = PosReturnService::createReturn(
            (int) $companyId,
            (int) $id,
            $r->items,
            $r->refund_method,
            $user->id
        );

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        $return = $result['return'];

        // PRA submission AFTER commit — network I/O never inside the DB txn.
        PosReturnService::submitToPraPostCommit($result);

        return redirect()->route('pos.transaction.show', $return->id)
            ->with('success', __('pos.return_processed', ['amount' => number_format((float) $return->total_amount)]));
    }
}
