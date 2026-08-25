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
        // Task 1186: effective scope (derived default included) + own-bill
        // exemption — a derived-scope cashier can always return apna hi
        // banaya bill, whichever stream it landed in.
        // Task 1197: AND the per-cashier isolation verdict — an isolated
        // cashier can only return their OWN bills; returns on other cashiers'
        // bills are manager/owner work.
        $viewer = auth('pos')->user();
        if (!$txn->allowedForBillingScopeOf($viewer)
            || !$txn->allowedForCashierIsolationOf($viewer)) {
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

    /**
     * Quick Return lookup (Task 681): sale screen se bill number likh kar
     * seedha return form kholna. JSON: { url } ya { error }.
     *
     * Accepts (case-insensitive):
     *  - full serial:  POS-2026-00012 / L-012 (padding optional: pos-2026-12, l-12)
     *  - bare digits:  12 → POS-{thisYear/lastYear}-00012 or L-012 (newest match wins)
     *  - PRA fiscal number (exact)
     *  - receipt order code (last segment of ORD-yymmdd-XXXXX, 'code' style shops)
     *
     * Same gates as returnForm: returnsAllowed (gate) + stream lock
     * (allowedForBillingScope) + returnableReason — the redirect target
     * re-enforces everything server-side, this endpoint only navigates.
     */
    public function quickLookup(Request $r)
    {
        $this->gate();
        $companyId = app('currentCompanyId');
        $q = strtoupper(trim((string) $r->query('q', '')));
        if ($q === '' || strlen($q) > 40) {
            return response()->json(['error' => __('pos.quick_return_enter_number')], 422);
        }

        // Candidate invoice_number strings the input could mean.
        $candidates = [$q];
        if (preg_match('/^POS-?(\d{4})-?(\d+)$/', $q, $m)) {
            $candidates[] = 'POS-' . $m[1] . '-' . str_pad($m[2], 5, '0', STR_PAD_LEFT);
        } elseif (preg_match('/^P-?(\d+)$/', $q, $m)) {
            // Short final series (P-036). Padded + unpadded, pad grows past 999.
            $candidates[] = 'P-' . str_pad($m[1], 3, '0', STR_PAD_LEFT);
            $candidates[] = 'P-' . $m[1];
        } elseif (preg_match('/^L-?(\d+)$/', $q, $m)) {
            $candidates[] = 'L-' . str_pad($m[1], 3, '0', STR_PAD_LEFT);
            $candidates[] = 'L-' . $m[1];
        } elseif (ctype_digit($q)) {
            // Bare serial digits — the short final series, this year's + last
            // year's legacy POS series, and the L-series (padded + unpadded;
            // both short pads grow past 999 naturally).
            $n = ltrim($q, '0');
            $n = $n === '' ? '0' : $n;
            $candidates[] = 'P-' . str_pad($n, 3, '0', STR_PAD_LEFT);
            $candidates[] = 'P-' . $n;
            foreach ([now()->format('Y'), now()->subYear()->format('Y')] as $yr) {
                $candidates[] = 'POS-' . $yr . '-' . str_pad($n, 5, '0', STR_PAD_LEFT);
            }
            $candidates[] = 'L-' . str_pad($n, 3, '0', STR_PAD_LEFT);
            $candidates[] = 'L-' . $n;
        }
        $candidates = array_values(array_unique($candidates));

        // withoutGlobalScope: parity with returnForm — an archived reporting-OFF
        // final is still a real sale whose goods can come back.
        $txn = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where(function ($w) use ($candidates, $q) {
                $w->whereIn('invoice_number', $candidates)
                    ->orWhere('pra_invoice_number', $q);
            })
            ->orderByDesc('created_at')
            ->first();

        // Receipt order code ('code' match style): last segment of the
        // restaurant order_number (ORD-yymmdd-XXXXX). Only when no bill matched
        // and the input looks like such a code (alnum, has a letter).
        if (!$txn && preg_match('/^[A-Z0-9]{3,10}$/', $q) && preg_match('/[A-Z]/', $q)
            && Schema::hasTable('restaurant_orders')) {
            $order = \App\Models\RestaurantOrder::where('company_id', $companyId)
                ->whereNotNull('pos_transaction_id')
                ->where('order_number', 'like', '%-' . $q)
                ->orderByDesc('id')
                ->first();
            if ($order) {
                $txn = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->find($order->pos_transaction_id);
            }
        }

        if (!$txn) {
            return response()->json(['error' => __('pos.quick_return_not_found')], 404);
        }

        // Stream lock (Task 678): same per-row predicate as reports/lists.
        // Task 1186: effective scope + own-bill exemption (derived only).
        // Task 1197: isolated cashier can only quick-return their OWN bills.
        $viewer = auth('pos')->user();
        if (!$txn->allowedForBillingScopeOf($viewer)
            || !$txn->allowedForCashierIsolationOf($viewer)) {
            return response()->json(['error' => __('pos.return_manager_only')], 403);
        }

        if ($reason = self::returnableReason($txn)) {
            return response()->json(['error' => __('pos.return_not_allowed_' . $reason)], 422);
        }

        return response()->json([
            'url' => route('pos.transaction.return-form', $txn->id, false),
            'invoice_number' => $txn->invoice_number,
        ]);
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
