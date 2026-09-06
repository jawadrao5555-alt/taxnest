<?php

namespace App\Services;

use App\Exceptions\LedgerReversalRefused;
use App\Models\HealthBill;
use App\Models\HealthBillLine;
use App\Models\HealthCharge;
use App\Models\HealthCashierShift;
use App\Models\HealthPatient;
use App\Models\HealthPayment;
use App\Models\HealthTaxCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The patient billing workflow (Task 1551, step 2).
 *
 * Turns ledger charges into something a patient is handed, and tracks the money
 * that comes back. Estimates, deposits, department receipts, combined
 * statements, part payments, concessions, refunds, credit balances and the
 * discharge settlement all run through here, so every one of them reconciles to
 * the same persisted totals.
 *
 * The invariants worth stating outright:
 *
 *  - FINALIZING IS THE POINT OF NO RETURN. It freezes the lines, freezes each
 *    charge's tax treatment (tax_locked_at) and makes the bill real money. A
 *    draft can be thrown away; a finalized bill can only be corrected forward,
 *    by refund or credit.
 *  - A CHARGE BELONGS TO AT MOST ONE BILL. The claim is a conditional UPDATE
 *    inside a transaction, so two cashiers billing the same patient at the same
 *    second cannot both walk away with the same charge.
 *  - AN ESTIMATE OWES NOTHING. It quotes charges without claiming them, so the
 *    real bill can still be raised afterwards from the same ledger.
 *  - MONEY IS NEVER NETTED AWAY. Paid, refunded and outstanding are three
 *    stored numbers, because "what did we collect" and "what did we give back"
 *    are asked separately.
 */
class HealthBillingService
{
    /**
     * Build a bill (or an estimate) from a set of ledger charges.
     *
     * @param  array<int>  $chargeIds
     * @return array{ok:bool,reason?:string,bill?:HealthBill}
     */
    public static function createBill(int $companyId, int $patientId, array $chargeIds, array $opts = [], $actor = null): array
    {
        if (!Schema::hasTable('health_bills')) {
            return ['ok' => false, 'reason' => 'not_installed'];
        }

        $chargeIds = array_values(array_unique(array_map('intval', $chargeIds)));
        if (empty($chargeIds)) {
            return ['ok' => false, 'reason' => 'no_charges'];
        }

        $docType = in_array(($opts['doc_type'] ?? null), HealthBill::TYPES, true)
            ? $opts['doc_type']
            : HealthBill::TYPE_INVOICE;
        $scope = in_array(($opts['scope'] ?? null), HealthBill::SCOPES, true)
            ? $opts['scope']
            : HealthBill::SCOPE_DEPARTMENT;

        $bill = null;
        $failure = null;

        DB::transaction(function () use (
            $companyId, $patientId, $chargeIds, $opts, $actor, $docType, $scope, &$bill, &$failure
        ) {
            $charges = HealthCharge::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('health_patient_id', $patientId)
                ->whereIn('id', $chargeIds)
                ->where('status', HealthCharge::STATUS_POSTED)
                ->whereNull('health_bill_id')
                ->lockForUpdate()
                ->orderBy('charge_date')
                ->orderBy('id')
                ->get();

            if ($charges->isEmpty()) {
                $failure = 'no_billable_charges';

                return;
            }

            $billNo = $docType === HealthBill::TYPE_ESTIMATE
                ? HealthNumberService::estimateNumber($companyId)
                : HealthNumberService::billNumber($companyId);

            $totals = HealthChargeService::totals($charges);

            // A department bill inherits the department only when every line
            // really came from one. A mixed set has no single owner and saying
            // otherwise would put the wrong department's name on a receipt.
            $deptIds = $charges->pluck('health_department_id')->filter()->unique();
            $departmentId = $opts['health_department_id'] ?? null;
            if (!$departmentId && $scope === HealthBill::SCOPE_DEPARTMENT && $deptIds->count() === 1) {
                $departmentId = (int) $deptIds->first();
            }

            $branchIds = $charges->pluck('branch_id')->filter()->unique();

            $insurance = round(max(0, (float) ($opts['insurance_amount'] ?? 0)), 2);
            $corporate = round(max(0, (float) ($opts['corporate_amount'] ?? 0)), 2);
            $total = $totals['total'];
            // A third-party slice can never exceed the bill; anything left over
            // silently becoming "the patient's problem" is how a panel patient
            // ends up chased for money the panel agreed to pay.
            if ($insurance + $corporate > $total) {
                $insurance = min($insurance, $total);
                $corporate = round(max(0, $total - $insurance), 2);
            }
            $patientPayable = round($total - $insurance - $corporate, 2);

            $bill = HealthBill::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'branch_id' => $opts['branch_id'] ?? ($branchIds->count() === 1 ? (int) $branchIds->first() : null),
                'health_department_id' => $departmentId,
                'health_patient_id' => $patientId,
                'health_visit_id' => $opts['health_visit_id'] ?? null,
                'health_admission_id' => $opts['health_admission_id'] ?? null,
                'bill_no' => $billNo,
                'doc_type' => $docType,
                'scope' => $scope,
                'status' => HealthBill::STATUS_DRAFT,
                'bill_date' => $opts['bill_date'] ?? now()->toDateString(),
                'business_date' => now()->toDateString(),
                'due_date' => $opts['due_date'] ?? null,
                'gross_amount' => $totals['gross'],
                'concession_amount' => $totals['concession'],
                'net_amount' => $totals['net'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $total,
                'insurance_amount' => $insurance,
                'corporate_amount' => $corporate,
                'patient_payable' => $patientPayable,
                'deposit_applied' => 0,
                'paid_amount' => 0,
                'refunded_amount' => 0,
                'outstanding_amount' => $patientPayable,
                'payer_type' => in_array(($opts['payer_type'] ?? null), HealthBill::PAYER_TYPES, true)
                    ? $opts['payer_type']
                    : 'self',
                'payer_name' => $opts['payer_name'] ?? null,
                'payer_reference' => $opts['payer_reference'] ?? null,
                'treatment_totals' => $totals['by_treatment'],
                'fbr_eligible' => false,
                'share_token' => Str::random(40),
                'notes' => $opts['notes'] ?? null,
                'created_by' => $actor->id ?? null,
            ]);

            $lineNo = 1;
            foreach ($charges as $charge) {
                HealthBillLine::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'health_bill_id' => $bill->id,
                    'health_charge_id' => $charge->id,
                    'line_no' => $lineNo++,
                    'category' => $charge->category,
                    'description' => $charge->description,
                    'reference' => $charge->reference,
                    'health_department_id' => $charge->health_department_id,
                    'department_name' => optional($charge->department)->name,
                    'source_type' => $charge->source_type,
                    'source_id' => $charge->source_id,
                    'source_reference' => $charge->source_reference,
                    'unit_price' => $charge->unit_price,
                    'quantity' => $charge->quantity,
                    'gross_amount' => $charge->gross_amount,
                    'concession_amount' => $charge->concession_amount,
                    'net_amount' => $charge->net_amount,
                    'tax_treatment' => $charge->tax_treatment,
                    'tax_rate' => $charge->tax_rate,
                    'tax_amount' => $charge->tax_amount,
                    'total_amount' => $charge->total_amount,
                    'pct_code' => $charge->pct_code,
                ]);
            }

            // An ESTIMATE quotes without claiming: the same charges must still
            // be available for the real bill afterwards.
            if ($docType !== HealthBill::TYPE_ESTIMATE) {
                HealthCharge::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->whereIn('id', $charges->pluck('id')->all())
                    ->update([
                        'health_bill_id' => $bill->id,
                        'billed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });

        if ($failure) {
            return ['ok' => false, 'reason' => $failure];
        }

        return ['ok' => true, 'bill' => $bill ? $bill->fresh() : null];
    }

    /**
     * Remove the lines of a draft whose charge has since been reversed.
     *
     * @return int how many lines were dropped
     */
    public static function dropDeadLines(HealthBill $bill): int
    {
        if (!Schema::hasTable('health_bill_lines') || !Schema::hasTable('health_charges')) {
            return 0;
        }

        $dead = HealthCharge::withoutGlobalScopes()
            ->where('company_id', $bill->company_id)
            ->where('status', HealthCharge::STATUS_REVERSED)
            ->pluck('id');

        if ($dead->isEmpty()) {
            return 0;
        }

        return HealthBillLine::withoutGlobalScopes()
            ->where('company_id', $bill->company_id)
            ->where('health_bill_id', $bill->id)
            ->whereIn('health_charge_id', $dead->all())
            ->delete();
    }

    /**
     * Finalize a bill: freeze the lines, freeze the tax, make it money owed.
     *
     * After this the charges behind it cannot be reclassified, discounted or
     * reversed by anybody. That is the whole point — a document that was printed
     * and may already have been filed with the regulator has to stay
     * reproducible.
     *
     * @return array{ok:bool,reason?:string,bill?:HealthBill}
     */
    public static function finalize(HealthBill $bill, $actor = null): array
    {
        if ($bill->status !== HealthBill::STATUS_DRAFT) {
            return ['ok' => false, 'reason' => 'not_draft'];
        }
        if ($bill->isEstimate()) {
            return ['ok' => false, 'reason' => 'estimate_cannot_finalize'];
        }
        /*
         * A charge that died after the draft was built must not survive as a
         * line. Reversing a charge releases it from its draft bill, but the
         * frozen line stays behind — and finalizing that line would bill the
         * patient for a returned medicine or a cancelled procedure, with the
         * charge ledger and the printed bill each telling a different story.
         */
        self::dropDeadLines($bill);

        if ($bill->lines()->count() === 0) {
            return ['ok' => false, 'reason' => 'no_lines'];
        }

        DB::transaction(function () use ($bill, $actor) {
            $lines = HealthBillLine::withoutGlobalScopes()
                ->where('company_id', $bill->company_id)
                ->where('health_bill_id', $bill->id)
                ->get();

            $totals = HealthChargeService::totals($lines);
            $fbrTotal = $totals['by_treatment'][HealthTaxCategory::TREATMENT_FBR] ?? 0.0;

            $insurance = round((float) $bill->insurance_amount, 2);
            $corporate = round((float) $bill->corporate_amount, 2);
            $patientPayable = round($totals['total'] - $insurance - $corporate, 2);

            $bill->forceFill([
                'status' => HealthBill::STATUS_FINALIZED,
                'gross_amount' => $totals['gross'],
                'concession_amount' => $totals['concession'],
                'net_amount' => $totals['net'],
                'tax_amount' => $totals['tax'],
                'total_amount' => $totals['total'],
                'patient_payable' => $patientPayable,
                'treatment_totals' => $totals['by_treatment'],
                // Eligibility is a fact about the lines, not a preference: only
                // a bill actually carrying FBR-treated money can be filed.
                'fbr_eligible' => $fbrTotal > 0,
                'fbr_status' => $fbrTotal > 0 ? null : HealthBill::FBR_NOT_APPLICABLE,
                'finalized_at' => now(),
                'finalized_by' => $actor->id ?? null,
            ])->save();

            HealthCharge::withoutGlobalScopes()
                ->where('company_id', $bill->company_id)
                ->where('health_bill_id', $bill->id)
                ->update([
                    'status' => HealthCharge::STATUS_BILLED,
                    'tax_locked_at' => now(),
                    'tax_locked_by' => $actor->id ?? null,
                    'updated_at' => now(),
                ]);

            self::recomputeBill($bill->fresh());
        });

        // The books follow the document, never lead it. Posting happens AFTER
        // the bill is safely finalized and outside its transaction, so a ledger
        // problem can never roll back a bill the counter has already printed —
        // anything missed here is picked up by the accounts sweep.
        HealthPostingService::auto('postBill', $bill->fresh(), $actor);

        return ['ok' => true, 'bill' => $bill->fresh()];
    }

    /**
     * Cancel a bill and release its charges back to the ledger.
     *
     * Refused once money has moved or the regulator has been told, because
     * neither of those can be cancelled from this side. A paid bill is corrected
     * by refund; a filed one is corrected by the regulator's own return flow.
     *
     * @return array{ok:bool,reason?:string}
     */
    public static function cancel(HealthBill $bill, $actor = null, string $reason = ''): array
    {
        if ($bill->status === HealthBill::STATUS_CANCELLED) {
            return ['ok' => true, 'reason' => 'already_cancelled'];
        }
        if ($bill->isFbrFiled()) {
            return ['ok' => false, 'reason' => 'already_filed'];
        }
        if (round((float) $bill->paid_amount, 2) > 0) {
            return ['ok' => false, 'reason' => 'already_paid'];
        }

        try {
            DB::transaction(function () use ($bill, $actor, $reason) {
                HealthCharge::withoutGlobalScopes()
                    ->where('company_id', $bill->company_id)
                    ->where('health_bill_id', $bill->id)
                    ->update([
                        'health_bill_id' => null,
                        'billed_at' => null,
                        'status' => HealthCharge::STATUS_POSTED,
                        // The freeze is released with the bill: the charges are
                        // back on the ledger and may legitimately be
                        // reclassified again before somebody bills them
                        // properly.
                        'tax_locked_at' => null,
                        'tax_locked_by' => null,
                        'updated_at' => now(),
                    ]);

                $bill->forceFill([
                    'status' => HealthBill::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->id ?? null,
                    'cancel_reason' => $reason ? mb_substr($reason, 0, 300) : null,
                    'outstanding_amount' => 0,
                ])->save();

                // A cancelled bill's income and receivable must leave the books,
                // and the only honest way to remove a posted entry is to reverse
                // it. If the books refuse, the cancellation refuses with them —
                // a cancelled bill still carrying its income is a lie the trial
                // balance cannot show anybody.
                $undone = HealthPostingService::reverseBillPosting($bill, $actor, $reason ?: __('health.bill_cancelled'));
                if (!($undone['ok'] ?? false)) {
                    throw new LedgerReversalRefused((string) ($undone['reason'] ?? 'reverse_failed'));
                }
            });
        } catch (LedgerReversalRefused $e) {
            report($e);

            return ['ok' => false, 'reason' => 'ledger_refused'];
        }

        return ['ok' => true];
    }

    /**
     * Take money — a deposit against the account, or a payment against a bill.
     *
     * A deposit with no bill is an advance: it sits on the patient's account as
     * credit until something is billed. A payment against a bill is capped at
     * what that bill still owes, so an over-payment becomes credit on the
     * account rather than a bill that mysteriously owes minus two hundred.
     *
     * @return array{ok:bool,reason?:string,payment?:HealthPayment}
     */
    public static function recordPayment(int $companyId, int $patientId, array $data, $actor = null): array
    {
        if (!Schema::hasTable('health_payments')) {
            return ['ok' => false, 'reason' => 'not_installed'];
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return ['ok' => false, 'reason' => 'invalid_amount'];
        }

        $kind = in_array(($data['kind'] ?? null), HealthPayment::KINDS, true)
            ? $data['kind']
            : HealthPayment::KIND_PAYMENT;
        $method = in_array(($data['method'] ?? null), HealthPayment::METHODS, true)
            ? $data['method']
            : 'cash';

        $bill = null;
        if (!empty($data['health_bill_id'])) {
            $bill = HealthBill::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('health_patient_id', $patientId)
                ->find((int) $data['health_bill_id']);

            if (!$bill) {
                return ['ok' => false, 'reason' => 'bill_not_found'];
            }
            if ($bill->isEstimate()) {
                return ['ok' => false, 'reason' => 'estimate_not_payable'];
            }
            if ($bill->status === HealthBill::STATUS_DRAFT) {
                return ['ok' => false, 'reason' => 'bill_not_finalized'];
            }
            if ($bill->status === HealthBill::STATUS_CANCELLED) {
                return ['ok' => false, 'reason' => 'bill_cancelled'];
            }
        }

        $payment = null;
        $credited = 0.0;
        /*
         * EVERY row this call creates, so every one of them gets posted. An
         * overpayment writes two receipts — the part the bill owed and the
         * surplus that became credit — and posting only the first understates
         * both the cash in the drawer and the advance the hospital now owes the
         * patient until somebody happens to run a sweep.
         */
        $created = [];

        /*
         * A receipt attached to a bill can never exceed what that bill still
         * owes. The counter regularly takes a round 5,000 against a 4,300 bill,
         * and the 700 is the patient's money, not the bill's: if it were simply
         * written onto the bill, the bill would read as over-paid, the change
         * owed would vanish from the account, and the day's collection would
         * stop matching the day's billing.
         *
         * So the receipt is SPLIT — the part the bill owes is paid against the
         * bill, the surplus is stored as an unallocated deposit, which is what
         * the credit balance and applyCredit() already read.
         */
        if ($bill && $kind !== HealthPayment::KIND_REFUND) {
            $outstanding = max(0, round((float) $bill->outstanding_amount, 2));
            if ($amount > $outstanding) {
                $credited = round($amount - $outstanding, 2);
                $amount = $outstanding;
            }
        }

        DB::transaction(function () use ($companyId, $patientId, $data, $actor, $amount, $kind, $method, $bill, $credited, &$payment, &$created) {
            $shiftId = $data['health_cashier_shift_id']
                ?? (self::openShiftFor($companyId, $actor)->id ?? null);

            $common = [
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? ($bill->branch_id ?? null),
                'health_patient_id' => $patientId,
                'health_admission_id' => $data['health_admission_id'] ?? ($bill->health_admission_id ?? null),
                'health_cashier_shift_id' => $shiftId,
                'method' => $method,
                'reference' => isset($data['reference']) ? mb_substr((string) $data['reference'], 0, 120) : null,
                'received_at' => now(),
                'received_by' => $actor->id ?? null,
                'business_date' => now()->toDateString(),
            ];

            $note = isset($data['note']) ? mb_substr((string) $data['note'], 0, 300) : null;

            if ($amount > 0) {
                $payment = HealthPayment::withoutGlobalScopes()->create(array_merge($common, [
                    'health_bill_id' => $bill->id ?? null,
                    'receipt_no' => HealthNumberService::receiptNumber($companyId),
                    'kind' => $kind,
                    'amount' => $amount,
                    'note' => $note,
                ]));
                $created[] = $payment;
            }

            if ($credited > 0) {
                // The surplus gets its OWN receipt so the patient can be shown
                // exactly what became credit, and so reversing the bill payment
                // never silently takes the credit with it.
                $surplus = HealthPayment::withoutGlobalScopes()->create(array_merge($common, [
                    'health_bill_id' => null,
                    'receipt_no' => HealthNumberService::receiptNumber($companyId),
                    'kind' => HealthPayment::KIND_DEPOSIT,
                    'amount' => $credited,
                    'note' => trim((string) $note . ' ' . __('health.pay_surplus_credited', [
                        'no' => $bill->bill_no ?? '',
                    ])),
                ]));

                $created[] = $surplus;
                $payment = $payment ?: $surplus;
            }

            if ($bill) {
                self::recomputeBill($bill->fresh());
            }
        });

        foreach ($created as $row) {
            HealthPostingService::auto('postPayment', $row->fresh(), $actor);
        }

        return ['ok' => true, 'payment' => $payment, 'credited' => $credited];
    }

    /**
     * Give money back.
     *
     * Capped at what the patient actually paid on that bill, minus what has
     * already been refunded — a refund larger than the payment is either a
     * mistake or a fraud, and neither should be a single click.
     *
     * @return array{ok:bool,reason?:string,payment?:HealthPayment}
     */
    public static function refund(HealthBill $bill, float $amount, array $data = [], $actor = null): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return ['ok' => false, 'reason' => 'invalid_amount'];
        }

        $refundable = round((float) $bill->paid_amount - (float) $bill->refunded_amount, 2);
        if ($amount > $refundable) {
            return ['ok' => false, 'reason' => 'exceeds_refundable'];
        }

        $payment = null;

        DB::transaction(function () use ($bill, $amount, $data, $actor, &$payment) {
            $payment = HealthPayment::withoutGlobalScopes()->create([
                'company_id' => $bill->company_id,
                'branch_id' => $bill->branch_id,
                'health_patient_id' => $bill->health_patient_id,
                'health_bill_id' => $bill->id,
                'health_admission_id' => $bill->health_admission_id,
                'health_cashier_shift_id' => $data['health_cashier_shift_id']
                    ?? (self::openShiftFor((int) $bill->company_id, $actor)->id ?? null),
                'receipt_no' => HealthNumberService::receiptNumber((int) $bill->company_id),
                'kind' => HealthPayment::KIND_REFUND,
                'amount' => $amount,
                'method' => in_array(($data['method'] ?? null), HealthPayment::METHODS, true)
                    ? $data['method']
                    : 'cash',
                'reference' => isset($data['reference']) ? mb_substr((string) $data['reference'], 0, 120) : null,
                'note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 300) : null,
                'received_at' => now(),
                'received_by' => $actor->id ?? null,
                'business_date' => now()->toDateString(),
            ]);

            self::recomputeBill($bill->fresh());
        });

        if ($payment) {
            HealthPostingService::auto('postPayment', $payment->fresh(), $actor);
        }

        return ['ok' => true, 'payment' => $payment];
    }

    /**
     * Reverse a receipt taken in error.
     *
     * The row stays and is stamped rather than deleted: a receipt number that
     * was printed and handed over has to remain findable, and a cashier's shift
     * has to be able to show that the money came in and then went back out.
     *
     * @return array{ok:bool,reason?:string}
     */
    public static function reversePayment(HealthPayment $payment, $actor = null, string $reason = ''): array
    {
        if ($payment->reversed_at) {
            return ['ok' => true, 'reason' => 'already_reversed'];
        }

        try {
            DB::transaction(function () use ($payment, $actor, $reason) {
                // The receipt is evidence now, not money. Its ledger footprint —
                // both the cash side and any advance application — has to come
                // back out, and if it will not, the receipt stays live: a
                // reversed receipt whose cash is still in the books hides a hole
                // in the drawer.
                $undone = HealthPostingService::reversePaymentPosting($payment, $actor, $reason ?: __('health.pay_reversed'));
                if (!($undone['ok'] ?? false)) {
                    throw new LedgerReversalRefused((string) ($undone['reason'] ?? 'reverse_failed'));
                }

                $payment->forceFill([
                    'reversed_at' => now(),
                    'reversed_by' => $actor->id ?? null,
                    'reversal_reason' => $reason ? mb_substr($reason, 0, 300) : null,
                ])->save();

                if ($payment->health_bill_id) {
                    $bill = HealthBill::withoutGlobalScopes()->find($payment->health_bill_id);
                    if ($bill) {
                        self::recomputeBill($bill);
                    }
                }
            });
        } catch (LedgerReversalRefused $e) {
            report($e);

            return ['ok' => false, 'reason' => 'ledger_refused'];
        }

        return ['ok' => true];
    }

    /**
     * Apply the patient's unallocated credit (advances, over-payments) to a bill.
     *
     * Nothing is moved between rows: the deposit that funded the credit gains
     * the bill's id, so the money is still the same receipt the patient was
     * given, now pointed at what it paid for.
     *
     * @return array{ok:bool,reason?:string,applied?:float}
     */
    public static function applyCredit(HealthBill $bill, $actor = null, ?float $limit = null): array
    {
        if (!$bill->isFinal()) {
            return ['ok' => false, 'reason' => 'bill_not_finalized'];
        }

        $outstanding = round((float) $bill->outstanding_amount, 2);
        if ($outstanding <= 0) {
            return ['ok' => true, 'applied' => 0.0];
        }

        $applied = 0.0;

        $touched = [];

        DB::transaction(function () use ($bill, $outstanding, $limit, $actor, &$applied, &$touched) {
            $budget = $limit !== null ? min($outstanding, round($limit, 2)) : $outstanding;

            $credits = HealthPayment::withoutGlobalScopes()
                ->where('company_id', $bill->company_id)
                ->where('health_patient_id', $bill->health_patient_id)
                ->whereNull('health_bill_id')
                ->whereNull('reversed_at')
                ->where('kind', HealthPayment::KIND_DEPOSIT)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            foreach ($credits as $credit) {
                if ($budget <= 0) {
                    break;
                }

                $amount = round((float) $credit->amount, 2);

                if ($amount <= $budget) {
                    $credit->forceFill([
                        'health_bill_id' => $bill->id,
                        'note' => trim((string) $credit->note . ' ' . __('health.pay_applied_to', ['no' => $bill->bill_no])),
                    ])->save();
                    $budget = round($budget - $amount, 2);
                    $applied = round($applied + $amount, 2);
                    continue;
                }

                // A deposit bigger than what is left owing is SPLIT rather than
                // partly consumed: the applied part becomes its own receipt
                // against this bill and the remainder stays as credit. Anything
                // else would either over-pay the bill or strand the balance.
                $credit->forceFill(['amount' => round($amount - $budget, 2)])->save();

                HealthPayment::withoutGlobalScopes()->create([
                    'company_id' => $bill->company_id,
                    'branch_id' => $credit->branch_id,
                    'health_patient_id' => $bill->health_patient_id,
                    'health_bill_id' => $bill->id,
                    // The money already arrived once, on the parent receipt.
                    // Without this the books would credit the same cash twice —
                    // once on the parent and once on the piece carved out of it.
                    'split_from_payment_id' => $credit->id,
                    'health_admission_id' => $bill->health_admission_id,
                    'health_cashier_shift_id' => $credit->health_cashier_shift_id,
                    'receipt_no' => HealthNumberService::receiptNumber((int) $bill->company_id),
                    'kind' => HealthPayment::KIND_DEPOSIT,
                    'amount' => $budget,
                    'method' => $credit->method,
                    'reference' => $credit->receipt_no,
                    'note' => __('health.pay_applied_from', ['no' => $credit->receipt_no]),
                    'received_at' => now(),
                    'received_by' => $actor->id ?? null,
                    'business_date' => now()->toDateString(),
                ]);

                $applied = round($applied + $budget, 2);
                $budget = 0.0;
            }

            self::recomputeBill($bill->fresh());
            $touched = HealthPayment::withoutGlobalScopes()
                ->where('company_id', $bill->company_id)
                ->where('health_bill_id', $bill->id)
                ->where('kind', HealthPayment::KIND_DEPOSIT)
                ->whereNull('reversed_at')
                ->pluck('id')
                ->all();
        });

        // Applying credit turns advances into settled receivables. Each touched
        // deposit re-runs its own posting: the cash half is already keyed and
        // becomes a no-op, and the application half lands for the first time.
        foreach ($touched as $paymentId) {
            $row = HealthPayment::withoutGlobalScopes()->find($paymentId);
            if ($row) {
                HealthPostingService::auto('postPayment', $row, $actor);
            }
        }

        return ['ok' => true, 'applied' => $applied];
    }

    /**
     * Recompute a bill's money from the rows that actually exist.
     *
     * Deliberately derived rather than incremented. An incremented balance drifts
     * the first time a request dies between two writes, and a hospital cannot
     * tell a patient "the number is wrong but the receipts are right".
     */
    public static function recomputeBill(HealthBill $bill): HealthBill
    {
        $payments = HealthPayment::withoutGlobalScopes()
            ->where('company_id', $bill->company_id)
            ->where('health_bill_id', $bill->id)
            ->whereNull('reversed_at')
            ->get();

        $paid = 0.0;
        $refunded = 0.0;
        $deposits = 0.0;
        $writtenOff = 0.0;

        foreach ($payments as $p) {
            $amount = round((float) $p->amount, 2);
            if ($p->kind === HealthPayment::KIND_REFUND) {
                $refunded = round($refunded + $amount, 2);
                continue;
            }
            /*
             * A WRITE-OFF settles the debt without any money arriving. It
             * belongs in neither bucket: counted as paid it would inflate the
             * day's collection, and counted as refunded it would RAISE the
             * outstanding on a bill the hospital has just decided to stop
             * chasing. It clears the balance and stays out of every cash total.
             */
            if ($p->kind === HealthPayment::KIND_WRITE_OFF) {
                $writtenOff = round($writtenOff + $amount, 2);
                continue;
            }
            $paid = round($paid + $amount, 2);
            if ($p->kind === HealthPayment::KIND_DEPOSIT) {
                $deposits = round($deposits + $amount, 2);
            }
        }

        $payable = round((float) $bill->patient_payable, 2);
        $outstanding = round($payable - $paid - $writtenOff + $refunded, 2);
        if ($outstanding < 0) {
            $outstanding = 0.0;
        }

        $status = $bill->status;
        if (in_array($status, HealthBill::LIVE_STATUSES, true)) {
            $status = $outstanding <= 0 && $payable > 0
                ? HealthBill::STATUS_SETTLED
                : HealthBill::STATUS_FINALIZED;
        }

        $bill->forceFill([
            'paid_amount' => $paid,
            'refunded_amount' => $refunded,
            'deposit_applied' => $deposits,
            'outstanding_amount' => $outstanding,
            'status' => $status,
            'settled_at' => $status === HealthBill::STATUS_SETTLED ? ($bill->settled_at ?: now()) : null,
        ])->save();

        return $bill;
    }

    /**
     * The patient's whole financial picture, from the persisted rows only.
     *
     * This is the statement the counter reads out, and it is the same arithmetic
     * the receipts and the shift reconciliation use — one truth, three surfaces.
     */
    public static function patientAccount(int $companyId, int $patientId): array
    {
        $bills = Schema::hasTable('health_bills')
            ? HealthBill::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('health_patient_id', $patientId)
                ->orderByDesc('id')
                ->get()
            : collect();

        $payments = Schema::hasTable('health_payments')
            ? HealthPayment::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('health_patient_id', $patientId)
                ->whereNull('reversed_at')
                ->orderByDesc('id')
                ->get()
            : collect();

        $liveBills = $bills->filter(fn ($b) => in_array($b->status, HealthBill::LIVE_STATUSES, true)
            && $b->doc_type === HealthBill::TYPE_INVOICE);

        $billed = round($liveBills->sum(fn ($b) => (float) $b->patient_payable), 2);
        $outstanding = round($liveBills->sum(fn ($b) => (float) $b->outstanding_amount), 2);

        $collected = round($payments
            ->filter(fn ($p) => $p->isInflow())
            ->sum(fn ($p) => (float) $p->amount), 2);
        $refunded = round($payments
            ->filter(fn ($p) => !$p->isInflow())
            ->sum(fn ($p) => (float) $p->amount), 2);

        // Unallocated deposits — real money the hospital holds for this patient.
        $credit = round($payments
            ->filter(fn ($p) => $p->kind === HealthPayment::KIND_DEPOSIT && !$p->health_bill_id)
            ->sum(fn ($p) => (float) $p->amount), 2);

        $unbilled = HealthChargeService::unbilled($companyId, $patientId);

        return [
            'bills' => $bills,
            'payments' => $payments,
            'unbilled' => $unbilled,
            'unbilled_totals' => HealthChargeService::totals($unbilled),
            'billed' => $billed,
            'collected' => $collected,
            'refunded' => $refunded,
            'credit' => $credit,
            'outstanding' => $outstanding,
            // What the patient would pay to walk out clean right now.
            'due_now' => round(max(0, $outstanding - $credit), 2),
        ];
    }

    /**
     * Everything blocking a discharge settlement.
     *
     * Returned as reasons rather than a bare boolean so the ward is told what to
     * fix, not merely that something is wrong.
     *
     * @return array<int,array{key:string,value?:mixed}>
     */
    public static function settlementBlockers(int $companyId, int $patientId, ?int $admissionId = null): array
    {
        $blockers = [];

        $unbilled = HealthChargeService::unbilled($companyId, $patientId, $admissionId ? ['admission_id' => $admissionId] : []);
        if ($unbilled->isNotEmpty()) {
            $blockers[] = ['key' => 'unbilled_charges', 'value' => $unbilled->count()];
        }

        $draft = HealthBill::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_patient_id', $patientId)
            ->where('doc_type', HealthBill::TYPE_INVOICE)
            ->where('status', HealthBill::STATUS_DRAFT)
            ->when($admissionId, fn ($q) => $q->where('health_admission_id', $admissionId))
            ->count();
        if ($draft > 0) {
            $blockers[] = ['key' => 'draft_bills', 'value' => $draft];
        }

        $due = HealthBill::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_patient_id', $patientId)
            ->whereIn('status', HealthBill::LIVE_STATUSES)
            ->when($admissionId, fn ($q) => $q->where('health_admission_id', $admissionId))
            ->sum('outstanding_amount');
        if (round((float) $due, 2) > 0) {
            $blockers[] = ['key' => 'outstanding_due', 'value' => round((float) $due, 2)];
        }

        return $blockers;
    }

    /**
     * The discharge settlement: sweep every unbilled charge of a stay onto one
     * final bill.
     *
     * @return array{ok:bool,reason?:string,bill?:HealthBill}
     */
    public static function settleAdmission(int $companyId, int $patientId, int $admissionId, array $opts = [], $actor = null): array
    {
        $charges = HealthChargeService::unbilled($companyId, $patientId, ['admission_id' => $admissionId]);
        if ($charges->isEmpty()) {
            return ['ok' => false, 'reason' => 'no_billable_charges'];
        }

        return self::createBill($companyId, $patientId, $charges->pluck('id')->all(), array_merge($opts, [
            'doc_type' => HealthBill::TYPE_INVOICE,
            'scope' => HealthBill::SCOPE_FINAL,
            'health_admission_id' => $admissionId,
        ]), $actor);
    }

    /** The caller's currently open shift, if they have one. */
    public static function openShiftFor(int $companyId, $actor = null): ?HealthCashierShift
    {
        if (!$actor || !Schema::hasTable('health_cashier_shifts')) {
            return null;
        }

        return HealthCashierShift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $actor->id)
            ->where('status', HealthCashierShift::STATUS_OPEN)
            ->orderByDesc('id')
            ->first();
    }

    /** Patient lookup that does not depend on the caller having loaded one. */
    public static function patient(int $companyId, int $patientId): ?HealthPatient
    {
        return HealthPatient::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($patientId);
    }
}
