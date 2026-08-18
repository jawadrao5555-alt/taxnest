<?php

namespace App\Services;

use App\Models\PosTransaction;
use App\Support\PosPaymentBuckets;
use Illuminate\Support\Facades\Schema;

/**
 * Task 666: "Aaj ka Khaata" — stream-wise TODAY sale/tax summary (PRA + Local).
 *
 * Extracted from PosController::dashboard (14 Aug 2026) so the RESTAURANT
 * dashboard (RestaurantPosController::dashboard — a completely separate page)
 * can show the same card: Malik Chicken Broast (restaurant mode) ka owner
 * card dhoondta reh gaya kyunke woh sirf retail dashboard par tha.
 *
 * Conventions (must stay in lockstep with Transactions/Reports tabs):
 * - Canonical stream split = PosTransaction::applyStreamTab (never hand-rolled).
 * - Money figures are SIGNED (returns net out, Task 570); bill counts SALES-only.
 * - Cash/card tax split uses the full card-alias bucket (PosPaymentBuckets) —
 *   matching ='card' would report Rs 0 card tax.
 * - Exempt bills (pra_status='exempt_internal') belong to NO stream: aggregated
 *   ONCE as their own bucket so both-scope views never double-count them; each
 *   stream card's "exempt items" line is the mixed-bill share only.
 * - hasColumn guards keep pre-migration PROD schemas alive.
 * - Stream visibility mirrors the Transactions tabs: single-scope staff see
 *   exactly their own stream; both-scope users get LOCAL only if admin/manager.
 */
class PosTodayKhata
{
    /**
     * @param int|null $onlyCreatedBy Task 1197 — per-cashier isolation / admin
     *   per-cashier view: when set, every aggregate narrows to bills created
     *   by this user id (ANDs with the stream split — compose, never replace).
     */
    public static function build(int $companyId, string $bizToday, $user, ?int $onlyCreatedBy = null): array
    {
        $dashScope = $user?->posBillingScope() ?? 'both';

        $typeReady = Schema::hasColumn('pos_transactions', 'transaction_type');
        $signExpr = $typeReady ? "CASE WHEN transaction_type = 'return' THEN -1 ELSE 1 END" : '1';
        $saleRowExpr = $typeReady ? "CASE WHEN transaction_type = 'return' THEN 0 ELSE 1 END" : '1';

        $hasExempt = Schema::hasColumn('pos_transactions', 'exempt_amount');
        $exemptExpr = $hasExempt ? 'COALESCE(exempt_amount,0)' : '0';
        // tax_amount gets the same drift guard (Task 718 review): minimal/legacy
        // schemas without the column must still render the dashboard — tax rows
        // simply aggregate to 0 instead of throwing "no such column".
        $taxExpr = Schema::hasColumn('pos_transactions', 'tax_amount') ? 'COALESCE(tax_amount,0)' : '0';
        $hasPayMethod = Schema::hasColumn('pos_transactions', 'payment_method');
        $cardIn = "'" . implode("','", PosPaymentBuckets::CARD_ALIASES) . "'";
        $cash = PosPaymentBuckets::CASH;
        // Same drift guard for payment_method: without it the cash/card tax
        // split is unknowable — report 0 for both instead of throwing.
        $cashTaxExpr = $hasPayMethod
            ? "COALESCE(SUM(CASE WHEN payment_method = '{$cash}' THEN ({$signExpr}) * {$taxExpr} ELSE 0 END),0)"
            : '0';
        $cardTaxExpr = $hasPayMethod
            ? "COALESCE(SUM(CASE WHEN payment_method IN ({$cardIn}) THEN ({$signExpr}) * {$taxExpr} ELSE 0 END),0)"
            : '0';
        // Task 1163: sale split by payment bucket — same signed sums and the
        // same payment_method drift guard as the tax split above (cash =
        // exactly 'cash', card = full alias bucket; anything else is neither).
        $cashSaleExpr = $hasPayMethod
            ? "COALESCE(SUM(CASE WHEN payment_method = '{$cash}' THEN ({$signExpr}) * total_amount ELSE 0 END),0)"
            : '0';
        $cardSaleExpr = $hasPayMethod
            ? "COALESCE(SUM(CASE WHEN payment_method IN ({$cardIn}) THEN ({$signExpr}) * total_amount ELSE 0 END),0)"
            : '0';

        $agg = function (string $tab) use ($companyId, $bizToday, $signExpr, $saleRowExpr, $exemptExpr, $taxExpr, $cashTaxExpr, $cardTaxExpr, $cashSaleExpr, $cardSaleExpr, $onlyCreatedBy) {
            $row = PosTransaction::where('company_id', $companyId)
                ->where('status', 'completed')
                ->where('business_date', $bizToday)
                ->when($onlyCreatedBy, fn ($q) => $q->where('created_by', $onlyCreatedBy))
                ->tap(fn ($q) => PosTransaction::applyStreamTab($q, $tab))
                ->selectRaw("
                    COALESCE(SUM({$saleRowExpr}),0) as bills,
                    COALESCE(SUM(({$signExpr}) * total_amount),0) as sale,
                    {$cashSaleExpr} as cash_sale,
                    {$cardSaleExpr} as card_sale,
                    COALESCE(SUM(({$signExpr}) * {$taxExpr}),0) as tax,
                    {$cashTaxExpr} as cash_tax,
                    {$cardTaxExpr} as card_tax,
                    COALESCE(SUM(({$signExpr}) * {$exemptExpr}),0) as exempt_items,
                    COALESCE(SUM(CASE WHEN pra_status = 'submitted' THEN ({$signExpr}) * total_amount ELSE 0 END),0) as reported
                ")->first();

            return [
                'bills'        => (int) ($row->bills ?? 0),
                'sale'         => (float) ($row->sale ?? 0),
                'cash_sale'    => (float) ($row->cash_sale ?? 0),
                'card_sale'    => (float) ($row->card_sale ?? 0),
                'tax'          => (float) ($row->tax ?? 0),
                'cash_tax'     => (float) ($row->cash_tax ?? 0),
                'card_tax'     => (float) ($row->card_tax ?? 0),
                'exempt_items' => (float) ($row->exempt_items ?? 0),
                'reported'     => (float) ($row->reported ?? 0),
            ];
        };

        $showPra = $dashScope !== 'local';
        // Task 996: mirror combinedSale() — managers in khufia hidden-local mode
        // (posHidesLocalStream) must NOT see the Local ledger card, same as every
        // other dashboard surface (Transactions tab, revenue card, Pending tile).
        $showLocal = $dashScope === 'local' || ($dashScope === 'both' && $user && $user->isPosAdmin() && !$user->posHidesLocalStream());

        return [
            'scope'  => $dashScope,
            'date'   => $bizToday,
            'pra'    => $showPra ? $agg('pra') : null,
            'local'  => $showLocal ? $agg('local') : null,
            'exempt' => $agg('exempt'),
        ];
    }

    /**
     * Task 988 (owner video + voice notes, 16 Aug 2026): scope-aware combined
     * "total sale" (PRA + Local + Exempt) for the dashboard revenue cards, so
     * the headline number equals the SUM of the ledger figures the user is
     * allowed to see (PIZZA POINT video: card said Rs 80 while the ledger
     * showed Local Rs 410 — owner had to add the streams himself).
     *
     * Same canonical stream split (applyStreamTab) + signed returns netting
     * as build() above. Visibility is STRICTER than build() on one point:
     * managers in khufia hidden-local mode (posHidesLocalStream) get the
     * PRA-only figure — the big headline number must never leak the local
     * stream. Exempt bills belong to NO stream and are visible to every
     * scope (applyBillingScopeFilter convention), so they are always in.
     *
     * $bizTo null = open-ended range (business_date >= $bizFrom) for the
     * retail Monthly card; pass the same date twice for a single day.
     */
    public static function combinedSale(int $companyId, $user, string $bizFrom, ?string $bizTo = null, ?int $onlyCreatedBy = null): float
    {
        $scope = $user?->posBillingScope() ?? 'both';

        $typeReady = Schema::hasColumn('pos_transactions', 'transaction_type');
        $signExpr = $typeReady ? "CASE WHEN transaction_type = 'return' THEN -1 ELSE 1 END" : '1';

        // Task 1197 ($onlyCreatedBy): per-cashier isolation / admin
        // per-cashier view — narrows every stream sum to one creator.
        $sum = function (string $tab) use ($companyId, $bizFrom, $bizTo, $signExpr, $onlyCreatedBy) {
            return (float) (PosTransaction::where('company_id', $companyId)
                ->where('status', 'completed')
                ->where('business_date', '>=', $bizFrom)
                ->when($bizTo !== null, fn ($q) => $q->where('business_date', '<=', $bizTo))
                ->when($onlyCreatedBy, fn ($q) => $q->where('created_by', $onlyCreatedBy))
                ->tap(fn ($q) => PosTransaction::applyStreamTab($q, $tab))
                ->selectRaw("COALESCE(SUM(({$signExpr}) * total_amount),0) as sale")
                ->value('sale') ?? 0);
        };

        $showPra = $scope !== 'local';
        $showLocal = $scope === 'local'
            || ($scope === 'both' && $user && $user->isPosAdmin() && !$user->posHidesLocalStream());

        return ($showPra ? $sum('pra') : 0.0)
            + ($showLocal ? $sum('local') : 0.0)
            + $sum('exempt');
    }
}
