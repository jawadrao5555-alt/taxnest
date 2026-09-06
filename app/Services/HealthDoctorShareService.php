<?php

namespace App\Services;

use App\Models\HealthAdmission;
use App\Models\HealthBill;
use App\Models\HealthCharge;
use App\Models\HealthDoctorSettlement;
use App\Models\HealthDoctorShare;
use App\Models\HealthDoctorShareRule;
use App\Models\HealthJournal;
use App\Models\HealthOperation;
use App\Models\HealthVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * What the hospital owes its doctors, and when (Task 1552).
 *
 * The whole feature is built around one uncomfortable fact: a doctor's share is
 * the single number in a hospital most likely to be argued about six months
 * later. So every accrual carries, permanently, the rule that produced it — its
 * basis, its rate, the base it bit on and the amount that base was. Change the
 * rule tomorrow and last month's payout still explains itself. Nothing here
 * recalculates history.
 *
 * The second decision is WHEN the money hits the books. Accrual alone does not
 * post: an unreviewed accrual is an estimate, and the moment estimates enter a
 * ledger somebody reports on, the reports stop being true. The expense and the
 * payable land at SETTLEMENT APPROVAL, by somebody who is not the accountant who
 * prepared it.
 */
class HealthDoctorShareService
{
    /** Never accrue more than this in one press. */
    public const ACCRUE_LIMIT = 1000;

    // ═══════════════════════════════════════════════════════════════════
    // RULES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Every rule that could apply, most specific first.
     *
     * Loaded once and filtered in PHP rather than queried per charge: an accrual
     * run walks thousands of charges, and a rule lookup per row is how a month
     * end takes twenty minutes.
     */
    public static function rulesFor(int $companyId)
    {
        return HealthDoctorShareRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->sortByDesc(fn ($rule) => ($rule->specificity() * 1000) + (int) $rule->priority)
            ->values();
    }

    /**
     * The one rule that governs this charge, or NULL when the doctor simply is
     * not on a share arrangement — which is a normal answer, not a failure.
     */
    public static function resolveRule(
        $rules,
        ?int $doctorId,
        ?string $category,
        ?int $departmentId,
        ?int $branchId,
        string $date
    ): ?HealthDoctorShareRule {
        foreach ($rules as $rule) {
            if ($rule->health_doctor_id && (int) $rule->health_doctor_id !== (int) $doctorId) {
                continue;
            }
            if ($rule->charge_category !== HealthDoctorShareRule::CATEGORY_ALL
                && $rule->charge_category !== $category) {
                continue;
            }
            if ($rule->health_department_id && (int) $rule->health_department_id !== (int) $departmentId) {
                continue;
            }
            if ($rule->branch_id && (int) $rule->branch_id !== (int) $branchId) {
                continue;
            }
            if (!$rule->isEffectiveOn($date)) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    /** What the rule pays on this charge. */
    public static function computeShare(HealthDoctorShareRule $rule, HealthCharge $charge): array
    {
        $baseAmount = match ($rule->base) {
            HealthDoctorShareRule::BASE_GROSS => (float) $charge->gross_amount,
            HealthDoctorShareRule::BASE_TOTAL => (float) $charge->total_amount,
            default => (float) $charge->net_amount,
        };
        $baseAmount = round($baseAmount, 2);

        $share = $rule->basis === HealthDoctorShareRule::BASIS_FIXED
            ? round((float) $rule->value * max(1.0, (float) $charge->quantity), 2)
            : round($baseAmount * ((float) $rule->value / 100), 2);

        // A floor and a ceiling are how a hospital promises a consultant a
        // minimum without handing them an open cheque on a big procedure.
        if ($rule->min_amount !== null && $share < (float) $rule->min_amount) {
            $share = round((float) $rule->min_amount, 2);
        }
        if ($rule->max_amount !== null && $share > (float) $rule->max_amount) {
            $share = round((float) $rule->max_amount, 2);
        }

        // Never pay out more than the charge itself brought in.
        if ($share > $baseAmount && $baseAmount > 0) {
            $share = $baseAmount;
        }

        return ['base_amount' => $baseAmount, 'share' => max(0.0, $share)];
    }

    // ═══════════════════════════════════════════════════════════════════
    // WHOSE CHARGE IS IT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The doctor behind a charge.
     *
     * Charges carry no doctor of their own — deliberately, because a charge is a
     * line of money and a doctor is a clinical fact. The link is made through
     * whatever produced the charge: the visit's doctor, the admission's
     * consultant, the operation's primary surgeon.
     *
     * Returning NULL is normal and safe. A consumable issued on a ward has no
     * doctor, and inventing one to make an accrual happen would be paying
     * somebody for work nobody did.
     *
     * @return array{0:?int,1:?string} [doctor id, how it was found]
     */
    public static function doctorForCharge(int $companyId, HealthCharge $charge): array
    {
        $cache = [];

        if ($charge->source_type === HealthCharge::SOURCE_OPERATION && $charge->source_id) {
            $operation = HealthOperation::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->find($charge->source_id);
            if ($operation && $operation->primary_surgeon_id) {
                return [(int) $operation->primary_surgeon_id, 'operation'];
            }
        }

        if ($charge->health_visit_id) {
            $visit = HealthVisit::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->find($charge->health_visit_id);
            if ($visit && $visit->health_doctor_id) {
                return [(int) $visit->health_doctor_id, 'visit'];
            }
        }

        if ($charge->health_admission_id) {
            $admission = HealthAdmission::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->find($charge->health_admission_id);
            if ($admission && $admission->health_doctor_id) {
                return [(int) $admission->health_doctor_id, 'admission'];
            }
        }

        unset($cache);

        return [null, null];
    }

    // ═══════════════════════════════════════════════════════════════════
    // ACCRUAL
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Work out what the doctors earned in a window.
     *
     * Idempotent on `dedupe_key = 'charge:{id}'`, so pressing it twice on the
     * same month cannot pay anybody twice. A charge whose accrual already exists
     * is left completely alone — including one an accountant has excluded by
     * hand, because a re-run must never silently un-exclude a decision somebody
     * made on purpose.
     */
    public static function accrue(int $companyId, ?string $from = null, ?string $to = null, $actor = null): array
    {
        $settings = HealthFiscalPeriodService::settings($companyId);
        if ($settings && !$settings->doctor_shares_enabled) {
            return ['ok' => true, 'created' => 0, 'skipped' => 'disabled'];
        }

        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();
        $collectedBasis = $settings && $settings->doctor_share_basis === 'collected';

        $rules = self::rulesFor($companyId);
        if ($rules->isEmpty()) {
            return ['ok' => true, 'created' => 0, 'skipped' => 'no_rules'];
        }

        $existing = HealthDoctorShare::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('accrual_date', '>=', $from)
            ->whereDate('accrual_date', '<=', $to)
            ->pluck('dedupe_key')
            ->flip();

        $created = 0;
        $noDoctor = 0;
        $noRule = 0;

        $query = HealthCharge::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('reversed_at')
            ->whereNotNull('health_bill_id')
            ->whereDate('charge_date', '>=', $from)
            ->whereDate('charge_date', '<=', $to)
            ->orderBy('id')
            ->limit(self::ACCRUE_LIMIT);

        foreach ($query->get() as $charge) {
            $key = 'charge:' . $charge->id;
            if ($existing->has($key)) {
                continue;
            }

            $bill = HealthBill::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->find($charge->health_bill_id);

            if (!$bill || !in_array($bill->status, HealthBill::LIVE_STATUSES, true)) {
                continue;
            }

            /*
             * On a COLLECTED basis a share is only earned once the money is in.
             * A clinic carrying panel patients for ninety days would otherwise
             * be paying consultants out of cash it has not received — the
             * quickest way for a profitable hospital to run out of money.
             */
            if ($collectedBasis && round((float) $bill->outstanding_amount, 2) > 0.005) {
                continue;
            }

            [$doctorId] = self::doctorForCharge($companyId, $charge);
            if (!$doctorId) {
                $noDoctor++;
                continue;
            }

            $date = $charge->charge_date
                ? $charge->charge_date->toDateString()
                : now()->toDateString();

            $rule = self::resolveRule(
                $rules,
                $doctorId,
                $charge->category,
                $charge->health_department_id,
                $charge->branch_id,
                $date
            );

            if (!$rule) {
                $noRule++;
                continue;
            }

            $computed = self::computeShare($rule, $charge);
            if ($computed['share'] <= 0) {
                continue;
            }

            try {
                HealthDoctorShare::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'branch_id' => $charge->branch_id,
                    'health_department_id' => $charge->health_department_id,
                    'health_doctor_id' => $doctorId,
                    'health_charge_id' => $charge->id,
                    'health_bill_id' => $charge->health_bill_id,
                    'health_patient_id' => $charge->health_patient_id,
                    'health_doctor_share_rule_id' => $rule->id,
                    'accrual_date' => $date,
                    'charge_category' => $charge->category,
                    'description' => $charge->description,
                    // Frozen. The rule may change tomorrow; this row may not.
                    'basis' => $rule->basis,
                    'rate' => $rule->value,
                    'base' => $rule->base,
                    'base_amount' => $computed['base_amount'],
                    'share_amount' => $computed['share'],
                    'status' => HealthDoctorShare::STATUS_ACCRUED,
                    'dedupe_key' => $key,
                ]);
                $created++;
            } catch (\Illuminate\Database\QueryException $e) {
                // The unique index did its job under a concurrent run.
                if (!self::isDuplicate($e)) {
                    throw $e;
                }
            }
        }

        return [
            'ok' => true,
            'created' => $created,
            'no_doctor' => $noDoctor,
            'no_rule' => $noRule,
        ];
    }

    protected static function isDuplicate(\Illuminate\Database\QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $message = strtolower($e->getMessage());

        return $code === '1062'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }

    /**
     * Take one accrual out of the payout, with a reason.
     *
     * The row stays. A share that quietly disappears is a share the doctor will
     * ask about and nobody will be able to explain.
     */
    public static function exclude(HealthDoctorShare $share, string $reason, $actor = null): array
    {
        if ($share->health_doctor_settlement_id) {
            throw ValidationException::withMessages(['share' => [__('health.dsh_on_settlement')]]);
        }
        if ($share->status !== HealthDoctorShare::STATUS_ACCRUED) {
            throw ValidationException::withMessages(['share' => [__('health.dsh_not_open')]]);
        }

        $share->forceFill([
            'status' => HealthDoctorShare::STATUS_EXCLUDED,
            'exclusion_reason' => $reason,
            'excluded_by' => $actor?->id,
            'excluded_at' => now(),
        ])->save();

        return ['ok' => true];
    }

    /** Put an excluded accrual back in play. */
    public static function restore(HealthDoctorShare $share, $actor = null): array
    {
        if ($share->status !== HealthDoctorShare::STATUS_EXCLUDED) {
            throw ValidationException::withMessages(['share' => [__('health.dsh_not_excluded')]]);
        }

        $share->forceFill([
            'status' => HealthDoctorShare::STATUS_ACCRUED,
            'exclusion_reason' => null,
            'excluded_by' => null,
            'excluded_at' => null,
        ])->save();

        return ['ok' => true];
    }

    // ═══════════════════════════════════════════════════════════════════
    // SETTLEMENT
    // ═══════════════════════════════════════════════════════════════════

    /** Everything this doctor is owed but has not been put on a payout yet. */
    public static function openShares(int $companyId, int $doctorId, ?string $from, ?string $to)
    {
        return HealthDoctorShare::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_doctor_id', $doctorId)
            ->open()
            ->when($from, fn ($q) => $q->whereDate('accrual_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('accrual_date', '<=', $to))
            ->orderBy('accrual_date')
            ->get();
    }

    /**
     * Gather a doctor's open accruals into a draft payout.
     *
     * Claiming the lines onto the settlement inside the transaction is what stops
     * two accountants building two payouts for the same month and paying the
     * doctor twice.
     */
    /**
     * @param int|null   $branchId  branch the payout is FILED under
     * @param array|null $branchIds branch boundary of the person building it;
     *                              NULL means the whole organisation
     */
    public static function buildSettlement(
        int $companyId,
        int $doctorId,
        string $from,
        string $to,
        $actor = null,
        ?int $branchId = null,
        ?array $branchIds = null
    ): HealthDoctorSettlement {
        return DB::transaction(function () use ($companyId, $doctorId, $from, $to, $actor, $branchId, $branchIds) {
            $shares = HealthDoctorShare::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('health_doctor_id', $doctorId)
                ->open()
                ->whereDate('accrual_date', '>=', $from)
                ->whereDate('accrual_date', '<=', $to)
                /*
                 * The boundary belongs on the SHARES, not just on the header.
                 * Stamping a payout "branch A" while it sweeps up branch B's
                 * open accruals pays a doctor out of a branch the builder may
                 * not even look at, and labels the payment as if it came from
                 * their own. A share with no branch is organisation-wide and
                 * stays claimable, the same rule every other branch scope uses.
                 */
                ->when(is_array($branchIds), fn ($q) => $q->where(function ($w) use ($branchIds) {
                    if ($branchIds) {
                        $w->whereIn('branch_id', $branchIds);
                    }
                    $w->orWhereNull('branch_id');
                }))
                // And when the payout is filed under one branch, it may only
                // contain that branch's work.
                ->when($branchId, fn ($q) => $q->where(function ($w) use ($branchId) {
                    $w->where('branch_id', $branchId)->orWhereNull('branch_id');
                }))
                ->lockForUpdate()
                ->get();

            if ($shares->isEmpty()) {
                throw ValidationException::withMessages(['shares' => [__('health.dset_nothing_open')]]);
            }

            $gross = round((float) $shares->sum('share_amount'), 2);

            $settlement = HealthDoctorSettlement::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'health_doctor_id' => $doctorId,
                'settlement_no' => HealthNumberService::settlementNumber($companyId),
                'period_from' => $from,
                'period_to' => $to,
                'share_count' => $shares->count(),
                'gross_amount' => $gross,
                'deduction_amount' => 0,
                'net_amount' => $gross,
                'paid_amount' => 0,
                'status' => HealthDoctorSettlement::STATUS_DRAFT,
                'created_by' => $actor?->id,
            ]);

            HealthDoctorShare::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('id', $shares->pluck('id'))
                ->update(['health_doctor_settlement_id' => $settlement->id]);

            return $settlement;
        });
    }

    /** Recompute a draft's totals from the lines actually on it. */
    public static function recompute(HealthDoctorSettlement $settlement): HealthDoctorSettlement
    {
        $rows = HealthDoctorShare::withoutGlobalScopes()
            ->where('company_id', $settlement->company_id)
            ->where('health_doctor_settlement_id', $settlement->id)
            ->where('status', '!=', HealthDoctorShare::STATUS_EXCLUDED)
            ->get();

        $gross = round((float) $rows->sum('share_amount'), 2);
        $deduction = round((float) $settlement->deduction_amount, 2);

        $settlement->forceFill([
            'share_count' => $rows->count(),
            'gross_amount' => $gross,
            'net_amount' => round($gross - $deduction, 2),
        ])->save();

        return $settlement;
    }

    /** Drop a line off a draft payout and release it back to the open pool. */
    public static function detach(HealthDoctorSettlement $settlement, HealthDoctorShare $share): void
    {
        if (!$settlement->isDraft()) {
            throw ValidationException::withMessages(['settlement' => [__('health.dset_locked')]]);
        }

        $share->forceFill([
            'health_doctor_settlement_id' => null,
            'status' => HealthDoctorShare::STATUS_ACCRUED,
        ])->save();

        self::recompute($settlement);
    }

    /**
     * Sign the payout off — and only NOW does it reach the books.
     *
     *   Dr Doctor Share Expense (GROSS)
     *       Cr Doctor Share Payable  (net — what the doctor is still owed)
     *       Cr Doctor Advances       (the deduction — an advance now recovered)
     *
     * The expense is what the doctor EARNED, not what is left after the
     * hospital keeps back an advance it already handed over. Posting the net
     * would understate compensation cost, understate the liability, and quietly
     * delete the advance from the books instead of clearing it — the hospital
     * would have paid that money twice and be able to prove neither.
     *
     * The money has not moved yet: booking it at accrual instead would put an
     * unreviewed estimate into a ledger the owner reads as fact.
     */
    public static function approve(HealthDoctorSettlement $settlement, $actor = null): array
    {
        if (!$settlement->isDraft()) {
            throw ValidationException::withMessages(['settlement' => [__('health.dset_not_draft')]]);
        }

        $gross = round((float) $settlement->gross_amount, 2);
        $deduction = round((float) $settlement->deduction_amount, 2);
        $net = round($gross - $deduction, 2);

        if ($gross <= 0) {
            throw ValidationException::withMessages(['settlement' => [__('health.dset_zero')]]);
        }
        if ($net < 0) {
            throw ValidationException::withMessages(['settlement' => [__('health.dset_deduction_too_big')]]);
        }

        $companyId = (int) $settlement->company_id;

        $lines = [
            [
                'account' => HealthChartOfAccountsService::EXPENSE_DOCTOR_SHARE,
                'debit' => $gross,
                'health_doctor_id' => $settlement->health_doctor_id,
            ],
        ];

        if ($net > 0) {
            $lines[] = [
                'account' => HealthChartOfAccountsService::DOCTOR_SHARE_PAYABLE,
                'credit' => $net,
                'health_doctor_id' => $settlement->health_doctor_id,
            ];
        }

        if ($deduction > 0) {
            $lines[] = [
                'account' => HealthChartOfAccountsService::DOCTOR_ADVANCE,
                'credit' => $deduction,
                'health_doctor_id' => $settlement->health_doctor_id,
                'memo' => $settlement->deduction_reason
                    ? mb_substr((string) $settlement->deduction_reason, 0, 190)
                    : __('health.dset_deduction'),
            ];
        }

        $posted = HealthLedgerService::post($companyId, [
            'date' => $settlement->period_to,
            'branch_id' => $settlement->branch_id,
            'lines' => $lines,
            'memo' => __('health.jrn_memo_doctor_accrual', ['no' => $settlement->settlement_no]),
            'source_type' => HealthJournal::SRC_DOCTOR_SETTLEMENT,
            'source_id' => $settlement->id,
            'source_reference' => $settlement->settlement_no,
            'dedupe_key' => 'dset:' . $settlement->id,
        ], $actor);

        if (!($posted['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'settlement' => [__('health.acc_post_failed', ['reason' => $posted['reason'] ?? '—'])],
            ]);
        }

        DB::transaction(function () use ($settlement, $actor, $companyId) {
            $settlement->forceFill([
                'status' => HealthDoctorSettlement::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $actor?->id,
            ])->save();

            HealthDoctorShare::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('health_doctor_settlement_id', $settlement->id)
                ->where('status', HealthDoctorShare::STATUS_ACCRUED)
                ->update(['status' => HealthDoctorShare::STATUS_APPROVED]);
        });

        return ['ok' => true, 'settlement' => $settlement->fresh()];
    }

    /**
     * Pay it.
     *
     *   Dr Doctor Share Payable    Cr Cash / Bank
     */
    public static function pay(
        HealthDoctorSettlement $settlement,
        string $method,
        ?int $accountId,
        ?string $reference,
        $actor = null
    ): array {
        if (!$settlement->isApproved()) {
            throw ValidationException::withMessages(['settlement' => [__('health.dset_not_approved')]]);
        }

        $companyId = (int) $settlement->company_id;
        $net = round((float) $settlement->net_amount, 2);

        $creditAccountId = $accountId ?: HealthChartOfAccountsService::id(
            $companyId,
            $method === 'bank' ? HealthChartOfAccountsService::BANK : HealthChartOfAccountsService::CASH
        );

        if (!$creditAccountId) {
            throw ValidationException::withMessages(['settlement' => [__('health.acc_no_pay_account')]]);
        }

        /*
         * A payout entirely swallowed by its deduction moves no money: approval
         * already cleared the whole earning against the doctor's advance, so
         * there is no payable left to settle and nothing to take out of the
         * drawer. It is still closed and stamped, because the doctor's accruals
         * have been dealt with.
         */
        $posted = $net <= 0 ? ['ok' => true, 'skipped' => 'nothing_to_pay'] : HealthLedgerService::post($companyId, [
            'date' => now()->toDateString(),
            'branch_id' => $settlement->branch_id,
            'lines' => [
                [
                    'account' => HealthChartOfAccountsService::DOCTOR_SHARE_PAYABLE,
                    'debit' => $net,
                    'health_doctor_id' => $settlement->health_doctor_id,
                ],
                [
                    'account_id' => $creditAccountId,
                    'credit' => $net,
                    'health_doctor_id' => $settlement->health_doctor_id,
                ],
            ],
            'memo' => __('health.jrn_memo_doctor_payment', ['no' => $settlement->settlement_no]),
            'source_type' => HealthJournal::SRC_DOCTOR_SETTLEMENT,
            'source_id' => $settlement->id,
            'source_reference' => $settlement->settlement_no,
            'dedupe_key' => 'dsetpay:' . $settlement->id,
        ], $actor);

        if (!($posted['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'settlement' => [__('health.acc_post_failed', ['reason' => $posted['reason'] ?? '—'])],
            ]);
        }

        DB::transaction(function () use ($settlement, $actor, $companyId, $method, $creditAccountId, $reference, $net) {
            $settlement->forceFill([
                'status' => HealthDoctorSettlement::STATUS_PAID,
                'paid_amount' => $net,
                'paid_at' => now(),
                'paid_by' => $actor?->id,
                'pay_method' => $method,
                'paid_from_account_id' => $creditAccountId,
                'pay_reference' => $reference,
            ])->save();

            HealthDoctorShare::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('health_doctor_settlement_id', $settlement->id)
                ->where('status', HealthDoctorShare::STATUS_APPROVED)
                ->update(['status' => HealthDoctorShare::STATUS_SETTLED]);
        });

        return ['ok' => true, 'settlement' => $settlement->fresh()];
    }

    /**
     * Undo a payout.
     *
     * Both journals are reversed rather than deleted, and the lines go back to
     * being open accruals rather than vanishing: the doctor still did the work,
     * so the earning still exists and belongs on a corrected payout.
     */
    public static function reverse(HealthDoctorSettlement $settlement, string $reason, $actor = null): array
    {
        if ($settlement->status === HealthDoctorSettlement::STATUS_REVERSED) {
            throw ValidationException::withMessages(['settlement' => [__('health.dset_already_reversed')]]);
        }

        $companyId = (int) $settlement->company_id;

        /*
         * The ledger comes undone first, inside the same transaction as the
         * operational undo, and a refused reversal stops everything.
         *
         * Splitting them lets the worst case through: the payment journal
         * refuses (a closed period, no open period to adjust into), the shares
         * are handed back to the open pool anyway, and the hospital now has the
         * expense and the payout both still posted AND the same shares queued to
         * be paid a second time.
         */
        DB::transaction(function () use ($settlement, $actor, $companyId, $reason) {
            foreach (['dsetpay:' . $settlement->id, 'dset:' . $settlement->id] as $key) {
                $result = HealthLedgerService::reverseByDedupe($companyId, $key, $actor, $reason);

                if (!($result['ok'] ?? false)) {
                    throw ValidationException::withMessages([
                        'settlement' => [__('health.dset_reverse_failed', [
                            'reason' => (string) ($result['reason'] ?? ''),
                        ])],
                    ]);
                }
            }

            HealthDoctorShare::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('health_doctor_settlement_id', $settlement->id)
                ->update([
                    'health_doctor_settlement_id' => null,
                    'status' => HealthDoctorShare::STATUS_ACCRUED,
                ]);

            $settlement->forceFill([
                'status' => HealthDoctorSettlement::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $actor?->id,
                'reversal_reason' => $reason,
            ])->save();
        });

        return ['ok' => true];
    }

    // ═══════════════════════════════════════════════════════════════════
    // STATEMENTS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * What one doctor earned, and what has been done with it.
     *
     * Deliberately money-only. A doctor's own earnings screen shows amounts,
     * dates and categories — never another clinician's notes, and never a
     * diagnosis, because an earnings statement is a finance document that
     * happens to be shown to a clinician.
     */
    public static function statement(
        int $companyId,
        int $doctorId,
        string $from,
        string $to,
        ?array $branchIds = null
    ): array {
        $shares = HealthDoctorShare::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_doctor_id', $doctorId)
            ->whereDate('accrual_date', '>=', $from)
            ->whereDate('accrual_date', '<=', $to)
            // A finance reader confined to one branch passes their boundary; a
            // doctor reading their OWN earnings passes null, because their money
            // is theirs wherever in the organisation they earned it.
            ->when(is_array($branchIds), fn ($q) => $q->where(function ($w) use ($branchIds) {
                if ($branchIds) {
                    $w->whereIn('branch_id', $branchIds);
                }
                $w->orWhereNull('branch_id');
            }))
            ->orderBy('accrual_date')
            ->get();

        $byStatus = [];
        foreach (HealthDoctorShare::STATUSES as $status) {
            $byStatus[$status] = 0.0;
        }

        $byCategory = [];
        foreach ($shares as $share) {
            $amount = round((float) $share->share_amount, 2);
            $byStatus[$share->status] = round(($byStatus[$share->status] ?? 0) + $amount, 2);
            if ($share->status !== HealthDoctorShare::STATUS_EXCLUDED
                && $share->status !== HealthDoctorShare::STATUS_REVERSED) {
                $key = $share->charge_category ?: 'misc';
                $byCategory[$key] = round(($byCategory[$key] ?? 0) + $amount, 2);
            }
        }

        $earned = round(array_sum(array_values($byCategory)), 2);
        $paid = $byStatus[HealthDoctorShare::STATUS_SETTLED] ?? 0.0;

        return [
            'shares' => $shares,
            'by_status' => $byStatus,
            'by_category' => $byCategory,
            'earned' => $earned,
            'paid' => $paid,
            'outstanding' => round($earned - $paid, 2),
            'settlements' => Schema::hasTable('health_doctor_settlements')
                ? HealthDoctorSettlement::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('health_doctor_id', $doctorId)
                    ->orderByDesc('period_to')
                    ->limit(24)
                    ->get()
                : collect(),
        ];
    }

    /** One row per doctor for the payout screen. */
    public static function summary(
        int $companyId,
        string $from,
        string $to,
        ?int $branchId = null,
        ?array $branchIds = null,
        ?array $departmentIds = null
    ): array {
        if (!Schema::hasTable('health_doctor_shares')) {
            return [];
        }

        return DB::table('health_doctor_shares')
            ->where('company_id', $companyId)
            ->whereDate('accrual_date', '>=', $from)
            ->whereDate('accrual_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(is_array($branchIds), fn ($q) => $q->where(function ($w) use ($branchIds) {
                if ($branchIds) {
                    $w->whereIn('branch_id', $branchIds);
                }
                $w->orWhereNull('branch_id');
            }))
            // Totals a confined reader is shown must count the same accruals
            // their list shows. A tile that quietly sums the whole hospital is
            // the leak nobody notices, because it looks like a number.
            ->when(is_array($departmentIds), fn ($q) => $q->where(function ($w) use ($departmentIds) {
                if ($departmentIds) {
                    $w->whereIn('health_department_id', $departmentIds);
                }
                $w->orWhereNull('health_department_id');
            }))
            ->selectRaw('health_doctor_id')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw("SUM(CASE WHEN status = 'accrued' THEN share_amount ELSE 0 END) as open_amount")
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN share_amount ELSE 0 END) as approved_amount")
            ->selectRaw("SUM(CASE WHEN status = 'settled' THEN share_amount ELSE 0 END) as settled_amount")
            ->selectRaw("SUM(CASE WHEN status = 'excluded' THEN share_amount ELSE 0 END) as excluded_amount")
            ->groupBy('health_doctor_id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
