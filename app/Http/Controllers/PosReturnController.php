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
 *  - Manager/owner-only (posCashierBlocked → 403); local-scoped staff blocked
 *    (returns live in the PRA stream).
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
        if (!$user || $user->posCashierBlocked()) {
            abort(403, __('pos.return_manager_only'));
        }
        // Billing Scope: local-scoped staff run the offline stream only —
        // returns/credit notes belong to the PRA stream.
        if (($user->posBillingScope() ?? 'both') === 'local') {
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

        if ($reason = self::returnableReason($original)) {
            return redirect()->route('pos.transaction.show', $original->id)
                ->with('error', __('pos.return_not_allowed_' . $reason));
        }

        return view('pos.return-form', compact('original'));
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
        PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)->findOrFail($id);

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
