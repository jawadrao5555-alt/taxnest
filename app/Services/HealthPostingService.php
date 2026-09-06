<?php

namespace App\Services;

use App\Models\HealthAccountingSetting;
use App\Models\HealthBill;
use App\Models\HealthBillLine;
use App\Models\HealthCharge;
use App\Models\HealthExpense;
use App\Models\HealthFundTransfer;
use App\Models\HealthJournal;
use App\Models\HealthPayment;
use App\Models\HealthPharmacyReturn;
use App\Models\HealthPharmacySale;
use App\Models\HealthSupplierPayment;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turning what happened into what the books say (Task 1552).
 *
 * Every method here derives its entry FROM THE AUTHORITATIVE SOURCE ROW and
 * keys it with a dedupe key. That single decision is what makes the whole layer
 * safe:
 *
 *   - The same call can be made inline the moment a bill is finalized AND by a
 *     sweep the accountant presses next month. Same code, same result.
 *   - A shop that turns automatic posting on after six months of trading gets
 *     six months of correct books from one sweep, not a blank ledger.
 *   - Nothing needs a "posted" flag on the source table. The journal's dedupe
 *     key IS the flag, so a source row and its posting can never disagree about
 *     whether it happened.
 *
 * Nothing here ever guesses an amount. If the source row cannot say what a
 * number is, the posting is refused and the reason is shown, because a ledger
 * that quietly rounds a hospital's money is worse than no ledger.
 */
class HealthPostingService
{
    /** Never sweep the whole of history in one web request. */
    public const SWEEP_LIMIT = 400;

    /**
     * TRUE when the organisation wants source events posted automatically.
     *
     * OFF is a legitimate answer, not a broken install: a hospital mid-migration
     * wants the books to exist and stay empty until its opening balances are
     * right. The sweep button on the accounts screen always works regardless.
     */
    public static function autoPostEnabled(int $companyId): bool
    {
        $settings = HealthFiscalPeriodService::settings($companyId);

        return $settings ? (bool) $settings->auto_post_enabled : false;
    }

    /**
     * Post one source event, but only if automatic posting is on.
     *
     * This is what the billing and pharmacy write paths call. It never throws
     * and never fails the operation in front of the user: a receipt that was
     * taken has been taken, and refusing it because the ledger had a bad day
     * would be the software choosing its own bookkeeping over the patient at
     * the counter. Anything missed is picked up by the sweep.
     */
    public static function auto(string $method, ...$args): void
    {
        try {
            $companyId = 0;
            $first = $args[0] ?? null;
            if (is_object($first) && isset($first->company_id)) {
                $companyId = (int) $first->company_id;
            }
            if ($companyId <= 0 || !self::autoPostEnabled($companyId)) {
                return;
            }
            if (!method_exists(self::class, $method)) {
                return;
            }

            self::$method(...$args);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // BILLS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * A finalized invoice: income earned and money owed.
     *
     *   Dr Receivable            total_amount   (split patient/insurance/corporate)
     *   Dr Concessions           concession_amount
     *     Cr Income (by line)      gross_amount
     *     Cr Tax Payable           tax_amount
     *
     * It balances because net = gross − concession and total = net + tax, so
     * total + concession = gross + tax. That identity is the reason concession
     * is booked as contra-income rather than netted off silently: a hospital
     * that gave away 200,000 needs to see the 200,000.
     */
    public static function postBill(HealthBill $bill, $actor = null): array
    {
        if ($bill->doc_type !== HealthBill::TYPE_INVOICE) {
            return ['ok' => true, 'skipped' => 'estimate'];
        }
        if (!in_array($bill->status, HealthBill::LIVE_STATUSES, true)) {
            return ['ok' => true, 'skipped' => 'not_live'];
        }

        $companyId = (int) $bill->company_id;
        $lines = [];

        // Income, grouped by category AND department so departmental
        // profitability is a query rather than an estimate.
        $groups = HealthBillLine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_bill_id', $bill->id)
            ->selectRaw('category, health_department_id, SUM(gross_amount) as gross, SUM(concession_amount) as concession, SUM(tax_amount) as tax')
            ->groupBy('category', 'health_department_id')
            ->get();

        if ($groups->isEmpty()) {
            return ['ok' => true, 'skipped' => 'no_lines'];
        }

        $concessionTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($groups as $group) {
            $gross = round((float) $group->gross, 2);
            $concessionTotal = round($concessionTotal + (float) $group->concession, 2);
            $taxTotal = round($taxTotal + (float) $group->tax, 2);

            if ($gross <= 0) {
                continue;
            }

            $lines[] = [
                'account_id' => HealthChartOfAccountsService::incomeIdForCategory($companyId, $group->category),
                'credit' => $gross,
                'health_department_id' => $group->health_department_id,
                'health_patient_id' => $bill->health_patient_id,
                'memo' => $group->category,
            ];
        }

        if ($concessionTotal > 0) {
            $lines[] = [
                'account' => HealthChartOfAccountsService::INCOME_CONCESSION,
                'debit' => $concessionTotal,
                'health_patient_id' => $bill->health_patient_id,
            ];
        }

        if ($taxTotal > 0) {
            $lines[] = [
                'account' => HealthChartOfAccountsService::TAX_PAYABLE,
                'credit' => $taxTotal,
            ];
        }

        // Who owes it. Three payers, three receivables — a panel patient's
        // insurance slice sitting in the same bucket as walk-in cash is how a
        // hospital chases the wrong people for ninety days.
        foreach ([
            [HealthChartOfAccountsService::PATIENT_RECEIVABLE, (float) $bill->patient_payable],
            [HealthChartOfAccountsService::INSURANCE_RECEIVABLE, (float) $bill->insurance_amount],
            [HealthChartOfAccountsService::CORPORATE_RECEIVABLE, (float) $bill->corporate_amount],
        ] as [$key, $amount]) {
            $amount = round($amount, 2);
            if (abs($amount) <= HealthLedgerService::EPSILON) {
                continue;
            }
            $lines[] = [
                'account' => $key,
                'debit' => $amount,
                'health_patient_id' => $bill->health_patient_id,
                'health_department_id' => $bill->health_department_id,
            ];
        }

        return HealthLedgerService::post($companyId, [
            'date' => $bill->bill_date,
            'branch_id' => $bill->branch_id,
            'lines' => $lines,
            'memo' => __('health.jrn_memo_bill', ['no' => $bill->bill_no]),
            'source_type' => HealthJournal::SRC_BILL,
            'source_id' => $bill->id,
            'source_reference' => $bill->bill_no,
            'dedupe_key' => 'bill:' . $bill->id,
        ], $actor);
    }

    // ═══════════════════════════════════════════════════════════════════
    // PAYMENTS, ADVANCES, REFUNDS, WRITE-OFFS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * A receipt. Up to two journals, because two different things can happen at
     * once and a hospital has to be able to see them apart:
     *
     *   the money arriving        Dr Cash/Bank/Card   Cr Receivable or Advance
     *   an advance being applied  Dr Patient Advance  Cr Patient Receivable
     *
     * A receipt carved out of a bigger advance (split_from_payment_id) skips the
     * first: that cash arrived once already, on its parent.
     */
    public static function postPayment(HealthPayment $payment, $actor = null): array
    {
        if ($payment->reversed_at) {
            return ['ok' => true, 'skipped' => 'reversed'];
        }

        $companyId = (int) $payment->company_id;
        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return ['ok' => true, 'skipped' => 'zero'];
        }

        $date = $payment->business_date
            ? $payment->business_date->toDateString()
            : ($payment->received_at ? $payment->received_at->toDateString() : now()->toDateString());

        $results = [];

        // ── 1. The money side ──
        if (!$payment->split_from_payment_id) {
            $results['cash'] = self::postPaymentCash($payment, $date, $actor);
        }

        // ── 2. The allocation side ──
        // A deposit only becomes revenue-settling when it is pointed at a bill.
        if ($payment->kind === HealthPayment::KIND_DEPOSIT && $payment->health_bill_id) {
            $results['applied'] = HealthLedgerService::post($companyId, [
                'date' => $date,
                'branch_id' => $payment->branch_id,
                'lines' => [
                    [
                        'account' => HealthChartOfAccountsService::PATIENT_ADVANCE,
                        'debit' => $amount,
                        'health_patient_id' => $payment->health_patient_id,
                    ],
                    [
                        'account' => HealthChartOfAccountsService::PATIENT_RECEIVABLE,
                        'credit' => $amount,
                        'health_patient_id' => $payment->health_patient_id,
                    ],
                ],
                'memo' => __('health.jrn_memo_advance_applied', ['no' => $payment->receipt_no]),
                'source_type' => HealthJournal::SRC_ADVANCE_APPLIED,
                'source_id' => $payment->id,
                'source_reference' => $payment->receipt_no,
                'dedupe_key' => 'depapp:' . $payment->id,
            ], $actor);
        }

        return ['ok' => true, 'results' => $results];
    }

    /** The cash/bank half of a receipt, refund or write-off. */
    protected static function postPaymentCash(HealthPayment $payment, string $date, $actor = null): array
    {
        $companyId = (int) $payment->company_id;

        /*
         * The parent of a split carries the WHOLE amount that originally
         * arrived: its own remainder plus every piece carved off it. That total
         * never changes however often it is split again, which is what lets the
         * cash be posted once and stay right.
         */
        $amount = round((float) $payment->amount, 2);
        if ($payment->kind === HealthPayment::KIND_DEPOSIT) {
            $carved = (float) HealthPayment::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('split_from_payment_id', $payment->id)
                ->whereNull('reversed_at')
                ->sum('amount');
            $amount = round($amount + $carved, 2);
        }

        if ($amount <= 0) {
            return ['ok' => true, 'skipped' => 'zero'];
        }

        $methodAccount = HealthChartOfAccountsService::accountIdForMethod($companyId, $payment->method);

        // What the receipt settles.
        $settlesKey = match ($payment->kind) {
            HealthPayment::KIND_DEPOSIT => HealthChartOfAccountsService::PATIENT_ADVANCE,
            HealthPayment::KIND_INSURANCE => HealthChartOfAccountsService::INSURANCE_RECEIVABLE,
            HealthPayment::KIND_CORPORATE => HealthChartOfAccountsService::CORPORATE_RECEIVABLE,
            default => HealthChartOfAccountsService::PATIENT_RECEIVABLE,
        };
        $settlesAccount = HealthChartOfAccountsService::id($companyId, $settlesKey);

        if (!$methodAccount || !$settlesAccount) {
            return ['ok' => false, 'reason' => 'unknown_account'];
        }

        /*
         * A WRITE-OFF is not a payment. Nothing arrived; the hospital decided to
         * stop chasing, so the debt becomes a cost. Booking it against a cash
         * account would show money the drawer never saw.
         */
        if ($payment->kind === HealthPayment::KIND_WRITE_OFF) {
            $methodAccount = HealthChartOfAccountsService::id(
                $companyId,
                HealthChartOfAccountsService::EXPENSE_WRITE_OFF
            );
        }

        if ($methodAccount === $settlesAccount) {
            // "Paid by credit" against a receivable moves nothing. Recording a
            // debit and a credit on one account would be a journal that says
            // something happened when it did not.
            return ['ok' => true, 'skipped' => 'no_movement'];
        }

        $isOutflow = $payment->kind === HealthPayment::KIND_REFUND;

        $lines = [
            [
                'account_id' => $methodAccount,
                'debit' => $isOutflow ? 0 : $amount,
                'credit' => $isOutflow ? $amount : 0,
                'health_patient_id' => $payment->health_patient_id,
            ],
            [
                'account_id' => $settlesAccount,
                'debit' => $isOutflow ? $amount : 0,
                'credit' => $isOutflow ? 0 : $amount,
                'health_patient_id' => $payment->health_patient_id,
            ],
        ];

        $memoKey = match ($payment->kind) {
            HealthPayment::KIND_REFUND => 'health.jrn_memo_refund',
            HealthPayment::KIND_WRITE_OFF => 'health.jrn_memo_write_off',
            HealthPayment::KIND_DEPOSIT => 'health.jrn_memo_advance',
            default => 'health.jrn_memo_receipt',
        };

        return HealthLedgerService::post($companyId, [
            'date' => $date,
            'branch_id' => $payment->branch_id,
            'lines' => $lines,
            'memo' => __($memoKey, ['no' => $payment->receipt_no]),
            'source_type' => HealthJournal::SRC_PAYMENT,
            'source_id' => $payment->id,
            'source_reference' => $payment->receipt_no,
            'dedupe_key' => 'pay:' . $payment->id,
        ], $actor);
    }

    /**
     * Undo whatever a receipt put in the books.
     *
     * @return array{ok:bool,reason?:string} the FIRST refusal, so the caller can
     *                                       roll its own stamping back
     */
    public static function reversePaymentPosting(HealthPayment $payment, $actor = null, string $reason = ''): array
    {
        $companyId = (int) $payment->company_id;
        foreach (['pay:' . $payment->id, 'depapp:' . $payment->id] as $key) {
            $result = HealthLedgerService::reverseByDedupe($companyId, $key, $actor, $reason);

            if (!($result['ok'] ?? false)) {
                return $result;
            }
        }

        return ['ok' => true];
    }

    /**
     * Undo whatever a bill put in the books.
     *
     * @return array{ok:bool,reason?:string}
     */
    public static function reverseBillPosting(HealthBill $bill, $actor = null, string $reason = ''): array
    {
        return HealthLedgerService::reverseByDedupe((int) $bill->company_id, 'bill:' . $bill->id, $actor, $reason);
    }

    // ═══════════════════════════════════════════════════════════════════
    // PHARMACY PURCHASING AND SUPPLIERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Goods received from a supplier.
     *
     *   Dr Pharmacy Inventory    Cr Supplier Payable
     *
     * An asset, not an expense. Medicine on the shelf is money the hospital
     * still has; expensing it on arrival would report a loss every time the
     * pharmacy restocked and a profit every month it did not.
     */
    public static function postPurchase(PurchaseOrder $order, $actor = null): array
    {
        if (!in_array($order->status, [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_PARTIAL], true)) {
            return ['ok' => true, 'skipped' => 'not_received'];
        }

        $companyId = (int) $order->company_id;
        $amount = round((float) $order->total_amount, 2);
        if ($amount <= 0) {
            return ['ok' => true, 'skipped' => 'zero'];
        }

        $date = $order->received_date ?: ($order->order_date ?: now());

        return HealthLedgerService::post($companyId, [
            'date' => is_string($date) ? $date : $date->toDateString(),
            'branch_id' => $order->branch_id,
            'lines' => [
                [
                    'account' => HealthChartOfAccountsService::PHARMACY_INVENTORY,
                    'debit' => $amount,
                    'supplier_id' => $order->supplier_id,
                ],
                [
                    'account' => HealthChartOfAccountsService::SUPPLIER_PAYABLE,
                    'credit' => $amount,
                    'supplier_id' => $order->supplier_id,
                ],
            ],
            'memo' => __('health.jrn_memo_purchase', ['no' => $order->po_number]),
            'source_type' => HealthJournal::SRC_PURCHASE,
            'source_id' => $order->id,
            'source_reference' => $order->po_number,
            'dedupe_key' => 'po:' . $order->id,
        ], $actor);
    }

    /**
     * Money paid to a supplier.
     *
     * An ADJUSTMENT method is not a payment: it is a balance the supplier agreed
     * to drop, so it settles the payable against other income rather than
     * pretending cash left the building.
     */
    public static function postSupplierPayment(HealthSupplierPayment $payment, $actor = null): array
    {
        $companyId = (int) $payment->company_id;
        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return ['ok' => true, 'skipped' => 'zero'];
        }

        $creditKey = match ($payment->method) {
            'cash' => HealthChartOfAccountsService::CASH,
            'bank', 'cheque', 'online' => HealthChartOfAccountsService::BANK,
            'adjustment' => HealthChartOfAccountsService::INCOME_OTHER,
            default => HealthChartOfAccountsService::CASH,
        };

        return HealthLedgerService::post($companyId, [
            'date' => $payment->paid_on ?: $payment->created_at,
            'branch_id' => $payment->branch_id,
            'lines' => [
                [
                    'account' => HealthChartOfAccountsService::SUPPLIER_PAYABLE,
                    'debit' => $amount,
                    'supplier_id' => $payment->supplier_id,
                ],
                [
                    'account' => $creditKey,
                    'credit' => $amount,
                    'supplier_id' => $payment->supplier_id,
                ],
            ],
            'memo' => __('health.jrn_memo_supplier_payment', ['ref' => $payment->reference ?: ('#' . $payment->id)]),
            'source_type' => HealthJournal::SRC_SUPPLIER_PAYMENT,
            'source_id' => $payment->id,
            'source_reference' => $payment->reference,
            'dedupe_key' => 'suppay:' . $payment->id,
        ], $actor);
    }

    // ═══════════════════════════════════════════════════════════════════
    // PHARMACY SALES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The cost side of a pharmacy sale: stock leaving the shelf.
     *
     *   Dr Pharmacy Cost of Sales   Cr Pharmacy Inventory
     *
     * Always posted, for every sale, patient-linked or counter. Revenue without
     * cost is the single most common way a pharmacy is reported as wildly
     * profitable right up to the day it runs out of money.
     */
    public static function postPharmacyCogs(HealthPharmacySale $sale, $actor = null): array
    {
        $companyId = (int) $sale->company_id;
        $cost = round((float) $sale->cost_amount, 2);
        if ($cost <= 0 || $sale->status === 'void') {
            return ['ok' => true, 'skipped' => 'zero'];
        }

        return HealthLedgerService::post($companyId, [
            'date' => $sale->business_date ?: $sale->created_at,
            'branch_id' => $sale->branch_id,
            'lines' => [
                [
                    'account' => HealthChartOfAccountsService::COGS_PHARMACY,
                    'debit' => $cost,
                    'health_department_id' => $sale->health_department_id,
                ],
                [
                    'account' => HealthChartOfAccountsService::PHARMACY_INVENTORY,
                    'credit' => $cost,
                ],
            ],
            'memo' => __('health.jrn_memo_pharmacy_cogs', ['no' => $sale->sale_number]),
            'source_type' => HealthJournal::SRC_PHARMACY_COGS,
            'source_id' => $sale->id,
            'source_reference' => $sale->sale_number,
            'dedupe_key' => 'phcogs:' . $sale->id,
        ], $actor);
    }

    /**
     * The revenue side of a COUNTER pharmacy sale.
     *
     * Only for sales that never became a patient charge. A patient-linked sale
     * already reaches the books through its bill, and posting it here as well
     * would double the pharmacy's income — which is exactly the sort of error
     * that survives for months because both halves look individually correct.
     */
    public static function postPharmacySaleRevenue(HealthPharmacySale $sale, $actor = null): array
    {
        $companyId = (int) $sale->company_id;

        if ($sale->status === 'void') {
            return ['ok' => true, 'skipped' => 'void'];
        }

        /*
         * A patient-linked sale reaches the books through the patient's bill,
         * never through the counter. The charge ingest raises one for EVERY
         * such sale, so waiting for the charge to exist is not good enough:
         * at sale time it does not exist yet, and posting counter revenue now
         * would be undone by nothing when the bill posts the same medicine
         * again — the hospital's income would count it twice.
         */
        if ($sale->patient_id || self::pharmacySaleIsCharged($companyId, (int) $sale->id)) {
            return ['ok' => true, 'skipped' => 'billed_via_charge'];
        }

        $subtotal = round((float) $sale->subtotal, 2);
        $discount = round((float) $sale->discount_amount, 2);
        $tax = round((float) $sale->tax_amount, 2);
        $total = round((float) $sale->total_amount, 2);

        if ($total <= 0) {
            return ['ok' => true, 'skipped' => 'zero'];
        }

        $lines = [
            [
                'account_id' => HealthChartOfAccountsService::accountIdForMethod($companyId, $sale->payment_method),
                'debit' => $total,
            ],
            [
                'account' => HealthChartOfAccountsService::INCOME_PHARMACY,
                'credit' => $subtotal,
                'health_department_id' => $sale->health_department_id,
            ],
        ];

        if ($discount > 0) {
            $lines[] = [
                'account' => HealthChartOfAccountsService::INCOME_CONCESSION,
                'debit' => $discount,
            ];
        }
        if ($tax > 0) {
            $lines[] = [
                'account' => HealthChartOfAccountsService::TAX_PAYABLE,
                'credit' => $tax,
            ];
        }

        return HealthLedgerService::post($companyId, [
            'date' => $sale->business_date ?: $sale->created_at,
            'branch_id' => $sale->branch_id,
            'lines' => $lines,
            'memo' => __('health.jrn_memo_pharmacy_sale', ['no' => $sale->sale_number]),
            'source_type' => HealthJournal::SRC_PHARMACY_SALE,
            'source_id' => $sale->id,
            'source_reference' => $sale->sale_number,
            'dedupe_key' => 'phsale:' . $sale->id,
        ], $actor);
    }

    /**
     * A pharmacy return: money back, and stock back on the shelf.
     *
     * A return is a sale run backwards, so it has to undo the SAME pieces the
     * sale posted — the income, the concession that was given on it and the tax
     * that was collected with it. Debiting the refund straight out of income
     * leaves the concession and the tax liability standing, and a discounted or
     * taxed sale then reports revenue and tax that no longer exist.
     *
     * WHERE the money goes back to is decided by where it came from, and only
     * one path may run for a given return:
     *
     *   counter sale  → the payment method it was taken on (cash/bank/card)
     *   billed patient, bill finalized → the patient's receivable, exactly as a
     *       credit note: what they owe drops by what they brought back
     *   billed patient, bill still open → nothing here. The charge behind the
     *       draft is corrected on the charge ledger by the return workflow, and
     *       posting it here as well would credit the patient twice.
     *
     * The stock half is posted whenever goods were restocked, from the ORIGINAL
     * sale line's cost. A return valued at the selling price would quietly
     * inflate inventory by the pharmacy's margin every time somebody brought a
     * strip back.
     */
    public static function postPharmacyReturn(HealthPharmacyReturn $return, $actor = null): array
    {
        $companyId = (int) $return->company_id;
        $refund = round((float) $return->refund_amount, 2);

        $sale = HealthPharmacySale::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($return->sale_id);

        $lines = [];

        if ($refund > 0 && $sale) {
            $charge = self::livePharmacyCharge($companyId, (int) $return->sale_id);

            if ($charge) {
                if (self::chargeIsFrozen($charge)) {
                    $lines = self::patientReturnLines($companyId, $sale, $charge, $refund);
                }
            } elseif (HealthLedgerService::alreadyPosted($companyId, 'phsale:' . $return->sale_id)) {
                $lines = self::counterReturnLines($companyId, $sale, $return, $refund);
            }
        }

        // Cost of what came back and is sellable again.
        $restockCost = 0.0;
        if (Schema::hasTable('health_pharmacy_return_items') && Schema::hasTable('health_pharmacy_sale_items')) {
            $restockCost = round((float) DB::table('health_pharmacy_return_items as r')
                ->join('health_pharmacy_sale_items as s', 's.id', '=', 'r.sale_item_id')
                ->where('r.company_id', $companyId)
                ->where('r.return_id', $return->id)
                ->where('r.restocked', true)
                ->sum(DB::raw('r.quantity * s.unit_cost')), 2);
        }

        if ($restockCost > 0) {
            $lines[] = [
                'account' => HealthChartOfAccountsService::PHARMACY_INVENTORY,
                'debit' => $restockCost,
            ];
            $lines[] = [
                'account' => HealthChartOfAccountsService::COGS_PHARMACY,
                'credit' => $restockCost,
            ];
        }

        if (!$lines) {
            return ['ok' => true, 'skipped' => 'nothing_to_post'];
        }

        return HealthLedgerService::post($companyId, [
            'date' => $return->created_at ?: now(),
            'branch_id' => $return->branch_id,
            'lines' => $lines,
            'memo' => __('health.jrn_memo_pharmacy_return', ['no' => $return->return_number]),
            'source_type' => HealthJournal::SRC_PHARMACY_RETURN,
            'source_id' => $return->id,
            'source_reference' => $return->return_number,
            'dedupe_key' => 'phret:' . $return->id,
        ], $actor);
    }

    /**
     * A counter return: undo the sale's own pieces for the quantities that came
     * back, and hand the money out of the account it was taken into.
     */
    protected static function counterReturnLines(
        int $companyId,
        HealthPharmacySale $sale,
        HealthPharmacyReturn $return,
        float $refund
    ): array {
        $parts = self::pharmacyReturnParts($companyId, (int) $return->id);

        // The document's own refund is the money that actually left the drawer.
        // Any paisa between it and the reassembled pieces is revenue, never a
        // silent adjustment to the tax the hospital owes.
        $drift = round($refund - round($parts['gross'] - $parts['discount'] + $parts['tax'], 2), 2);

        return self::returnRevenueLines(
            $companyId,
            $sale,
            round($parts['gross'] + $drift, 2),
            $parts['discount'],
            $parts['tax'],
            $refund,
            HealthChartOfAccountsService::accountIdForMethod($companyId, $sale->payment_method ?? 'cash'),
            null
        );
    }

    /**
     * A return on a sale that was billed to a patient and printed.
     *
     * The pieces come from the CHARGE, not from the counter's own tax split:
     * the bill is what reached the books, and a pharmacy sale billed to a room
     * may carry a different tax treatment than the same medicine sold over the
     * counter. Reversing the counter's numbers would credit the patient for tax
     * the hospital never charged them.
     */
    protected static function patientReturnLines(
        int $companyId,
        HealthPharmacySale $sale,
        HealthCharge $charge,
        float $refund
    ): array {
        $saleTotal = round((float) $sale->total_amount, 2);
        if ($saleTotal <= 0) {
            return [];
        }

        $share = min(1.0, max(0.0, $refund / $saleTotal));
        if ($share <= 0) {
            return [];
        }

        $gross = round((float) $charge->gross_amount * $share, 2);
        $discount = round((float) $charge->concession_amount * $share, 2);
        $tax = round((float) $charge->tax_amount * $share, 2);
        $credit = round($gross - $discount + $tax, 2);

        return self::returnRevenueLines(
            $companyId,
            $sale,
            $gross,
            $discount,
            $tax,
            $credit,
            HealthChartOfAccountsService::id($companyId, HealthChartOfAccountsService::PATIENT_RECEIVABLE),
            (int) ($sale->patient_id ?: $charge->health_patient_id) ?: null
        );
    }

    /** The sale, piece by piece, run backwards. */
    protected static function returnRevenueLines(
        int $companyId,
        HealthPharmacySale $sale,
        float $gross,
        float $discount,
        float $tax,
        float $credit,
        $moneyAccountId,
        ?int $patientId
    ): array {
        if (!$moneyAccountId || $credit <= 0 || $gross <= 0) {
            return [];
        }

        $lines = [[
            'account' => HealthChartOfAccountsService::INCOME_PHARMACY,
            'debit' => $gross,
            'health_department_id' => $sale->health_department_id,
            'health_patient_id' => $patientId,
        ]];

        // The concession was a debit on the way out; it comes back as a credit.
        if ($discount > 0) {
            $lines[] = [
                'account' => HealthChartOfAccountsService::INCOME_CONCESSION,
                'credit' => $discount,
                'health_patient_id' => $patientId,
            ];
        }

        // Tax collected on goods that came back is no longer owed to anybody.
        if ($tax > 0) {
            $lines[] = [
                'account' => HealthChartOfAccountsService::TAX_PAYABLE,
                'debit' => $tax,
            ];
        }

        $lines[] = [
            'account_id' => $moneyAccountId,
            'credit' => $credit,
            'health_patient_id' => $patientId,
        ];

        return $lines;
    }

    /**
     * What the returned quantities were worth, split the way the sale split it.
     *
     * Each returned quantity carries its own line's share of that line's
     * discount and tax — the same proportion the customer was actually charged.
     *
     * @return array{gross:float,discount:float,tax:float}
     */
    protected static function pharmacyReturnParts(int $companyId, int $returnId): array
    {
        $out = ['gross' => 0.0, 'discount' => 0.0, 'tax' => 0.0];

        if (!Schema::hasTable('health_pharmacy_return_items') || !Schema::hasTable('health_pharmacy_sale_items')) {
            return $out;
        }

        $rows = DB::table('health_pharmacy_return_items as r')
            ->join('health_pharmacy_sale_items as s', 's.id', '=', 'r.sale_item_id')
            ->where('r.company_id', $companyId)
            ->where('r.return_id', $returnId)
            ->select([
                'r.quantity as returned_qty',
                's.quantity as sold_qty',
                's.unit_price',
                's.discount_amount',
                's.tax_amount',
            ])
            ->get();

        foreach ($rows as $row) {
            $soldQty = (float) $row->sold_qty;
            $returnedQty = (float) $row->returned_qty;
            if ($soldQty <= 0 || $returnedQty <= 0) {
                continue;
            }

            $share = min(1.0, $returnedQty / $soldQty);

            $out['gross'] = round($out['gross'] + round((float) $row->unit_price * $returnedQty, 2), 2);
            $out['discount'] = round($out['discount'] + round((float) $row->discount_amount * $share, 2), 2);
            $out['tax'] = round($out['tax'] + round((float) $row->tax_amount * $share, 2), 2);
        }

        return $out;
    }

    /** The live patient charge behind a pharmacy sale, if it was billed at all. */
    protected static function livePharmacyCharge(int $companyId, int $saleId): ?HealthCharge
    {
        if (!Schema::hasTable('health_charges')) {
            return null;
        }

        return HealthCharge::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('source_type', HealthCharge::SOURCE_PHARMACY_SALE)
            ->where('source_id', $saleId)
            ->where('status', '!=', HealthCharge::STATUS_REVERSED)
            ->orderByDesc('id')
            ->first();
    }

    /** TRUE once a finalized bill froze this charge — the books own it now. */
    protected static function chargeIsFrozen(HealthCharge $charge): bool
    {
        if ($charge->isLocked()) {
            return true;
        }

        if (!$charge->health_bill_id) {
            return false;
        }

        $bill = HealthBill::withoutGlobalScopes()->find($charge->health_bill_id);

        return $bill ? $bill->isFinal() : false;
    }

    /** TRUE when this sale already reaches the books as a patient charge. */
    protected static function pharmacySaleIsCharged(int $companyId, int $saleId): bool
    {
        if (!Schema::hasTable('health_charges')) {
            return false;
        }

        return self::livePharmacyCharge($companyId, $saleId) !== null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // ACCOUNTANT-ENTERED DOCUMENTS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * An expense.
     *
     *   Dr Expense account (from the category)
     *     Cr Cash / Bank        when it was paid
     *     Cr Expense Payable    when it was taken on credit
     */
    public static function postExpense(HealthExpense $expense, $actor = null): array
    {
        if ($expense->status !== HealthExpense::STATUS_POSTED) {
            return ['ok' => true, 'skipped' => 'not_live'];
        }

        $companyId = (int) $expense->company_id;
        $total = round((float) $expense->total_amount, 2);
        if ($total <= 0) {
            return ['ok' => true, 'skipped' => 'zero'];
        }

        $category = $expense->category;
        $debitAccountId = $category?->health_account_id
            ?: HealthChartOfAccountsService::id($companyId, HealthChartOfAccountsService::EXPENSE_GENERAL);

        if (!$debitAccountId) {
            return ['ok' => false, 'reason' => 'no_expense_account'];
        }

        $creditAccountId = $expense->pay_mode === HealthExpense::PAY_CREDIT
            ? HealthChartOfAccountsService::id($companyId, HealthChartOfAccountsService::EXPENSE_PAYABLE)
            : ($expense->paid_from_account_id ?: HealthChartOfAccountsService::id(
                $companyId,
                $expense->pay_mode === HealthExpense::PAY_BANK
                    ? HealthChartOfAccountsService::BANK
                    : HealthChartOfAccountsService::CASH
            ));

        if (!$creditAccountId) {
            return ['ok' => false, 'reason' => 'no_pay_account'];
        }

        return HealthLedgerService::post($companyId, [
            'date' => $expense->expense_date,
            'branch_id' => $expense->branch_id,
            'lines' => [
                [
                    'account_id' => $debitAccountId,
                    'debit' => $total,
                    'health_department_id' => $expense->health_department_id,
                    'supplier_id' => $expense->supplier_id,
                ],
                [
                    'account_id' => $creditAccountId,
                    'credit' => $total,
                    'supplier_id' => $expense->supplier_id,
                ],
            ],
            'memo' => __('health.jrn_memo_expense', ['no' => $expense->expense_no]),
            'source_type' => HealthJournal::SRC_EXPENSE,
            'source_id' => $expense->id,
            'source_reference' => $expense->expense_no,
            'dedupe_key' => 'exp:' . $expense->id,
        ], $actor);
    }

    /** Cash to bank, bank to cash, bank to bank — always one balanced pair. */
    public static function postTransfer(HealthFundTransfer $transfer, $actor = null): array
    {
        if ($transfer->status !== HealthFundTransfer::STATUS_POSTED) {
            return ['ok' => true, 'skipped' => 'not_live'];
        }

        $companyId = (int) $transfer->company_id;
        $amount = round((float) $transfer->amount, 2);
        if ($amount <= 0) {
            return ['ok' => true, 'skipped' => 'zero'];
        }

        return HealthLedgerService::post($companyId, [
            'date' => $transfer->transfer_date,
            'branch_id' => $transfer->branch_id,
            'lines' => [
                ['account_id' => $transfer->to_account_id, 'debit' => $amount],
                ['account_id' => $transfer->from_account_id, 'credit' => $amount],
            ],
            'memo' => __('health.jrn_memo_transfer', ['no' => $transfer->transfer_no]),
            'source_type' => HealthJournal::SRC_TRANSFER,
            'source_id' => $transfer->id,
            'source_reference' => $transfer->transfer_no,
            'dedupe_key' => 'xfer:' . $transfer->id,
        ], $actor);
    }

    // ═══════════════════════════════════════════════════════════════════
    // THE SWEEP
    // ═══════════════════════════════════════════════════════════════════

    /**
     * SQL for the dedupe key a source row would carry once posted.
     *
     * Driver-split because the sweep runs on MySQL in production and SQLite in
     * the test suite, and the two spell string concatenation differently.
     */
    protected static function keyExpression(string $prefix, string $idColumn): string
    {
        $driver = DB::connection()->getDriverName();

        return in_array($driver, ['mysql', 'mariadb'], true)
            ? "concat('" . $prefix . ":', " . $idColumn . ")"
            : "('" . $prefix . ":' || " . $idColumn . ")";
    }

    /**
     * Narrow a source query to rows the books have never seen.
     *
     * The catch-up sweep takes the OLDEST rows first and stops at a limit. If
     * that limit is applied before the already-posted rows are excluded, then a
     * hospital past the limit sweeps the same first four hundred posted rows
     * forever and can never reach the one row that failed to post behind them —
     * the dashboard keeps reporting work the button cannot clear. So the filter
     * has to live IN the query, ahead of the limit.
     *
     * Correlated NOT EXISTS rather than a plucked id list: the id list of a
     * year-old hospital is not something to pull into memory on every sweep.
     */
    protected static function notPosted(string $prefix, string $idColumn, int $companyId): \Closure
    {
        return function ($query) use ($prefix, $idColumn, $companyId) {
            if (!Schema::hasTable('health_journals')) {
                return $query;
            }

            return $query->whereNotExists(function ($q) use ($prefix, $idColumn, $companyId) {
                $q->selectRaw('1')
                    ->from('health_journals')
                    ->where('health_journals.company_id', $companyId)
                    ->whereRaw('health_journals.dedupe_key = ' . self::keyExpression($prefix, $idColumn));
            });
        };
    }

    /**
     * Receipts still owing the books an entry.
     *
     * A receipt can owe TWO entries — the money side and, when a deposit is
     * pointed at a bill, the allocation side — so either one missing keeps it
     * on the list. A split child never posts cash of its own (the parent
     * carries the whole amount), and a zero receipt posts nothing at all;
     * neither may be counted as pending or the sweep would chase rows that can
     * never come off the list.
     */
    protected static function pendingPaymentScope(int $companyId): \Closure
    {
        return function ($query) use ($companyId) {
            $query->where('amount', '>', 0)
                ->where(function ($outer) use ($companyId) {
                    $outer->where(function ($w) use ($companyId) {
                        $w->whereNull('split_from_payment_id');
                        self::notPosted('pay', 'health_payments.id', $companyId)($w);
                    })->orWhere(function ($w) use ($companyId) {
                        $w->where('kind', HealthPayment::KIND_DEPOSIT)
                            ->whereNotNull('health_bill_id');
                        self::notPosted('depapp', 'health_payments.id', $companyId)($w);
                    });
                });
        };
    }

    /**
     * Pharmacy sales still owing the books an entry.
     *
     * Cost only when the sale actually carries one, and revenue only for a
     * walk-in — a patient-linked sale reaches the books through its bill and
     * will never have a counter revenue journal, so counting it as pending
     * would put a number on the dashboard that no sweep can ever bring down.
     */
    protected static function pendingPharmacySaleScope(int $companyId): \Closure
    {
        return function ($query) use ($companyId) {
            $query->where(function ($outer) use ($companyId) {
                $outer->where(function ($w) use ($companyId) {
                    $w->whereNull('patient_id');
                    self::notPosted('phsale', 'health_pharmacy_sales.id', $companyId)($w);
                })->orWhere(function ($w) use ($companyId) {
                    $w->where('cost_amount', '>', 0);
                    self::notPosted('phcogs', 'health_pharmacy_sales.id', $companyId)($w);
                });
            });
        };
    }

    /** Live invoices that actually have something to post. */
    protected static function billsWorthPosting(int $companyId, string $from, string $to)
    {
        return HealthBill::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('doc_type', HealthBill::TYPE_INVOICE)
            ->whereIn('status', HealthBill::LIVE_STATUSES)
            ->whereDate('bill_date', '>=', $from)
            ->whereDate('bill_date', '<=', $to)
            // A bill with no lines posts nothing; counting it as pending would
            // put a number on the dashboard that no sweep can ever bring down.
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('health_bill_lines')
                    ->whereColumn('health_bill_lines.health_bill_id', 'health_bills.id');
            })
            ->where(self::notPosted('bill', 'health_bills.id', $companyId));
    }

    /**
     * Post everything in a window that has not been posted yet.
     *
     * The SAME methods the inline hooks call. There is no separate "batch"
     * implementation to drift out of step with the live one — the second
     * implementation is how a sweep and a live posting end up producing
     * different books for the same day.
     *
     * @return array<string,int> how many journals each source produced
     */
    public static function sweep(int $companyId, ?string $from = null, ?string $to = null, $actor = null): array
    {
        $counts = [
            'bills' => 0, 'payments' => 0, 'purchases' => 0, 'supplier_payments' => 0,
            'pharmacy_sales' => 0, 'pharmacy_returns' => 0, 'expenses' => 0,
            'transfers' => 0, 'failed' => 0,
        ];

        $from = $from ?: now()->subMonths(3)->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();

        $tally = function (array $result) use (&$counts) {
            if (!($result['ok'] ?? false)) {
                $counts['failed']++;

                return false;
            }

            return !($result['duplicate'] ?? false) && !empty($result['journal']);
        };

        // ── Bills ──
        if (Schema::hasTable('health_bills')) {
            self::billsWorthPosting($companyId, $from, $to)
                ->orderBy('id')
                ->limit(self::SWEEP_LIMIT)
                ->each(function ($bill) use (&$counts, $tally, $actor) {
                    if ($tally(self::postBill($bill, $actor))) {
                        $counts['bills']++;
                    }
                });
        }

        // ── Receipts, advances, refunds, write-offs ──
        if (Schema::hasTable('health_payments')) {
            HealthPayment::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('reversed_at')
                ->whereDate('business_date', '>=', $from)
                ->whereDate('business_date', '<=', $to)
                ->where(self::pendingPaymentScope($companyId))
                ->orderBy('id')
                ->limit(self::SWEEP_LIMIT)
                ->each(function ($payment) use (&$counts, $actor) {
                    $out = self::postPayment($payment, $actor);
                    foreach (($out['results'] ?? []) as $one) {
                        if (!($one['ok'] ?? false)) {
                            $counts['failed']++;
                        } elseif (!($one['duplicate'] ?? false) && !empty($one['journal'])) {
                            $counts['payments']++;
                        }
                    }
                });
        }

        // ── Purchases ──
        if (Schema::hasTable('purchase_orders')) {
            PurchaseOrder::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('status', [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_PARTIAL])
                ->where(self::notPosted('po', 'purchase_orders.id', $companyId))
                ->orderBy('id')
                ->limit(self::SWEEP_LIMIT)
                ->each(function ($order) use (&$counts, $tally, $actor, $from, $to) {
                    $on = $order->received_date ?: $order->order_date;
                    $on = $on ? (is_string($on) ? $on : $on->toDateString()) : null;
                    if ($on && ($on < $from || $on > $to)) {
                        return;
                    }
                    if ($tally(self::postPurchase($order, $actor))) {
                        $counts['purchases']++;
                    }
                });
        }

        // ── Supplier payments ──
        if (Schema::hasTable('health_supplier_payments')) {
            HealthSupplierPayment::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where(self::notPosted('suppay', 'health_supplier_payments.id', $companyId))
                ->orderBy('id')
                ->limit(self::SWEEP_LIMIT)
                ->each(function ($payment) use (&$counts, $tally, $actor, $from, $to) {
                    $on = $payment->paid_on ? $payment->paid_on->toDateString() : null;
                    if ($on && ($on < $from || $on > $to)) {
                        return;
                    }
                    if ($tally(self::postSupplierPayment($payment, $actor))) {
                        $counts['supplier_payments']++;
                    }
                });
        }

        // ── Pharmacy ──
        if (Schema::hasTable('health_pharmacy_sales')) {
            HealthPharmacySale::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('status', '!=', 'void')
                ->whereDate('business_date', '>=', $from)
                ->whereDate('business_date', '<=', $to)
                ->where(self::pendingPharmacySaleScope($companyId))
                ->orderBy('id')
                ->limit(self::SWEEP_LIMIT)
                ->each(function ($sale) use (&$counts, $tally, $actor) {
                    if ($tally(self::postPharmacyCogs($sale, $actor))) {
                        $counts['pharmacy_sales']++;
                    }
                    if ($tally(self::postPharmacySaleRevenue($sale, $actor))) {
                        $counts['pharmacy_sales']++;
                    }
                });
        }

        if (Schema::hasTable('health_pharmacy_returns')) {
            HealthPharmacyReturn::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->where(self::notPosted('phret', 'health_pharmacy_returns.id', $companyId))
                ->orderBy('id')
                ->limit(self::SWEEP_LIMIT)
                ->each(function ($return) use (&$counts, $tally, $actor) {
                    if ($tally(self::postPharmacyReturn($return, $actor))) {
                        $counts['pharmacy_returns']++;
                    }
                });
        }

        // ── Accountant documents ──
        if (Schema::hasTable('health_expenses')) {
            HealthExpense::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('status', HealthExpense::STATUS_POSTED)
                ->whereDate('expense_date', '>=', $from)
                ->whereDate('expense_date', '<=', $to)
                ->where(self::notPosted('exp', 'health_expenses.id', $companyId))
                ->with('category')
                ->orderBy('id')
                ->limit(self::SWEEP_LIMIT)
                ->each(function ($expense) use (&$counts, $tally, $actor) {
                    if ($tally(self::postExpense($expense, $actor))) {
                        $counts['expenses']++;
                    }
                });
        }

        if (Schema::hasTable('health_fund_transfers')) {
            HealthFundTransfer::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('status', HealthFundTransfer::STATUS_POSTED)
                ->whereDate('transfer_date', '>=', $from)
                ->whereDate('transfer_date', '<=', $to)
                ->where(self::notPosted('xfer', 'health_fund_transfers.id', $companyId))
                ->orderBy('id')
                ->limit(self::SWEEP_LIMIT)
                ->each(function ($transfer) use (&$counts, $tally, $actor) {
                    if ($tally(self::postTransfer($transfer, $actor))) {
                        $counts['transfers']++;
                    }
                });
        }

        $settings = HealthFiscalPeriodService::settings($companyId);
        if ($settings) {
            $settings->forceFill(['last_posted_at' => now()])->save();
        }

        return $counts;
    }

    /**
     * How much of the window is still unposted.
     *
     * Shown on the accounts dashboard so "the books are behind" is something the
     * accountant SEES rather than something they find out at month end.
     */
    public static function pendingCounts(int $companyId, ?string $from = null, ?string $to = null): array
    {
        $from = $from ?: now()->subMonths(3)->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();

        // The SAME predicates the sweep runs on, or the dashboard would show a
        // number the button cannot bring down.
        $out = ['bills' => 0, 'payments' => 0, 'purchases' => 0, 'expenses' => 0];

        if (Schema::hasTable('health_bills')) {
            $out['bills'] = self::billsWorthPosting($companyId, $from, $to)->count();
        }

        if (Schema::hasTable('health_payments')) {
            $out['payments'] = HealthPayment::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('reversed_at')
                ->whereDate('business_date', '>=', $from)
                ->whereDate('business_date', '<=', $to)
                ->where(self::pendingPaymentScope($companyId))
                ->count();
        }

        if (Schema::hasTable('purchase_orders')) {
            $out['purchases'] = PurchaseOrder::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('status', [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_PARTIAL])
                ->where(self::notPosted('po', 'purchase_orders.id', $companyId))
                ->count();
        }

        if (Schema::hasTable('health_expenses')) {
            $out['expenses'] = HealthExpense::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('status', HealthExpense::STATUS_POSTED)
                ->whereDate('expense_date', '>=', $from)
                ->whereDate('expense_date', '<=', $to)
                ->where(self::notPosted('exp', 'health_expenses.id', $companyId))
                ->count();
        }

        $out['total'] = array_sum($out);

        return $out;
    }
}
